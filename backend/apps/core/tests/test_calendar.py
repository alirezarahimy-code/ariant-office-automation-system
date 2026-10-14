"""آزمون‌های تقویم کاری، پروفایل، تنظیمات و مدیریت کاربران."""
import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.core.calendar_services import (add_workdays, is_holiday, is_workday,
                                         next_workday, workdays_between)
from apps.core.models import Holiday, OrgSettings, UserProfile

User = get_user_model()


class CalendarServiceTests(TestCase):
    def setUp(self):
        # ۱۴۰۴/۰۷/۲۳ پنج‌شنبه است (کاری نیست در هفته کاری پیش‌فرض)
        self.holiday = Holiday.objects.create(
            date=jdatetime.date(1404, 7, 22), title='تعطیل آزمایشی')

    def test_is_holiday(self):
        self.assertTrue(is_holiday(jdatetime.date(1404, 7, 22)))
        self.assertFalse(is_holiday(jdatetime.date(1404, 7, 21)))

    def test_is_workday_skips_holiday(self):
        self.assertFalse(is_workday(jdatetime.date(1404, 7, 22)))  # تعطیل رسمی
        self.assertTrue(is_workday(jdatetime.date(1404, 7, 21)))   # دوشنبه
        self.assertFalse(is_workday(jdatetime.date(1404, 7, 25)))  # پنج‌شنبه

    def test_add_workdays_skips_holiday_and_weekend(self):
        # دوشنبه ۲۱ مهر + ۲ روز کاری → شنبه ۲۶ مهر
        # (سه‌شنبه ۲۲ تعطیل رسمی؛ پنج‌شنبه ۲۴ و جمعه ۲۵ خارج از هفته کاری)
        result = add_workdays(jdatetime.date(1404, 7, 21), 2)
        self.assertEqual(result, jdatetime.date(1404, 7, 26))

    def test_next_workday(self):
        self.assertEqual(next_workday(jdatetime.date(1404, 7, 22)),
                         jdatetime.date(1404, 7, 23))

    def test_workdays_between(self):
        count = workdays_between(jdatetime.date(1404, 7, 20),
                                 jdatetime.date(1404, 7, 26))
        # یکشنبه ۲۰ تا شنبه ۲۶: ۲۰، ۲۱، ۲۳، ۲۶ — سه‌شنبه ۲۲ تعطیل رسمی،
        # پنج‌شنبه ۲۴ و جمعه ۲۵ خارج از هفته کاری پیش‌فرض
        self.assertEqual(count, 4)

    def test_add_workdays_negative_raises(self):
        with self.assertRaises(ValueError):
            add_workdays(jdatetime.date(1404, 7, 21), -1)


class OrgSettingsTests(TestCase):
    def test_singleton_load(self):
        obj = OrgSettings.load()
        obj.org_name = 'سازمان نمونه'
        obj.save()
        self.assertEqual(OrgSettings.load().org_name, 'سازمان نمونه')
        self.assertEqual(OrgSettings.objects.count(), 1)


class ProfileViewTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')

    def test_profile_get(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('accounts:profile'))
        self.assertEqual(resp.status_code, 200)

    def test_profile_post_updates(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('accounts:profile'), {
            'first_name': 'علی', 'last_name': 'رضایی',
            'email': 'ali@example.com', 'phone': '0912'})
        self.assertEqual(resp.status_code, 302)
        self.user.refresh_from_db()
        self.assertEqual(self.user.first_name, 'علی')
        profile = UserProfile.objects.get(user=self.user)
        self.assertEqual(profile.phone, '0912')


class AdminViewsTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_user('boss', password='x', is_superuser=True)
        self.user = User.objects.create_user('u1', password='x')

    def test_settings_requires_admin(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('accounts:settings'))
        self.assertEqual(resp.status_code, 302)

    def test_settings_edit(self):
        self.client.force_login(self.admin)
        resp = self.client.post(reverse('accounts:settings'), {
            'org_name': 'آرینت', 'motto': 'آرام و شفاف', 'fiscal_year': '1404'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(OrgSettings.load().org_name, 'آرینت')

    def test_users_list_and_edit(self):
        self.client.force_login(self.admin)
        resp = self.client.get(reverse('accounts:users'))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.post(
            reverse('accounts:users_edit', args=[self.user.pk]), {
                'first_name': 'نام', 'last_name': 'خانوادگی',
                'email': '', 'is_active': 'on'})
        self.assertEqual(resp.status_code, 302)
        self.user.refresh_from_db()
        self.assertEqual(self.user.first_name, 'نام')

    def test_users_edit_deactivates(self):
        self.client.force_login(self.admin)
        self.client.post(reverse('accounts:users_edit', args=[self.user.pk]), {
            'first_name': '', 'last_name': '', 'email': ''})
        self.user.refresh_from_db()
        self.assertFalse(self.user.is_active)
