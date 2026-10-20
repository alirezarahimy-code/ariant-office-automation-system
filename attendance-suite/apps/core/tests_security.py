"""آزمون‌های امنیت و دقت: الزام مجوز، دامنه داده، محدودسازی نرخ، گزارش‌ساز."""
import datetime as gregorian

import jdatetime
from django.contrib.auth import get_user_model
from django.core.cache import cache
from django.test import TestCase
from django.utils import timezone
from django.urls import reverse

from apps.attendance.models import DailyRecord, Punch, Request
from apps.device.models import Device, RawRecord, UnisBridge
from apps.core.models import Permission, Role, RoleDataScope, UserRole
from apps.core.services import (login_throttled, record_login_failure,
                                scoped_employees)
from apps.org.models import Department, Employee
from apps.reports.models import ReportDef

User = get_user_model()


class SecurityTests(TestCase):
    def setUp(self):
        self.dept = Department.objects.create(name='مالی', code='FIN')
        self.employee = Employee.objects.create(
            badge_no='900', first_name='ن', last_name='ام', department=self.dept,
            web_user=True)
        self.user = User.objects.create_user('u1', password='x',
                                             employee=self.employee)
        role = Role.objects.create(name='کارمند', code='emp')
        role.permissions.add(
            Permission.objects.create(module='attendance', action='view'),
            Permission.objects.create(module='requests', action='add'),
            Permission.objects.create(module='requests', action='view'))
        UserRole.objects.create(user=self.user, role=role)
        RoleDataScope.objects.create(role=role, module='attendance', scope='self')

    def tearDown(self):
        cache.clear()

    def test_login_required(self):
        resp = self.client.get(reverse('attendance:dashboard'))
        self.assertEqual(resp.status_code, 302)

    def test_permission_denied_403(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('attendance:policy'))
        self.assertEqual(resp.status_code, 403)

    def test_shift_configuration_requires_management_permission(self):
        self.client.force_login(self.user)
        response = self.client.get(reverse('attendance:shifts'))
        self.assertEqual(response.status_code, 403)
        response = self.client.post(reverse('attendance:shifts'), {
            'name': 'غیرمجاز', 'start_time': '08:00', 'end_time': '16:00',
        })
        self.assertEqual(response.status_code, 403)
        from apps.attendance.models import Shift
        self.assertFalse(Shift.objects.filter(name='غیرمجاز').exists())
        self.assertEqual(
            self.client.get(reverse('attendance:schedule_definitions')).status_code,
            403)
        self.assertEqual(
            self.client.get(reverse('attendance:temp_schedules')).status_code,
            403)
        self.assertEqual(
            self.client.get(reverse('attendance:valid_leaves')).status_code,
            403)

    def test_temporary_schedule_cannot_cross_employee_scope(self):
        from apps.attendance.models import ScheduleDefinition, TemporarySchedule
        role = self.user.user_roles.get().role
        role.permissions.add(Permission.objects.get_or_create(
            module='attendance', action='manage')[0])
        foreign = Employee.objects.create(
            badge_no='902', first_name='ب', last_name='یر')
        schedule = ScheduleDefinition.objects.create(
            name='سراسری', symbol='S', from_time=gregorian.time(8),
            to_time=gregorian.time(16))
        self.client.force_login(self.user)
        response = self.client.post(reverse('attendance:temp_schedules'), {
            'action': 'save_day', 'employee_id': [str(foreign.pk)],
            'day': '1405/01/01', 'schedule_id': str(schedule.pk),
            'start_date': '1405/01/01', 'end_date': '1405/01/01',
        })
        self.assertEqual(response.status_code, 302)
        self.assertFalse(TemporarySchedule.objects.exists())

    def test_holiday_configuration_requires_management_permission(self):
        self.client.force_login(self.user)
        response = self.client.get(reverse('attendance:holidays'))
        self.assertEqual(response.status_code, 403)
        response = self.client.post(reverse('attendance:holidays'), {
            'date': '1404/01/01', 'title': 'نوروز', 'kind': 'national',
        })
        self.assertEqual(response.status_code, 403)
        from apps.attendance.models import Holiday
        self.assertFalse(Holiday.objects.exists())

    def test_schedule_rules_require_login_and_view_permission(self):
        response = self.client.get(reverse('attendance:rules'))
        self.assertEqual(response.status_code, 302)
        self.client.force_login(self.user)
        response = self.client.get(reverse('attendance:rules'))
        self.assertEqual(response.status_code, 200)

    def test_device_add_validates_choices_port_and_serial(self):
        role = self.user.user_roles.get().role
        role.permissions.add(
            Permission.objects.get_or_create(module='device', action='view')[0],
            Permission.objects.get_or_create(module='device', action='add')[0],
        )
        self.client.force_login(self.user)
        self.assertContains(self.client.get(reverse('device:index')), 'ثبت دستگاه تازه')
        base = {'name': 'ترمینال', 'serial': 'DEV-SEC-1', 'port': '437'}
        invalid_brand = self.client.post(reverse('device:device_add'), {
            **base, 'brand': 'unknown', 'conn_type': 'tcp'})
        self.assertEqual(invalid_brand.status_code, 302)
        self.assertFalse(Device.objects.filter(serial='DEV-SEC-1').exists())
        invalid_port = self.client.post(reverse('device:device_add'), {
            **base, 'brand': 'zk', 'conn_type': 'tcp', 'port': '70000'})
        self.assertEqual(invalid_port.status_code, 302)
        self.assertFalse(Device.objects.filter(serial='DEV-SEC-1').exists())
        valid = self.client.post(reverse('device:device_add'), {
            **base, 'brand': 'zk', 'conn_type': 'tcp'})
        self.assertEqual(valid.status_code, 302)
        self.assertTrue(Device.objects.filter(serial='DEV-SEC-1').exists())

    def test_device_sync_requires_device_and_attendance_management(self):
        device = Device.objects.create(
            name='Virdi', serial='SEC-V1', brand=Device.Brand.VIRDI)
        bridge = UnisBridge.objects.create(
            name='UNIS', device=device, mode=UnisBridge.Mode.DIR,
            dir_path='/not-used')
        raw = RawRecord.objects.create(
            device=device, pin=self.employee.badge_no,
            punch_time=timezone.make_aware(
                gregorian.datetime(2026, 10, 20, 8, 0)))
        self.client.force_login(self.user)
        for url in (
            reverse('device:sync_punches'),
            reverse('device:gateway_sync'),
            reverse('device:unis_sync', args=[bridge.pk]),
        ):
            response = self.client.post(url)
            self.assertEqual(response.status_code, 403)
        raw.refresh_from_db()
        self.assertEqual(raw.state, RawRecord.State.NEW)
        self.assertFalse(DailyRecord.objects.exists())

    def test_manual_sync_uses_intersection_of_attendance_and_device_scopes(self):
        role = self.user.user_roles.get().role
        role.permissions.add(
            Permission.objects.get_or_create(module='attendance', action='manage')[0],
            Permission.objects.get_or_create(module='device', action='manage')[0],
            Permission.objects.get_or_create(module='device', action='view')[0],
        )
        RoleDataScope.objects.update_or_create(
            role=role, module='attendance', defaults={'scope': 'all'})
        device = Device.objects.create(name='ZK', serial='SEC-INTERSECTION')
        other = Employee.objects.create(
            badge_no='903', first_name='ب', last_name='یر')
        own_raw = RawRecord.objects.create(
            device=device, pin=self.employee.badge_no,
            punch_time=timezone.make_aware(gregorian.datetime(2026, 10, 20, 8, 0)))
        foreign_raw = RawRecord.objects.create(
            device=device, pin=other.badge_no,
            punch_time=timezone.make_aware(gregorian.datetime(2026, 10, 20, 9, 0)))
        self.client.force_login(self.user)
        listing = self.client.get(reverse('device:raw_records'))
        self.assertEqual(
            [record.pin for record in listing.context['records']],
            [self.employee.badge_no])
        response = self.client.post(reverse('device:sync_punches'))
        self.assertEqual(response.status_code, 302)
        own_raw.refresh_from_db()
        foreign_raw.refresh_from_db()
        self.assertEqual(own_raw.state, RawRecord.State.TRANSFERED)
        self.assertEqual(foreign_raw.state, RawRecord.State.NEW)
        self.assertEqual(Punch.objects.count(), 1)

    def test_global_sync_requires_global_employee_and_device_scopes(self):
        role = self.user.user_roles.get().role
        role.permissions.add(
            Permission.objects.get_or_create(module='attendance', action='manage')[0],
            Permission.objects.get_or_create(module='device', action='manage')[0],
            Permission.objects.get_or_create(module='device', action='view')[0],
        )
        device = Device.objects.create(name='Virdi', serial='SEC-GLOBAL',
                                       brand=Device.Brand.VIRDI)
        bridge = UnisBridge.objects.create(
            name='UNIS scoped', device=device, mode=UnisBridge.Mode.DIR,
            dir_path='/not-used')
        raw = RawRecord.objects.create(
            device=device, pin=self.employee.badge_no,
            punch_time=timezone.make_aware(gregorian.datetime(2026, 10, 20, 8, 0)))
        self.client.force_login(self.user)
        index_page = self.client.get(reverse('device:index'))
        self.assertNotContains(index_page, 'همگام‌سازی دروازه')
        bridge_page = self.client.get(reverse('device:unis_bridge'))
        self.assertNotContains(
            bridge_page, reverse('device:unis_sync', args=[bridge.pk]))
        for url in (
            reverse('device:gateway_sync'),
            reverse('device:unis_sync', args=[bridge.pk]),
        ):
            response = self.client.post(url)
            self.assertEqual(response.status_code, 403)
        raw.refresh_from_db()
        self.assertEqual(raw.state, RawRecord.State.NEW)
        self.assertFalse(Punch.objects.exists())

    def test_punch_sync_does_not_convert_out_of_scope_raw_records(self):
        role = self.user.user_roles.get().role
        role.permissions.add(
            Permission.objects.get_or_create(module='attendance', action='manage')[0],
            Permission.objects.get_or_create(module='device', action='manage')[0],
        )
        other = Employee.objects.create(
            badge_no='903', first_name='پ', last_name='یرسنل')
        device = Device.objects.create(name='ZK', serial='SEC-ZK')
        own_raw = RawRecord.objects.create(
            device=device, pin=self.employee.badge_no,
            punch_time=timezone.make_aware(gregorian.datetime(2026, 10, 20, 8, 0)))
        foreign_raw = RawRecord.objects.create(
            device=device, pin=other.badge_no,
            punch_time=timezone.make_aware(gregorian.datetime(2026, 10, 20, 9, 0)))
        self.client.force_login(self.user)
        response = self.client.post(reverse('device:sync_punches'))
        self.assertEqual(response.status_code, 302)
        own_raw.refresh_from_db()
        foreign_raw.refresh_from_db()
        self.assertEqual(own_raw.state, RawRecord.State.TRANSFERED)
        self.assertEqual(foreign_raw.state, RawRecord.State.NEW)
        self.assertEqual(list(Punch.objects.values_list('employee__badge_no', flat=True)),
                         [self.employee.badge_no])

    def test_scope_limits_requests(self):
        other = Employee.objects.create(badge_no='901', first_name='د',
                                        last_name='یگر')
        Request.objects.create(employee=other, kind='overtime')
        Request.objects.create(employee=self.employee, kind='overtime')
        self.client.force_login(self.user)
        resp = self.client.get(reverse('attendance:request_list'))
        self.assertEqual(resp.status_code, 200)
        self.assertEqual(len(resp.context['requests']), 1)

    def test_login_rate_limit(self):
        ip = '127.0.0.1'
        for _ in range(5):
            record_login_failure('victim', ip)
        self.assertTrue(login_throttled('victim', ip))
        resp = self.client.post(reverse('core:login'), {
            'username': 'victim', 'password': 'wrong'})
        self.assertContains(resp, 'تعداد تلاش')

    def test_login_success_audited(self):
        self.client.post(reverse('core:login'), {
            'username': 'u1', 'password': 'x'})
        from apps.core.models import AuditLog
        self.assertTrue(AuditLog.objects.filter(action='login').exists())

    def test_builder_rejects_invalid_column(self):
        admin = User.objects.create_superuser('root', password='x')
        self.client.force_login(admin)
        resp = self.client.post(reverse('reports:builder'), {
            'code': 'r1', 'title': 'گزارش', 'model_key': 'daily_record',
            'columns': 'employee; DROP TABLE x'})
        self.assertFalse(ReportDef.objects.filter(code='r1').exists())
        self.assertContains(resp, 'ستون نامعتبر')

    def test_builder_creates_valid_def(self):
        admin = User.objects.create_superuser('root', password='x')
        self.client.force_login(admin)
        self.client.post(reverse('reports:builder'), {
            'code': 'r2', 'title': 'گزارش من', 'model_key': 'daily_record',
            'columns': 'employee, date, late_minutes', 'order_by': 'date'})
        self.assertTrue(ReportDef.objects.filter(code='r2').exists())
        resp = self.client.get(reverse('reports:run_def', args=['r2']))
        self.assertEqual(resp.status_code, 200)


