"""آزمون دستور داده نمایشی — باید idempotent و بی‌خطا اجرا شود."""
from django.contrib.auth import get_user_model
from django.core.management import call_command
from django.test import TestCase

from apps.secretariat.models import Letter, Office, Referral

User = get_user_model()


class DemoDataTests(TestCase):
    def test_setup_creates_full_demo(self):
        call_command('setup_demo_data')
        self.assertTrue(Office.objects.filter(code='HQ').exists())
        self.assertGreaterEqual(Letter.objects.count(), 11)
        self.assertTrue(User.objects.filter(username='dabir').exists())
        self.assertTrue(Referral.objects.filter(status='pending').exists())
        self.assertTrue(Letter.objects.filter(status='sent', letter_type='outgoing').exists())

    def test_second_run_is_noop_without_force(self):
        call_command('setup_demo_data')
        before = Letter.objects.count()
        call_command('setup_demo_data')
        self.assertEqual(Letter.objects.count(), before)

    def test_demo_user_can_login_and_see_kartable(self):
        call_command('setup_demo_data')
        self.client.login(username='karshenas', password='karshenas1234')
        response = self.client.get('/kartable/')
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'کارتابل من')
