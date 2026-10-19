"""آزمون‌های سامانه یکپارچه: مدل‌ها، RBAC، منطق حضور و غیاب."""
import datetime as gregorian

import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase

from apps.attendance.models import (AttendancePolicy, DailyRecord, Holiday,
                                    LeaveBalance, LeaveType, Punch, Request,
                                    Shift, ShiftAssignment)
from apps.attendance.services import (approve_request, area_distance_m,
                                      create_request, day_metrics, detect_area,
                                      monthly_summary, parse_jalali,
                                      register_punch, undo_request)
from apps.core.models import Permission, Role, RoleDataScope, UserRole
from apps.core.services import audit, data_scope, has_perm, scoped_employees
from apps.device.models import Device, RawRecord
from apps.org.models import Department, Employee, Position

User = get_user_model()


class OrgModelTests(TestCase):
    def test_badge_unique(self):
        Employee.objects.create(badge_no='100', first_name='الف', last_name='ب')
        with self.assertRaises(Exception):
            Employee.objects.create(badge_no='100', first_name='ج', last_name='د')

    def test_department_descendants(self):
        root = Department.objects.create(name='دفتر مرکزی', code='HQ')
        child = Department.objects.create(name='فناوری', code='IT', parent=root)
        child2 = Department.objects.create(name='نرم‌افزار', code='SW', parent=child)
        self.assertEqual([d.pk for d in root.descendants()],
                         [root.pk, child.pk, child2.pk])

    def test_position_chart(self):
        ceo = Position.objects.create(title='مدیرعامل', is_manager=True)
        cto = Position.objects.create(title='مدیر فناوری', parent=ceo,
                                      is_manager=True)
        self.assertEqual(cto.parent_id, ceo.pk)


class RbacTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('emp', password='x')
        self.dept = Department.objects.create(name='مالی', code='FIN')
        self.employee = Employee.objects.create(
            badge_no='200', first_name='ز', last_name='ر',
            department=self.dept)
        self.user.employee = self.employee
        self.user.save()
        self.role = Role.objects.create(name='کارمند', code='employee')
        view = Permission.objects.create(module='attendance', action='view')
        self.role.permissions.add(view)
        UserRole.objects.create(user=self.user, role=self.role)
        RoleDataScope.objects.create(
            role=self.role, module='attendance', scope='self')

    def test_has_perm(self):
        self.assertTrue(has_perm(self.user, 'attendance', 'view'))
        self.assertFalse(has_perm(self.user, 'attendance', 'delete'))

    def test_data_scope_self(self):
        qs = Employee.objects.all()
        scoped = scoped_employees(self.user, 'attendance', qs)
        self.assertEqual(list(scoped), [self.employee])

    def test_superuser_scope_all(self):
        admin = User.objects.create_superuser('root', password='x')
        self.assertEqual(data_scope(admin, 'attendance'), 'all')

    def test_audit(self):
        audit(self.user, 'login', detail='ورود موفق')
        from apps.core.models import AuditLog
        self.assertEqual(AuditLog.objects.count(), 1)


