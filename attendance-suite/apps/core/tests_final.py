"""آزمون‌های پردازش نهایی: ورود CSV، گردش‌کار، گزارش‌ساز پیشرفته، اعلان‌ها."""
import datetime as gregorian
import io

import jdatetime
from django.contrib.auth import get_user_model
from django.core.files.uploadedfile import SimpleUploadedFile
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import Punch, Request, WorkflowRule
from apps.org.models import Employee
from apps.reports.models import ReportDef

User = get_user_model()


class ImportCsvTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_superuser('root', password='x')
        self.employee = Employee.objects.create(
            badge_no='7001', first_name='و', last_name='رود')

    def test_import_csv_creates_punches(self):
        self.client.force_login(self.user)
        content = ('badge_no,date,time,direction\n'
                   '7001,1404/07/06,08:00,in\n'
                   '7001,1404/07/06,16:00,out\n'
                   'BAD,1404/07/06,08:00,in\n').encode('utf-8')
        file = SimpleUploadedFile('records.csv', content,
                                  content_type='text/csv')
        resp = self.client.post(reverse('attendance:import_csv'), {'file': file})
        self.assertEqual(resp.status_code, 200)
        self.assertEqual(Punch.objects.count(), 2)
        self.assertContains(resp, '۲')

    def test_import_requires_permission(self):
        plain = User.objects.create_user('plain', password='x')
        self.client.force_login(plain)
        resp = self.client.get(reverse('attendance:import_csv'))
        self.assertEqual(resp.status_code, 403)


class WorkflowRuleTests(TestCase):
    def setUp(self):
        self.employee = Employee.objects.create(
            badge_no='7002', first_name='گ', last_name='ردش')

    def test_auto_approve_when_rule_disabled(self):
        WorkflowRule.objects.create(kind=Request.Kind.OVERTIME,
                                    needs_approval=False)
        from apps.attendance.services import create_request
        req = create_request(Request.Kind.OVERTIME, self.employee,
                             for_date=gregorian.date.today(), hours=1)
        self.assertEqual(req.status, Request.Status.APPROVED)

    def test_default_needs_approval(self):
        from apps.attendance.services import create_request
        req = create_request(Request.Kind.OVERTIME, self.employee,
                             for_date=gregorian.date.today(), hours=1)
        self.assertEqual(req.status, Request.Status.PENDING)

    def test_custom_wf_page(self):
        admin = User.objects.create_superuser('root', password='x')
        self.client.force_login(admin)
        resp = self.client.get(reverse('attendance:custom_wf'))
        self.assertEqual(resp.status_code, 200)
        self.client.post(reverse('attendance:custom_wf'), {
            'kind': 'mission_daily', 'needs_approval': 'on',
            'use_deputy': 'on', 'description': 'تست'})
        self.assertTrue(WorkflowRule.objects.filter(
            kind='mission_daily').exists())


class AdvancedBuilderTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_superuser('root', password='x')
        self.employee = Employee.objects.create(
            badge_no='7003', first_name='س', last_name='از')

    def test_builder_sum_columns_and_filter(self):
        from apps.attendance.services import register_punch
        d = jdatetime.date(1404, 7, 6).togregorian()
        register_punch(self.employee, d, gregorian.time(8, 0), 'in')
        register_punch(self.employee, d, gregorian.time(18, 0), 'out')
        self.client.force_login(self.user)
        self.client.post(reverse('reports:builder'), {
            'code': 'sumrep', 'title': 'مجموع تأخیر', 'model_key': 'daily_record',
            'columns': 'employee, late_minutes',
            'sum_columns': 'late_minutes', 'order_by': 'date'})
        resp = self.client.get(reverse('reports:run_def', args=['sumrep']),
                               {'date_from': '1404/07/01',
                                'date_to': '1404/07/30'})
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'مجموع')

    def test_builder_rejects_bad_sum_column(self):
        self.client.force_login(self.user)
        self.client.post(reverse('reports:builder'), {
            'code': 'badsum', 'title': 'x', 'model_key': 'daily_record',
            'columns': 'employee', 'sum_columns': 'hack'})
        self.assertFalse(ReportDef.objects.filter(code='badsum').exists())

    def test_bad_date_filter(self):
        ReportDef.objects.create(
            code='d1', title='t', module='daily_record',
            columns_json=['employee'], definition_json={'model': 'daily_record'})
        self.client.force_login(self.user)
        resp = self.client.get(reverse('reports:run_def', args=['d1']),
                               {'date_from': 'garbage'})
        self.assertEqual(resp.status_code, 302)


