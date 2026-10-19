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
