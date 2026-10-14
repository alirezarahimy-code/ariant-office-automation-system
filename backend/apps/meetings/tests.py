"""آزمون جلسات و مصوبات — فاز ۲ اسپرینت ۳."""
import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.core.management import call_command
from django.test import TestCase
from django.urls import reverse

from apps.meetings.models import (AgendaItem, Attendee, Meeting,
                                  MinutesSignature, Resolution)
from apps.meetings.services import (convert_resolution_to_letter,
                                    finalize_minutes, get_or_create_minutes,
                                    sign_minutes)
from apps.secretariat.models import Letter, Office
from apps.secretariat.tests import make_office

User = get_user_model()


def give_perm(user, app, *codenames):
    for code in codenames:
        user.user_permissions.add(
            Permission.objects.get(codename=code, content_type__app_label=app))
    return user


class MeetingFlowTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.organizer = give_perm(
            User.objects.create_user('org', password='x'), 'meetings', 'manage_meetings')
        self.member = User.objects.create_user('member', password='x')
        self.meeting = Meeting.objects.create(
            title='جلسه شورای مدیران', jalali_date=jdatetime.date(1405, 7, 22),
            organizer=self.organizer, location='سالن جلسات')
        Attendee.objects.create(meeting=self.meeting, user=self.organizer,
                                role=Attendee.Role.CHAIR)
        Attendee.objects.create(meeting=self.meeting, user=self.member,
                                role=Attendee.Role.MEMBER)

    def test_minutes_save_sign_finalize(self):
        minutes = get_or_create_minutes(self.meeting)
        minutes.body = 'مقرر شد گزارش عملکرد تا پایان مهر ارائه شود.'
        minutes.save(update_fields=['body'])
        sign_minutes(minutes, self.organizer, position='رئیس جلسه')
        sign_minutes(minutes, self.member, position='عضو')
        self.assertEqual(MinutesSignature.objects.filter(minutes=minutes).count(), 2)
        # امضای تکراری → رکورد تازه نمی‌سازد
        sign_minutes(minutes, self.member, position='عضو')
        self.assertEqual(MinutesSignature.objects.filter(minutes=minutes).count(), 2)
        result = finalize_minutes(minutes, self.organizer)
        self.assertIsNotNone(result)
        self.assertTrue(result.is_final)
        # نهایی‌سازی دوباره → None
        self.assertIsNone(finalize_minutes(minutes, self.organizer))
        # اعلان به دعوت‌شدگان رفته است
        self.assertTrue(self.member.notifications.filter(
            title__contains='نهایی شد').exists())

    def test_finalize_empty_minutes_rejected(self):
        minutes = get_or_create_minutes(self.meeting)
        self.assertIsNone(finalize_minutes(minutes, self.organizer))
        self.assertFalse(minutes.is_final)

    def test_resolution_numbering_and_letter_conversion(self):
        r1 = Resolution.objects.create(meeting=self.meeting, number=1,
                                       title='تصویب بودجه تعمیرات')
        r2 = Resolution.objects.create(meeting=self.meeting, number=2,
                                       title='برگزاری دوره آموزشی')
        self.assertEqual(r2.number, 2)
        letter = convert_resolution_to_letter(r1, self.organizer)
        self.assertIsNotNone(letter)
        self.assertEqual(letter.letter_type, Letter.LetterType.OUTGOING)
        self.assertEqual(letter.status, Letter.Status.DRAFT)
        self.assertIn('مصوبه شماره 1', letter.body)
        r1.refresh_from_db()
        self.assertEqual(r1.status, Resolution.Status.CONVERTED)
        self.assertEqual(r1.converted_letter_id, letter.pk)
        # تبدیل دوباره → None
        self.assertIsNone(convert_resolution_to_letter(r1, self.organizer))

    def test_letter_conversion_without_office(self):
        Office.objects.all().delete()
        resolution = Resolution.objects.create(meeting=self.meeting, number=1, title='مصوبه')
        self.assertIsNone(convert_resolution_to_letter(resolution, self.organizer))


class MeetingViewTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.organizer = give_perm(
            User.objects.create_user('org2', password='x'), 'meetings', 'manage_meetings')
        self.member = User.objects.create_user('mem2', password='x')
        self.outsider = User.objects.create_user('out', password='x')

    def test_new_meeting_post_creates_and_invites(self):
        self.client.force_login(self.organizer)
        response = self.client.post(reverse('meetings:new'), {
            'title': 'جلسه بودجه', 'jalali_date': '1405-08-01',
            'start_time': '10:30', 'location': 'اتاق کنفرانس',
            'description': 'بررسی لایحه بودجه',
            'attendees': [str(self.member.pk)],
        }, follow=True)
        meeting = Meeting.objects.get(title='جلسه بودجه')
        self.assertEqual(meeting.attendees.count(), 1)
        self.assertContains(response, 'جلسه بودجه')
        self.assertTrue(self.member.notifications.filter(
            title__contains='دعوت به جلسه').exists())

    def test_new_meeting_rejects_bad_date(self):
        self.client.force_login(self.organizer)
        self.client.post(reverse('meetings:new'),
                         {'title': 'بدون تاریخ', 'jalali_date': 'not-a-date'})
        self.assertFalse(Meeting.objects.filter(title='بدون تاریخ').exists())

    def test_detail_gated_for_outsider(self):
        meeting = Meeting.objects.create(
            title='جلسه محرمان', jalali_date=jdatetime.date(1405, 7, 20),
            organizer=self.organizer)
        self.client.force_login(self.outsider)
        response = self.client.get(reverse('meetings:detail', args=[meeting.pk]), follow=True)
        self.assertContains(response, 'به این جلسه دسترسی ندارید')

    def test_minutes_edit_gated_for_member(self):
        meeting = Meeting.objects.create(
            title='جلسه عادی', jalali_date=jdatetime.date(1405, 7, 21),
            organizer=self.organizer)
        Attendee.objects.create(meeting=meeting, user=self.member)
        self.client.force_login(self.member)
        response = self.client.post(reverse('meetings:minutes_save', args=[meeting.pk]),
                                    {'body': 'تلاش برای ویرایش'}, follow=True)
        self.assertContains(response, 'فقط برگزارکننده')
        response = self.client.get(reverse('meetings:detail', args=[meeting.pk]))
        self.assertContains(response, 'جلسه عادی')

    def test_full_view_flow_minutes_to_letter(self):
        meeting = Meeting.objects.create(
            title='جلسه کامل', jalali_date=jdatetime.date(1405, 7, 25),
            organizer=self.organizer)
        Attendee.objects.create(meeting=meeting, user=self.member)
        self.client.force_login(self.organizer)
        # دستور جلسه
        self.client.post(reverse('meetings:agenda_add', args=[meeting.pk]),
                         {'title': 'بررسی گزارش فصلی'})
        self.assertEqual(AgendaItem.objects.filter(meeting=meeting).count(), 1)
        # صورت‌جلسه + امضای عضو
        self.client.post(reverse('meetings:minutes_save', args=[meeting.pk]),
                         {'body': 'گزارش فصلی تصویب شد.'})
        self.client.force_login(self.member)
        self.client.post(reverse('meetings:minutes_sign', args=[meeting.pk]))
        self.assertTrue(MinutesSignature.objects.filter(user=self.member).exists())
        # نهایی‌سازی
        self.client.force_login(self.organizer)
        self.client.post(reverse('meetings:minutes_finalize', args=[meeting.pk]),
                         {'body': 'گزارش فصلی تصویب شد.'})
        meeting.refresh_from_db()
        self.assertTrue(meeting.minutes.is_final)
        # مصوبه → نامه صادره
        self.client.post(reverse('meetings:resolution_add', args=[meeting.pk]),
                         {'title': 'ابلاغ گزارش فصلی', 'responsible_id': '',
                          'due_date': '1405-08-15'})
        resolution = Resolution.objects.get(meeting=meeting)
        self.assertEqual(resolution.due_date, jdatetime.date(1405, 8, 15))
        response = self.client.post(
            reverse('meetings:resolution_convert', args=[meeting.pk, resolution.pk]),
            {'target': 'letter'}, follow=True)
        self.assertContains(response, 'پیش‌نویس نامه صادره')
        self.assertIsNotNone(Letter.objects.filter(
            subject='ابلاغ گزارش فصلی', letter_type='outgoing').first())

    def test_list_renders_tabs(self):
        Meeting.objects.create(title='جلسه فهرست',
                               jalali_date=jdatetime.date(1405, 7, 22),
                               organizer=self.organizer)
        self.client.force_login(self.member)
        response = self.client.get(reverse('meetings:list'))
        self.assertNotContains(response, 'جلسه فهرست')  # member نمی‌بیند
        self.client.force_login(self.organizer)
        response = self.client.get(reverse('meetings:list'))
        self.assertContains(response, 'جلسه فهرست')
        response = self.client.get(reverse('meetings:list'), {'status': 'held'})
        self.assertNotContains(response, 'جلسه فهرست')

    def test_groups_seed_has_manage_meetings(self):
        call_command('setup_default_groups')
        from django.contrib.auth.models import Group
        boss = Group.objects.get(name='مدیر ارشد')
        self.assertTrue(boss.permissions.filter(codename='manage_meetings').exists())