class NotificationTests(TestCase):
    def test_notifications_page(self):
        admin = User.objects.create_superuser('root', password='x')
        self.client.force_login(admin)
        self.client.post(reverse('core:notifications'), {
            'kind': 'approved', 'title': 'تأیید شد', 'body': 'متن'})
        resp = self.client.get(reverse('core:notifications'))
        self.assertContains(resp, 'تأیید شد')


class NewPagesSmokeTests(TestCase):
    """دود آزمون صفحات تازه (بازسازی عمق — مطابق T5Web)."""

    def setUp(self):
        from apps.core.models import User
        self.admin = User.objects.create_superuser('admin2', password='x')
        self.client.force_login(self.admin)

    def test_all_new_pages_load(self):
        from django.urls import reverse
        names = [
            ('org:org_chart', []), ('org:titles', []), ('org:emp_types', []),
            ('org:certificates', []), ('org:custom_fields', []),
            ('org:userinfo', []), ('attendance:total_control', []),
            ('attendance:insert_record', []), ('attendance:edit_late_early', []),
            ('attendance:daily_leave_batch', []), ('attendance:calc_parameters', []),
            ('attendance:rules', []), ('attendance:ramadan', []),
            ('core:system_logs', []), ('core:change_pass', []),
            ('device:device_users', []), ('device:gates', []),
        ]
        for name, args in names:
            resp = self.client.get(reverse(name, args=args))
            self.assertIn(resp.status_code, (200, 302), f'{name} -> {resp.status_code}')

    def test_userinfo_create_and_edit(self):
        from django.urls import reverse
        resp = self.client.post(reverse('org:userinfo'), {
            'badge_no': '9001', 'first_name': 'ن', 'last_name': 'تست',
            'gender': 'M', 'hire_date': '1400-01-01'})
        self.assertEqual(resp.status_code, 302)
        from apps.org.models import Employee
        e = Employee.objects.get(badge_no='9001')
        self.assertEqual(e.gender, 'M')
        resp = self.client.post(reverse('org:userinfo_edit', args=[e.pk]), {
            'badge_no': '9001', 'first_name': 'نام', 'last_name': 'تستی',
            'father_name': 'پدر', 'gender': 'M', 'web_user': 'on'})
        self.assertEqual(resp.status_code, 302)
        e.refresh_from_db()
        self.assertEqual(e.father_name, 'پدر')
        self.assertTrue(e.web_user)

    def test_calc_parameter_create(self):
        from django.urls import reverse
        resp = self.client.post(reverse('attendance:calc_parameters'), {
            'name': 'کارکرد خالص', 'symbol': 'NetWork',
            'formula': '[WorkedHours]-[LeaveHours]', 'is_sum': 'on',
            'sum_function': 'SUM'})
        self.assertEqual(resp.status_code, 302)
        from apps.attendance.models import CalcParameter
        self.assertTrue(CalcParameter.objects.filter(symbol='NetWork').exists())

    def test_insert_record_creates_punch(self):
        from django.urls import reverse
        from apps.org.models import Employee
        Employee.objects.create(badge_no='9002', first_name='ر', last_name='ت')
        resp = self.client.post(reverse('attendance:insert_record'), {
            'selectedBadgeNumbers': ['9002'], 'date': '2026-10-20',
            'time': '08:00', 'check_type': 'in', 'description': 'دستی'})
        self.assertEqual(resp.status_code, 302)
        from apps.attendance.models import Punch
        self.assertTrue(Punch.objects.filter(employee__badge_no='9002',
                                             source='web').exists())
