"""آزمون‌های رابط کاربری فاز ۱: ورود، ثبت، دفترچه، کارت نامه، اقدام‌ها، کارتابل، اعلان‌ها."""
from datetime import date, timedelta

import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.core.models import Notification
from apps.organization.models import Department
from apps.secretariat.models import Letter, LetterEvent, Office, Referral
from apps.secretariat.services import register_letter
from apps.secretariat.tests import give, make_office

User = get_user_model()


def jalali_from_gregorian(g):
    return jdatetime.date.fromgregorian(date=g)


class AuthFlowTests(TestCase):
    def test_home_redirects_anonymous_to_login(self):
        response = self.client.get('/')
        self.assertRedirects(response, reverse('accounts:login'))

    def test_login_redirects_to_kartable(self):
        User.objects.create_user('staff', password='pass1234')
        ok = self.client.login(username='staff', password='pass1234')
        self.assertTrue(ok)
        response = self.client.get('/', follow=False)
        self.assertRedirects(response, reverse('secretariat:kartable'))


class RegisterViewTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.dabir = give(User.objects.create_user('dabir', password='x'), 'manage_secretariat', 'view_all_letter')
        self.normal = User.objects.create_user('normal', password='x')

    def test_incoming_form_only_for_permitted(self):
        self.client.force_login(self.dabir)
        self.assertTrue(self.client.get(reverse('secretariat:register')).context['can_incoming'])
        self.client.force_login(self.normal)
        self.assertFalse(self.client.get(reverse('secretariat:register')).context['can_incoming'])

    def test_post_incoming_creates_letter(self):
        self.client.force_login(self.dabir)
        response = self.client.post(reverse('secretariat:register'), {
            'form': 'incoming', 'in-office': self.office.pk,
            'in-subject': 'درخواست خرید تجهیزات', 'in-summary': 'خلاصه',
            'in-sender_name': 'علی رضایی', 'in-sender_org': 'شرکت پارس',
            'in-letter_date': '۱۴۰۵/۰۷/۲۰', 'in-priority': 'urgent',
            'in-confidentiality': 'normal', 'in-deadline': '1405/07/30',
        })
        letter = Letter.objects.get()
        self.assertRedirects(response, reverse('secretariat:letter_detail', args=[letter.pk]))
        self.assertEqual(letter.serial, 1)
        self.assertEqual(letter.letter_type, 'incoming')
        self.assertEqual(letter.priority, 'urgent')
        self.assertIsNotNone(letter.received_at)
        self.assertEqual(letter.events.first().action, LetterEvent.Action.REGISTERED)

    def test_post_outgoing_creates_draft(self):
        self.client.force_login(self.normal)
        self.client.post(reverse('secretariat:register'), {
            'form': 'outgoing', 'out-office': self.office.pk,
            'out-subject': 'پاسخ به استعلام', 'out-body': 'متن نامه',
            'out-letter_date': '', 'out-priority': 'normal', 'out-confidentiality': 'internal',
        })
        letter = Letter.objects.get()
        self.assertEqual(letter.status, Letter.Status.DRAFT)
        self.assertEqual(letter.letter_type, 'outgoing')

    def test_normal_user_cannot_register_incoming(self):
        self.client.force_login(self.normal)
        response = self.client.post(reverse('secretariat:register'), {
            'form': 'incoming', 'in-office': self.office.pk, 'in-subject': 'تلاش بدون مجوز',
        }, follow=True)
        self.assertEqual(Letter.objects.count(), 0)
        self.assertContains(response, 'مجوز ثبت نامه وارده ندارید')


class LetterListViewTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.viewer = give(User.objects.create_user('viewer', password='x'), 'view_all_letter')
        self.stranger = User.objects.create_user('stranger', password='x')
        self.plain = register_letter(self.office, 'incoming', 'نامه عادی')
        self.secret = register_letter(
            self.office, 'incoming', 'نامه محرمانه', confidentiality='confidential')

    def test_stranger_sees_nothing(self):
        self.client.force_login(self.stranger)
        response = self.client.get(reverse('secretariat:letter_list'))
        self.assertEqual(response.context['page_obj'].paginator.count, 0)

    def test_viewer_sees_non_confidential(self):
        self.client.force_login(self.viewer)
        response = self.client.get(reverse('secretariat:letter_list'))
        subjects = [letter.subject for letter in response.context['letters']]
        self.assertIn('نامه عادی', subjects)
        self.assertNotIn('نامه محرمانه', subjects)

    def test_type_filter_and_search(self):
        register_letter(self.office, 'outgoing', 'صادره نمونه')
        self.client.force_login(self.viewer)
        response = self.client.get(reverse('secretariat:letter_list'), {'type': 'outgoing'})
        self.assertEqual([l.subject for l in response.context['letters']], ['صادره نمونه'])
        response = self.client.get(reverse('secretariat:letter_list'), {'q': 'عادی'})
        self.assertEqual([l.subject for l in response.context['letters']], ['نامه عادی'])

    def test_xlsx_export(self):
        self.client.force_login(self.viewer)
        response = self.client.get(reverse('secretariat:letter_list'), {'export': 'xlsx'})
        self.assertEqual(
            response['Content-Type'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        self.assertTrue(response.content[:2] == b'PK')  # ZIP/xlsx


class LetterDetailActionTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.creator = give(User.objects.create_user('creator', password='x'),
                            'view_all_letter', 'manage_secretariat')
        self.expert = give(User.objects.create_user('expert', password='x'),
                           'view_all_letter', 'refer_letter', 'answer_letter')
        self.letter = register_letter(
            self.office, 'incoming', 'گزارش خرابی سرور', created_by=self.creator)

    def test_detail_renders_sheet(self):
        self.client.force_login(self.creator)
        response = self.client.get(reverse('secretariat:letter_detail', args=[self.letter.pk]))
        self.assertContains(response, 'گزارش خرابی سرور')
        self.assertContains(response, self.letter.number_display)
        self.assertContains(response, 'بسمه تعالی')

    def test_stranger_gets_404(self):
        self.client.force_login(User.objects.create_user('out', password='x'))
        response = self.client.get(reverse('secretariat:letter_detail', args=[self.letter.pk]))
        self.assertEqual(response.status_code, 404)

    def test_refer_creates_referral_and_notification(self):
        self.client.force_login(self.expert)
        response = self.client.post(
            reverse('secretariat:letter_refer', args=[self.letter.pk]),
            {'to_user': self.creator.pk, 'to_department': '', 'note': 'بررسی فنی',
             'deadline': '۱۴۰۵/۰۷/۲۸'}, follow=True)
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.REFERRED)
        referral = self.letter.referrals.first()
        self.assertEqual(referral.to_user, self.creator)
        self.assertIsNotNone(referral.deadline)
        notification = Notification.objects.get(user=self.creator)
        self.assertIn('ارجاع نامه', notification.title)
        self.assertContains(response, 'ارجاع ثبت شد')

    def test_answer_sets_status_and_acts_referral(self):
        Referral.objects.create(letter=self.letter, from_user=self.creator, to_user=self.expert)
        self.client.force_login(self.expert)
        self.client.post(reverse('secretariat:letter_answer', args=[self.letter.pk]),
                         {'note': 'سرور جایگزین شد و مشکل برطرف گردید.'})
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.ANSWERED)
        self.assertEqual(self.letter.referrals.first().status, Referral.Status.ACTED)
        self.assertTrue(Notification.objects.filter(user=self.creator).exists())

    def test_close_requires_permission(self):
        self.client.force_login(self.expert)
        self.client.post(reverse('secretariat:letter_close', args=[self.letter.pk]), follow=True)
        self.letter.refresh_from_db()
        self.assertNotEqual(self.letter.status, Letter.Status.ARCHIVED)

        boss = give(User.objects.create_user('boss', password='x'), 'view_all_letter', 'close_letter')
        self.client.force_login(boss)
        self.client.post(reverse('secretariat:letter_close', args=[self.letter.pk]), follow=True)
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.ARCHIVED)


class KartableTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.dabir = give(User.objects.create_user('dabir', password='x'),
                          'view_all_letter', 'manage_secretariat', 'refer_letter')
        self.expert = give(User.objects.create_user('expert', password='x'),
                           'view_own_letter', 'answer_letter')
        self.waiting_letter = register_letter(
            self.office, 'incoming', 'در انتظار اقدام', created_by=self.dabir)
        Referral.objects.create(
            letter=self.waiting_letter, from_user=self.dabir, to_user=self.expert,
            deadline=jalali_from_gregorian(date.today() - timedelta(days=5)))
        self.sent_letter = register_letter(
            self.office, 'outgoing', 'ارسالی من', created_by=self.expert)

    def test_waiting_tab_and_overdue_kpi(self):
        self.client.force_login(self.expert)
        response = self.client.get(reverse('secretariat:kartable'))
        self.assertIn(self.waiting_letter, list(response.context['waiting']))
        self.assertEqual(response.context['kpi']['waiting'], 1)
        self.assertEqual(response.context['kpi']['overdue'], 1)

    def test_sent_tab(self):
        self.client.force_login(self.expert)
        response = self.client.get(reverse('secretariat:kartable'), {'tab': 'sent'})
        self.assertIn(self.sent_letter, list(response.context['sent']))

    def test_follow_tab_for_referrer(self):
        self.client.force_login(self.dabir)
        response = self.client.get(reverse('secretariat:kartable'), {'tab': 'follow'})
        self.assertEqual(len(list(response.context['followups'])), 1)


class NotificationViewTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.n1 = Notification.objects.create(user=self.user, title='ارجاع جدید', url='/kartable/')
        Notification.objects.create(user=self.user, title='نزدیک مهلت')

    def test_list_and_mark_read(self):
        self.client.force_login(self.user)
        response = self.client.get(reverse('accounts:notifications'))
        self.assertContains(response, 'ارجاع جدید')
        self.assertEqual(response.context['unread'], 2)
        self.client.post(reverse('accounts:notification_read', args=[self.n1.pk]))
        self.n1.refresh_from_db()
        self.assertTrue(self.n1.is_read)

    def test_mark_all_read(self):
        self.client.force_login(self.user)
        self.client.post(reverse('accounts:notifications_read_all'))
        self.assertEqual(Notification.objects.filter(user=self.user, is_read=False).count(), 0)
