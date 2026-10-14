"""آزمون‌های کنترل تردد: مدل‌ها، منطق محاسبه و نماها."""
import datetime

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from .models import AttendanceRecord, WorkSchedule
from .services import (day_metrics, default_schedule, monthly_summary,
                       register_record)

User = get_user_model()


class AttendanceModelTests(TestCase):
    def test_default_schedule_created(self):
        schedule = default_schedule()
        self.assertEqual(schedule.name, 'ساعات اداری')
        self.assertEqual(schedule.work_days, [0, 1, 2, 3, 4])

    def test_unique_record_per_user_per_day(self):
        user = User.objects.create_user('u1', password='x')
        d = jdatetime.date(1404, 7, 23)
        AttendanceRecord.objects.create(user=user, date=d,
                                        check_in=datetime.time(8, 0))
        with self.assertRaises(Exception):
            AttendanceRecord.objects.create(user=user, date=d,
                                            check_in=datetime.time(9, 0))

    def test_register_record_idempotent(self):
        user = User.objects.create_user('u1', password='x')
        d = jdatetime.date(1404, 7, 23)
        register_record(user=user, date=d, check_in=datetime.time(8, 0),
                        check_out=datetime.time(16, 0))
        register_record(user=user, date=d, check_in=datetime.time(8, 5),
                        check_out=datetime.time(16, 30))
        self.assertEqual(AttendanceRecord.objects.filter(user=user).count(), 1)
        record = AttendanceRecord.objects.get(user=user)
        self.assertEqual(record.check_in, datetime.time(8, 5))


class DayMetricsTests(TestCase):
    def setUp(self):
        self.schedule = default_schedule()

    def _record(self, check_in, check_out):
        return AttendanceRecord(user=None, date=jdatetime.date(1404, 7, 23),
                                check_in=check_in, check_out=check_out)

    def test_ontime(self):
        m = day_metrics(self._record(datetime.time(7, 55), datetime.time(16, 10)),
                        self.schedule)
        self.assertEqual(m['state'], 'ontime')
        self.assertEqual(m['late_minutes'], 0)

    def test_within_grace_not_late(self):
        m = day_metrics(self._record(datetime.time(8, 10), datetime.time(16, 0)),
                        self.schedule)
        self.assertEqual(m['state'], 'ontime')

    def test_late_beyond_grace(self):
        m = day_metrics(self._record(datetime.time(8, 40), datetime.time(16, 0)),
                        self.schedule)
        self.assertEqual(m['state'], 'late')
        self.assertEqual(m['late_minutes'], 25)  # ۴۰ دقیقه منهای ۱۵ مهلت

    def test_overtime(self):
        m = day_metrics(self._record(datetime.time(8, 0), datetime.time(17, 30)),
                        self.schedule)
        self.assertEqual(m['overtime_minutes'], 90)

    def test_missing_checkout_incomplete(self):
        m = day_metrics(self._record(datetime.time(8, 0), None), self.schedule)
        self.assertEqual(m['state'], 'incomplete')

    def test_absent(self):
        m = day_metrics(None, self.schedule)
        self.assertEqual(m['state'], 'absent')


class MonthlySummaryTests(TestCase):
    def setUp(self):
        self.schedule = default_schedule()
        self.user = User.objects.create_user('u1', password='x')

    def test_summary_counts(self):
        # ۱۴۰۴/۰۷ — روز اول (سه‌شنبه ۱۴۰۴/۰۷/۱) کاری است
        register_record(user=self.user, date=jdatetime.date(1404, 7, 1),
                        check_in=datetime.time(8, 0), check_out=datetime.time(16, 0))
        register_record(user=self.user, date=jdatetime.date(1404, 7, 2),
                        check_in=datetime.time(8, 40), check_out=datetime.time(16, 0))
        s = monthly_summary(self.user, 1404, 7, self.schedule)
        self.assertEqual(s['present_days'], 2)
        self.assertEqual(s['late_days'], 1)
        self.assertGreater(s['absent_days'], 0)
        self.assertEqual(len(s['rows']), 30)  # مهر ۳۰ روزه
        holiday_rows = [r for r in s['rows'] if r['state'] == 'holiday']
        self.assertTrue(all(not r['is_workday'] for r in holiday_rows))

    def test_esfand_leap_length(self):
        s = monthly_summary(self.user, 1403, 12, self.schedule)
        self.assertEqual(len(s['rows']), 30)  # ۱۴۰۳ کبیسه


class AttendanceViewTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_attendance'))

    def test_list_requires_login(self):
        resp = self.client.get(reverse('attendance:list'))
        self.assertEqual(resp.status_code, 302)

    def test_list_own_records(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('attendance:list'))
        self.assertEqual(resp.status_code, 200)
        self.assertEqual(resp.context['target'], self.user)

    def test_manager_sees_others(self):
        self.client.force_login(self.manager)
        resp = self.client.get(
            reverse('attendance:list') + f'?user={self.user.pk}')
        self.assertEqual(resp.status_code, 200)
        self.assertEqual(resp.context['target'], self.user)

    def test_user_cannot_select_others(self):
        self.client.force_login(self.user)
        resp = self.client.get(
            reverse('attendance:list') + f'?user={self.manager.pk}')
        self.assertEqual(resp.context['target'], self.user)

    def test_new_post_creates_record(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('attendance:new'), {
            'date': '1404/07/23', 'check_in': '08:00', 'check_out': '16:10',
            'user_id': str(self.user.pk), 'note': 'آزمون'})
        self.assertEqual(resp.status_code, 302)
        record = AttendanceRecord.objects.get(user=self.user)
        self.assertEqual(record.check_in, datetime.time(8, 0))

    def test_new_post_invalid_date(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('attendance:new'), {
            'date': 'نامعتبر', 'check_in': '08:00'})
        self.assertEqual(AttendanceRecord.objects.count(), 0)
        self.assertEqual(resp.status_code, 302)

    def test_monthly_view(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('attendance:month'))
        self.assertEqual(resp.status_code, 200)