class AccuracyTests(TestCase):
    def setUp(self):
        self.employee = Employee.objects.create(
            badge_no='950', first_name='د', last_name='ق')

    def test_leap_day_parse(self):
        from apps.attendance.services import parse_jalali
        d = parse_jalali('1403/12/30')  # سال کبیسه
        self.assertEqual(d, jdatetime.date(1403, 12, 30).togregorian())

    def test_report_sum_math(self):
        from apps.attendance.services import register_punch
        d = jdatetime.date(1404, 7, 6).togregorian()
        register_punch(self.employee, d, gregorian.time(8, 0), 'in')
        register_punch(self.employee, d, gregorian.time(17, 0), 'out')
        admin = User.objects.create_superuser('root', password='x')
        self.client.force_login(admin)
        resp = self.client.get(reverse('reports:run_standard', args=['sum']),
                               {'year': 1404, 'month': 7,
                                'selectedBadgeNumbers': [self.employee.badge_no]})
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, '540')  # کارکرد دقیقه

    def test_csv_export_permission(self):
        user = User.objects.create_user('plain', password='x')
        self.client.force_login(user)
        resp = self.client.get(reverse('reports:run_standard', args=['sum']),
                               {'export': 'csv'})
        self.assertEqual(resp.status_code, 403)

    def test_scoped_self(self):
        user = User.objects.create_user('u2', password='x',
                                        employee=self.employee)
        role = Role.objects.create(name='x', code='x')
        UserRole.objects.create(user=user, role=role)
        RoleDataScope.objects.create(role=role, module='reports', scope='self')
        qs = scoped_employees(user, 'reports', Employee.objects.all())
        self.assertEqual(list(qs), [self.employee])
