"""آزمون‌های امنیت و دقت: الزام مجوز، دامنه داده، محدودسازی نرخ، گزارش‌ساز."""
import datetime as gregorian

import jdatetime
from django.contrib.auth import get_user_model
from django.core.cache import cache
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import DailyRecord, Request
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
            badge_no='900', first_name='ن', last_name='ام', department=self.dept)
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
                               {'year': 1404, 'month': 7})
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