class AttendanceLogicTests(TestCase):
    def setUp(self):
        self.dept = Department.objects.create(name='عملیات', code='OPS')
        self.employee = Employee.objects.create(
            badge_no='300', first_name='ک', last_name='و', department=self.dept)
        self.shift = Shift.objects.create(
            name='اداری', start_time=gregorian.time(8, 0),
            end_time=gregorian.time(16, 0), work_days=[0, 1, 2, 3, 4],
            grace_minutes=15)
        ShiftAssignment.objects.create(
            employee=self.employee, shift=self.shift,
            from_date=gregorian.date(2020, 1, 1))

    def test_parse_jalali(self):
        d = parse_jalali('1404/07/30')
        self.assertEqual(d, jdatetime.date(1404, 7, 30).togregorian())

    def test_day_metrics_ontime(self):
        d = jdatetime.date(1404, 7, 6).togregorian()  # دوشنبه کاری
        m = day_metrics(d, gregorian.time(8, 0), gregorian.time(16, 0),
                        employee=self.employee)
        self.assertEqual(m['state'], DailyRecord.State.ONTIME)
        self.assertEqual(m['worked_minutes'], 480)
        self.assertEqual(m['overtime_minutes'], 0)

    def test_day_metrics_late(self):
        d = jdatetime.date(1404, 7, 6).togregorian()
        m = day_metrics(d, gregorian.time(8, 30), gregorian.time(16, 0),
                        employee=self.employee)
        self.assertEqual(m['state'], DailyRecord.State.LATE)
        self.assertEqual(m['late_minutes'], 15)

    def test_punch_sync_and_idempotent_daily(self):
        d = jdatetime.date(1404, 7, 6).togregorian()
        register_punch(self.employee, d, gregorian.time(8, 0), 'in')
        register_punch(self.employee, d, gregorian.time(18, 15), 'out')
        record = DailyRecord.objects.get(employee=self.employee, date=d)
        self.assertEqual(record.overtime_minutes, 135)
        self.assertEqual(Punch.objects.count(), 2)

    def test_overtime_on_holiday(self):
        d = gregorian.date(2026, 10, 23)  # جمعه
        Holiday.objects.create(date=d, title='روز ملی')
        m = day_metrics(d, gregorian.time(9, 0), gregorian.time(13, 0),
                        employee=self.employee)
        self.assertEqual(m['state'], DailyRecord.State.HOLIDAY)
        self.assertEqual(m['overtime_minutes'], 240)

    def test_request_workflow(self):
        lt = LeaveType.objects.create(name='استحقاقی', code='annual',
                                      limit_per_year=30, needs_balance=True)
        policy = AttendancePolicy.get()
        policy.last_day_for_request = 31
        policy.save()
        manager_emp = Employee.objects.create(
            badge_no='301', first_name='مد', last_name='یر')
        self.employee.manager = manager_emp
        self.employee.save()
        approver = User.objects.create_user('approver', password='x')
        approver.employee = manager_emp
        approver.save()
        req = create_request(
            Request.Kind.LEAVE_DAILY, self.employee, leave_type=lt,
            from_date=jdatetime.date(1404, 7, 20).togregorian(),
            to_date=jdatetime.date(1404, 7, 21).togregorian(), days=2)
        approve_request(req, approver)
        balance = LeaveBalance.objects.get(
            employee=self.employee, leave_type=lt, jyear=1404)
        self.assertEqual(float(balance.used), 2.0)
        undo_request(req, approver)
        balance.refresh_from_db()
        self.assertEqual(float(balance.used), 0.0)

    def test_record_fix_quota(self):
        policy = AttendancePolicy.get()
        policy.record_fix_daily_limit = 1
        policy.save()
        d = jdatetime.date(1404, 7, 6).togregorian()
        create_request(Request.Kind.RECORD_FIX, self.employee, for_date=d)
        with self.assertRaises(ValueError):
            create_request(Request.Kind.RECORD_FIX, self.employee, for_date=d)

    def test_geo_fence(self):
        from apps.attendance.models import GeoArea
        area = GeoArea.objects.create(name='دفتر', lat=35.7, lng=51.4,
                                      radius_m=150)
        self.assertEqual(detect_area(35.7001, 51.4001).pk, area.pk)
        self.assertIsNone(detect_area(35.9, 51.9))
        self.assertGreater(area_distance_m(35.7, 51.4, 35.7, 51.401), 80)

    def test_monthly_summary(self):
        d1 = jdatetime.date(1404, 7, 6).togregorian()
        register_punch(self.employee, d1, gregorian.time(8, 0), 'in')
        register_punch(self.employee, d1, gregorian.time(16, 0), 'out')
        s = monthly_summary(self.employee, 1404, 7)
        self.assertEqual(s['present_days'], 1)
        self.assertEqual(s['worked_minutes'], 480)


class DeviceTests(TestCase):
    def test_raw_record_unique(self):
        dev = Device.objects.create(name='ورودی', serial='SN-1')
        t = gregorian.datetime(2026, 10, 19, 8, 0)
        RawRecord.objects.create(device=dev, pin='300', punch_time=t)
        with self.assertRaises(Exception):
            RawRecord.objects.create(device=dev, pin='300', punch_time=t)
