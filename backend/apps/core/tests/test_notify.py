"""آزمون‌های سرویس اعلان چندکاناله (درون‌سامانه‌ای/ایمیل/پیامک)."""
from unittest import mock

from django.contrib.auth import get_user_model
from django.core import mail
from django.test import TestCase, override_settings

from apps.core.models import Notification
from apps.core.services import send_notification
from apps.core.sms import reset_backend, send_sms

User = get_user_model()


class InAppNotificationTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user(
            username='notify', password='pass1234!', first_name='ندا', last_name='پیام')

    def test_creates_notification(self):
        n = send_notification(self.user, 'عنوان', 'متن', '/letters/1/')
        self.assertIsNotNone(n.pk)
        self.assertTrue(Notification.objects.filter(user=self.user, title='عنوان').exists())

    def test_truncates_long_fields(self):
        send_notification(self.user, 'ع' * 400, 'ب' * 2000, '/x' * 300)
        n = Notification.objects.get(user=self.user)
        self.assertLessEqual(len(n.title), 200)
        self.assertLessEqual(len(n.body), 1000)
        self.assertLessEqual(len(n.url), 300)


@override_settings(EMAIL_BACKEND='django.core.mail.backends.locmem.EmailBackend',
                   DEFAULT_FROM_EMAIL='noreply@test.local',
                   NOTIFICATIONS_EMAIL_ENABLED=True)
class EmailNotificationTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user(
            username='mail', password='pass1234!', email='mail@test.local')
        self.no_email = User.objects.create_user(username='nomail', password='pass1234!')

    def test_email_sent_with_html(self):
        send_notification(self.user, 'جلسه فردا', 'متن جلسه', 'https://x.test/meetings/1/')
        self.assertEqual(len(mail.outbox), 1)
        msg = mail.outbox[0]
        self.assertEqual(msg.subject, 'جلسه فردا')
        self.assertEqual(msg.to, ['mail@test.local'])
        self.assertEqual(len(msg.alternatives), 1)
        self.assertIn('text/html', msg.alternatives[0][1])
        self.assertIn('جلسه فردا', msg.alternatives[0][0])

    def test_no_email_without_address(self):
        send_notification(self.no_email, 'ع', 'ب')
        self.assertEqual(len(mail.outbox), 0)
        # اعلان درون‌سامانه‌ای همچنان ثبت می‌شود
        self.assertTrue(Notification.objects.filter(user=self.no_email).exists())

    @override_settings(NOTIFICATIONS_EMAIL_ENABLED=False)
    def test_email_disabled_by_setting(self):
        send_notification(self.user, 'ع', 'ب')
        self.assertEqual(len(mail.outbox), 0)

    def test_email_failure_does_not_break(self):
        with mock.patch('apps.core.services.EmailMultiAlternatives.send',
                        side_effect=OSError('smtp down')):
            n = send_notification(self.user, 'ع', 'ب')
        self.assertIsNotNone(n.pk)


@override_settings(SMS_ENABLED=True, SMS_BACKEND='apps.core.sms.ConsoleSMSBackend')
class SmsNotificationTests(TestCase):
    def setUp(self):
        reset_backend()
        from apps.organization.models import Department, Employee
        self.user = User.objects.create_user(username='sms', password='pass1234!')
        dept = Department.objects.create(title='فناوری', code='IT')
        Employee.objects.create(first_name='اس', last_name='ام', personnel_code='E-1',
                                department=dept, user=self.user, phone='09120000000')
        self.plain = User.objects.create_user(username='plain', password='pass1234!')

    def tearDown(self):
        reset_backend()

    def test_sms_sent_to_employee_phone(self):
        with mock.patch('apps.core.sms.ConsoleSMSBackend.send', return_value=True) as m:
            send_notification(self.user, 'وظیفه تازه', 'شرح')
        m.assert_called_once()
        self.assertEqual(m.call_args[0][0], '09120000000')
        self.assertIn('وظیفه تازه', m.call_args[0][1])

    def test_no_sms_without_phone(self):
        with mock.patch('apps.core.sms.ConsoleSMSBackend.send') as m:
            send_notification(self.plain, 'ع', 'ب')
        m.assert_not_called()

    @override_settings(SMS_ENABLED=False)
    def test_sms_disabled(self):
        with mock.patch('apps.core.sms.ConsoleSMSBackend.send') as m:
            send_notification(self.user, 'ع', 'ب')
        m.assert_not_called()
        self.assertFalse(send_sms('09120000000', 'متن'))

    def test_send_sms_skips_empty_number(self):
        self.assertFalse(send_sms('', 'متن'))

    def test_sms_failure_does_not_break_notification(self):
        with mock.patch('apps.core.sms.ConsoleSMSBackend.send', side_effect=RuntimeError):
            n = send_notification(self.user, 'ع', 'ب')
        self.assertIsNotNone(n.pk)

    def test_rest_backend_without_url_is_false(self):
        from apps.core.sms import RestSMSBackend
        with override_settings(SMS_API_URL=''):
            self.assertFalse(RestSMSBackend().send('09120000000', 'متن'))
