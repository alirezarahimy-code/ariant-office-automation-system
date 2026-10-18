"""آزمون‌های حضور و غیاب حرفه‌ای: درخواست‌ها، جانشین، مرخصی، geo-fence و سیاست‌ها."""
import datetime as gregorian_datetime

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from apps.core.services import clear_feature_cache

from .models import (AttendancePunch, AttendancePolicy, AttendanceRecord,
                     AttendanceRequest, Deputy, GeoArea, LeaveBalance,
                     LeaveType, OvertimeConstant, RewardPenalty)
from .services import (approve_request, area_distance_m, can_approve,
                       create_request, detect_area, leave_balance_row,
                       location_report, overtime_report, register_punch,
                       reject_request, undo_request)

User = get_user_model()


class RequestFlowTests(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_attendance'))
        self.user = User.objects.create_user('emp', password='x')
        self.leave_type = LeaveType.objects.create(
            name='استحقاقی', code='annual', limit_per_year=30, needs_balance=True)
        self.today = jdatetime.date.today()
        policy = AttendancePolicy.get()
        policy.last_day_for_request = 31
        policy.save()

    def test_create_request_and_approve(self):
        req = create_request(
            kind=AttendanceRequest.Kind.LEAVE_DAILY, user=self.user,
            leave_type=self.leave_type, from_date=self.today,
            to_date=self.today + jdatetime.timedelta(days=1), days=2,
            description='سفر')
        self.assertEqual(req.status, AttendanceRequest.Status.PENDING)
        approve_request(req, self.manager, note='تأیید')
        req.refresh_from_db()
        self.assertEqual(req.status, AttendanceRequest.Status.APPROVED)
        balance = LeaveBalance.objects.get(
            user=self.user, leave_type=self.leave_type, jyear=self.today.year)
        self.assertEqual(float(balance.used), 2.0)

    def test_reject_keeps_balance(self):
        req = create_request(
            kind=AttendanceRequest.Kind.LEAVE_DAILY, user=self.user,
            leave_type=self.leave_type, from_date=self.today, to_date=self.today,
            days=1)
        reject_request(req, self.manager, note='نیاز نیست')
        self.assertFalse(LeaveBalance.objects.filter(user=self.user).exists())

    def test_undo_restores_balance(self):
        req = create_request(
            kind=AttendanceRequest.Kind.LEAVE_DAILY, user=self.user,
            leave_type=self.leave_type, from_date=self.today, to_date=self.today,
            days=3)
        approve_request(req, self.manager)
        undo_request(req, self.manager)
        balance = LeaveBalance.objects.get(
            user=self.user, leave_type=self.leave_type, jyear=self.today.year)
        self.assertEqual(float(balance.used), 0.0)
        req.refresh_from_db()
        self.assertEqual(req.status, AttendanceRequest.Status.CANCELLED)

    def test_non_approver_cannot_decide(self):
        stranger = User.objects.create_user('other', password='x')
        req = create_request(
            kind=AttendanceRequest.Kind.OVERTIME, user=self.user,
            for_date=self.today, hours=2)
        with self.assertRaises(ValueError):
            approve_request(req, stranger)

    def test_record_fix_quota(self):
        p = AttendancePolicy.get()
        p.record_fix_daily_limit = 1
        p.save()
        create_request(kind=AttendanceRequest.Kind.RECORD_FIX, user=self.user,
                       for_date=self.today)
        with self.assertRaises(ValueError):
            create_request(kind=AttendanceRequest.Kind.RECORD_FIX, user=self.user,
                           for_date=self.today)

    def test_deputy_can_approve(self):
        other_manager = User.objects.create_user('chief', password='x')
        other_manager.user_permissions.add(
            Permission.objects.get(codename='manage_attendance'))
        Deputy.objects.create(
            user=self.user, for_user=other_manager,
            from_date=self.today.togregorian() - gregorian_datetime.timedelta(days=1),
            to_date=self.today.togregorian() + gregorian_datetime.timedelta(days=1))
        req = create_request(
            kind=AttendanceRequest.Kind.MISSION_DAILY, user=self.manager,
            for_date=self.today, days=1)
        # کاربر عادی که جانشین مدیر است می‌تواند تأیید کند
        self.assertTrue(can_approve(self.user, req))
        approve_request(req, self.user, note='جانشین')
        req.refresh_from_db()
        self.assertEqual(req.status, AttendanceRequest.Status.APPROVED)

    def test_request_views(self):
        self.client.force_login(self.user)
        req = create_request(
            kind=AttendanceRequest.Kind.OVERTIME, user=self.user,
            for_date=self.today, hours=1, description='کار اضطراری')
        for name, args in [('attendance:dashboard', None),
                           ('attendance:request_list', None),
                           ('attendance:request_new', None),
                           ('attendance:request_detail', [req.pk]),
                           ('attendance:punches', None),
                           ('attendance:leaves', None),
                           ('attendance:overtime', None),
                           ('attendance:rewards', None),
                           ('attendance:geo', None),
                           ('attendance:location', None)]:
            resp = self.client.get(
                reverse(name, args=args) if args else reverse(name))
            self.assertEqual(resp.status_code, 200, name)

    def test_request_new_post(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('attendance:request_new'), {
            'kind': 'overtime', 'for_date': '1404/07/30',
            'hours': '3', 'description': 'پروژه'})
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(AttendanceRequest.objects.filter(
            user=self.user, kind='overtime').exists())


class PunchAndGeoTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('emp', password='x')
        self.area = GeoArea.objects.create(
            name='دفتر مرکزی', lat=35.7000, lng=51.4000, radius_m=150)

    def tearDown(self):
        clear_feature_cache()

    def test_punch_syncs_daily_record(self):
        d = jdatetime.date(1404, 7, 30)
        register_punch(user=self.user, date=d,
                       time=gregorian_datetime.time(8, 0), direction='in')
        register_punch(user=self.user, date=d,
                       time=gregorian_datetime.time(16, 30), direction='out')
        record = AttendanceRecord.objects.get(user=self.user, date=d)
        self.assertEqual(record.check_in, gregorian_datetime.time(8, 0))
        self.assertEqual(record.check_out, gregorian_datetime.time(16, 30))

    def test_geo_detect_area(self):
        inside = detect_area(35.7001, 51.4001)
        self.assertEqual(inside.pk, self.area.pk)
        outside = detect_area(35.9, 51.9)
        self.assertIsNone(outside)

    def test_haversine_distance(self):
        d = area_distance_m(35.7, 51.4, 35.7, 51.401)
        self.assertGreater(d, 80)
        self.assertLess(d, 110)

    def test_punch_with_location_records_area(self):
        punch = register_punch(
            user=self.user, date=jdatetime.date(1404, 7, 30),
            time=gregorian_datetime.time(8, 0), direction='in',
            lat=35.70005, lng=51.40005)
        self.assertEqual(punch.area_id, self.area.pk)

    def test_location_report(self):
        register_punch(user=self.user, date=jdatetime.date(1404, 7, 30),
                       time=gregorian_datetime.time(8, 0), direction='in',
                       lat=35.70005, lng=51.40005)
        register_punch(user=self.user, date=jdatetime.date(1404, 7, 30),
                       time=gregorian_datetime.time(17, 0), direction='out',
                       lat=35.9, lng=51.9)
        rows = location_report(1404, 7)
        self.assertEqual(len(rows), 2)
        states = sorted(r['inside'] for r in rows)
        self.assertEqual(states, [False, True])

    def test_punch_post_view(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('attendance:punch_add'), {
            'date': '1404/07/30', 'time': '08:15', 'direction': 'in'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(AttendancePunch.objects.count(), 1)


class PolicyAndReportsTests(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_attendance'))
        self.user = User.objects.create_user('emp', password='x')

    def tearDown(self):
        clear_feature_cache()

    def test_policy_singleton(self):
        p1 = AttendancePolicy.get()
        p2 = AttendancePolicy.get()
        self.assertEqual(p1.pk, p2.pk)
        self.assertEqual(float(p1.hours_per_day), 8.0)

    def test_policy_view_post(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('attendance:policy'), {
            'hours_per_day': '7.5', 'show_days': '30',
            'last_day_for_request': '20', 'max_request_age_days': '5',
            'record_fix_daily_limit': '2', 'record_fix_monthly_limit': '6',
            'valid_late_early': '1', 'allow_hourly_free': '0',
            'overtime_holiday_rate': '1.5', 'overtime_night_rate': '1.1'})
        self.assertEqual(resp.status_code, 302)
        p = AttendancePolicy.get()
        self.assertEqual(float(p.hours_per_day), 7.5)
        self.assertTrue(p.valid_late_early)

    def test_overtime_report(self):
        from .services import register_record
        d = jdatetime.date(1404, 7, 6)  # دوشنبه کاری
        register_record(user=self.user, date=d,
                        check_in=gregorian_datetime.time(8, 0),
                        check_out=gregorian_datetime.time(18, 0))
        rows = overtime_report(1404, 7)
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['overtime_minutes'], 120)

    def test_reward_add(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('attendance:reward_add'), {
            'user_id': self.user.pk, 'kind': 'reward', 'date': '1404/07/30',
            'title': 'پشتکار', 'amount': '500000'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(RewardPenalty.objects.count(), 1)

    def test_geo_add(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('attendance:geo_add'), {
            'name': 'انبار', 'lat': '35.6', 'lng': '51.3', 'radius_m': '200'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(GeoArea.objects.count(), 1)

    def test_constant_add(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('attendance:overtime_constant_add'), {
            'key': 'night', 'title': 'ضریب شب', 'value': '1.2'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(OvertimeConstant.objects.count(), 1)

    def test_leave_balance_row(self):
        lt = LeaveType.objects.create(name='استحقاقی', code='annual',
                                      limit_per_year=30, needs_balance=True)
        LeaveBalance.objects.create(user=self.user, leave_type=lt,
                                    jyear=1404, granted=30, used=6)
        row = leave_balance_row(self.user, lt, 1404)
        self.assertEqual(row['remaining'], 24.0)
