import csv
import datetime as gregorian
from unittest.mock import patch

import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import DailyRecord, Punch
from apps.core.models import AppSetting, Permission, Role, RoleDataScope, UserRole
from apps.org.models import (Department, Employee, EmployeeFieldDef,
                             EmployeeFieldVal, EmpType)
from apps.reports.models import ReportDef
from apps.reports.views import _csv_response

User = get_user_model()


class PersonnelReportTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_superuser('report-admin', password='x')
        self.client.force_login(self.admin)
        self.employee = Employee.objects.create(
            badge_no='RP-101', first_name='آرمان', last_name='آزمایشی')

    def test_csv_neutralizes_formula_like_text_without_changing_numbers(self):
        response = _csv_response('test', ['value'], [
            ['=1+1'], ['  @SUM(A1)'], [-12], ['+2'], ['\t=1'],
        ])
        rows = list(csv.reader(response.content.decode('utf-8-sig').splitlines()))
        self.assertEqual([row[0] for row in rows[1:]], [
            "'=1+1", "'  @SUM(A1)", '-12', "'+2", "'\t=1",
        ])

    def test_standard_report_applies_custom_jalali_interval(self):
        selected_date = jdatetime.date(1404, 7, 6).togregorian()
        excluded_date = jdatetime.date(1404, 8, 1).togregorian()
        DailyRecord.objects.create(
            employee=self.employee, date=selected_date, late_minutes=12,
            state=DailyRecord.State.LATE)
        DailyRecord.objects.create(
            employee=self.employee, date=excluded_date, late_minutes=20,
            state=DailyRecord.State.LATE)
        response = self.client.get(
            reverse('reports:run_standard', args=['late']), {
                'year': '1404', 'month': '7',
                'date_from': '1404/07/01', 'date_to': '1404/07/30',
            })
        self.assertEqual(response.status_code, 200)
        self.assertEqual(len(response.context['rows']), 1)
        self.assertEqual(response.context['rows'][0][1], '1404/07/06')
        self.assertContains(response, '۱۴۰۴/۰۷/۰۶')
        self.assertContains(response, '۱۴۰۴/۰۷/۰۱ تا ۱۴۰۴/۰۷/۳۰')

    def test_monthly_mode_ignores_stale_custom_interval_values(self):
        in_month = jdatetime.date(1404, 7, 6).togregorian()
        outside_month = jdatetime.date(1404, 8, 6).togregorian()
        DailyRecord.objects.create(
            employee=self.employee, date=in_month, late_minutes=12,
            state=DailyRecord.State.LATE)
        DailyRecord.objects.create(
            employee=self.employee, date=outside_month, late_minutes=20,
            state=DailyRecord.State.LATE)
        response = self.client.get(
            reverse('reports:run_standard', args=['late']), {
                'isMonthly': 'true', 'year': '1404', 'month': '7',
                'date_from': '1404/08/01', 'date_to': '1404/08/30',
            })
        self.assertEqual(response.status_code, 200)
        self.assertEqual(len(response.context['rows']), 1)
        self.assertEqual(response.context['rows'][0][1], '1404/07/06')
        self.assertTrue(response.context['is_monthly'])

    def test_report_page_requires_selection_and_preserves_reference_filters(self):
        response = self.client.get(reverse('reports:run_standard', args=['daily']))
        self.assertEqual(response.status_code, 200)
        self.assertFalse(response.context['report_ready'])
        self.assertContains(response, 'selectedBadgeNumbers')
        self.assertContains(response, 'FromDateHijri')
        self.assertContains(response, 'isMonthly')

    def test_daily_report_filters_jalali_interval_and_renders_jalali_date(self):
        department = Department.objects.create(name='واحد نمونه', code='RP-DPT')
        self.employee.department = department
        self.employee.save(update_fields=['department'])
        date = jdatetime.date(1404, 7, 6).togregorian()
        DailyRecord.objects.create(
            employee=self.employee, date=date,
            check_in=gregorian.time(8, 0), check_out=gregorian.time(17, 0),
            worked_minutes=540, state=DailyRecord.State.ONTIME)
        for punch_time, direction in (
                (gregorian.time(8, 0), Punch.Direction.IN),
                (gregorian.time(10, 0), Punch.Direction.IN),
                (gregorian.time(12, 0), Punch.Direction.OUT),
                (gregorian.time(13, 0), Punch.Direction.IN),
                (gregorian.time(14, 0), Punch.Direction.OUT),
                (gregorian.time(15, 0), Punch.Direction.IN),
                (gregorian.time(17, 0), Punch.Direction.OUT),
                (gregorian.time(18, 0), Punch.Direction.IN),
                (gregorian.time(19, 0), Punch.Direction.OUT)):
            Punch.objects.create(
                employee=self.employee, date=date, time=punch_time,
                direction=direction)
        DailyRecord.objects.create(
            employee=self.employee,
            date=jdatetime.date(1404, 8, 1).togregorian(),
            worked_minutes=400, state=DailyRecord.State.ONTIME)
        url = reverse('reports:run_standard', args=['daily'])
        params = {
            'selectedBadgeNumbers': [self.employee.badge_no],
            'FromDateHijri': '1404/07/01',
            'ToDateHijri': '1404/07/30',
        }
        response = self.client.get(url, params)
        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.context['report_ready'])
        self.assertEqual(len(response.context['rows']), 1)
        row = response.context['rows'][0]
        self.assertEqual(row[:5], [
            self.employee.badge_no, 'آرمان آزمایشی', 'واحد نمونه',
            '1404/07/06', 'یکشنبه'])
        self.assertEqual(row[5:8], ['08:00', '10:00', '13:00'])
        self.assertEqual(row[8:11], ['12:00', '14:00', '17:00'])
        self.assertEqual(row[11], 540)
        self.assertEqual(len(response.context['header']), 16)
        self.assertContains(response, '۱۴۰۴/۰۷/۰۶')
        export_response = self.client.get(url, {**params, 'export': 'csv'})
        csv_rows = list(csv.reader(
            export_response.content.decode('utf-8-sig').splitlines()))
        self.assertEqual(csv_rows[0][:5], [
            'شماره کارگری', 'نام', 'واحد', 'تاریخ', 'روز هفته'])
        self.assertEqual(csv_rows[1][:5], row[:5])

    def test_daily_report_pages_by_employee_and_exports_all_selected_rows(self):
        start = jdatetime.date(1404, 7, 1).togregorian()
        employees = [self.employee]
        for index in range(50):
            employees.append(Employee.objects.create(
                badge_no=f'RP-P{index:03d}', first_name='کارمند',
                last_name=f'صفحه {index + 2:02d}'))
        for index, employee in enumerate(employees):
            DailyRecord.objects.create(
                employee=employee, date=start, worked_minutes=480 + index,
                state=DailyRecord.State.ONTIME)
        params = {
            'selectedBadgeNumbers': [employee.badge_no for employee in employees],
            'FromDateHijri': '1404/07/01', 'ToDateHijri': '1404/07/30',
        }
        url = reverse('reports:run_standard', args=['daily'])
        response = self.client.get(url, params)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['page_obj'].paginator.num_pages, 51)
        self.assertEqual(len(response.context['rows']), 1)
        self.assertEqual(response.context['rows'][0][0], self.employee.badge_no)

        second_page = self.client.get(url, {**params, 'page': '2'})
        self.assertEqual(len(second_page.context['rows']), 1)
        self.assertEqual(second_page.context['rows'][0][0], employees[1].badge_no)
        csv_response = self.client.get(url, {**params, 'export': 'csv'})
        self.assertEqual(csv_response.status_code, 200)
        self.assertIn('text/csv', csv_response['Content-Type'])
        csv_text = csv_response.content.decode('utf-8-sig')
        self.assertIn(self.employee.last_name, csv_text)
        self.assertIn(employees[-1].last_name, csv_text)

    def test_daily_csv_refuses_silent_truncation_over_export_limit(self):
        first_date = jdatetime.date(1404, 7, 1).togregorian()
        for offset in range(2):
            DailyRecord.objects.create(
                employee=self.employee,
                date=first_date + gregorian.timedelta(days=offset),
                worked_minutes=480, state=DailyRecord.State.ONTIME)
        params = {
            'selectedBadgeNumbers': [self.employee.badge_no],
            'FromDateHijri': '1404/07/01', 'ToDateHijri': '1404/07/02',
            'export': 'csv',
        }
        with patch('apps.reports.views.MAX_DAILY_EXPORT_ROWS', 1):
            response = self.client.get(
                reverse('reports:run_standard', args=['daily']), params)
        self.assertEqual(response.status_code, 413)
        self.assertContains(response, 'خروجی ناقص', status_code=413)

    def test_sum_report_pages_one_summary_per_selected_employee(self):
        other = Employee.objects.create(
            badge_no='RP-SUM-2', first_name='نگار', last_name='دوم')
        date = jdatetime.date(1404, 7, 3).togregorian()
        for index, employee in enumerate((self.employee, other)):
            DailyRecord.objects.create(
                employee=employee, date=date, worked_minutes=400 + index,
                late_minutes=15 + index, early_minutes=5,
                overtime_minutes=25, state=DailyRecord.State.LATE)
        params = {
            'selectedBadgeNumbers': [self.employee.badge_no, other.badge_no],
            'isMonthly': 'true', 'year': '1404', 'month': '7',
        }
        url = reverse('reports:run_standard', args=['sum'])
        first = self.client.get(url, params)
        self.assertEqual(first.context['page_obj'].paginator.num_pages, 2)
        self.assertEqual(len(first.context['rows']), 1)
        self.assertEqual(first.context['rows'][0][:2], [
            self.employee.badge_no, 'آرمان آزمایشی'])
        self.assertEqual(first.context['rows'][0][2:], [1, 1, 0, 15, 5, 400, 25])
        second = self.client.get(url, {**params, 'page': '2'})
        self.assertEqual(len(second.context['rows']), 1)
        self.assertEqual(second.context['rows'][0][0], other.badge_no)
        export_response = self.client.get(url, {**params, 'export': 'csv'})
        csv_rows = list(csv.reader(
            export_response.content.decode('utf-8-sig').splitlines()))
        self.assertEqual(csv_rows[0][:2], ['شماره کارگری', 'نام'])
        self.assertEqual(csv_rows[1][:2], [
            self.employee.badge_no, 'آرمان آزمایشی'])
        self.assertEqual(csv_rows[2][:2], [other.badge_no, 'نگار دوم'])

    def test_personnel_selector_uses_reference_advanced_filter_families(self):
        AppSetting.objects.create(key='certificates', value='کارشناسی|ارشد')
        EmpType.objects.create(name='رسمی', code='ET-REPORT')
        enabled = EmployeeFieldDef.objects.create(
            title='گروه شیفت', field_type=EmployeeFieldDef.FieldType.DROPDOWN,
            options='الف|ب', is_search_filter=True)
        EmployeeFieldDef.objects.create(
            title='یادداشت داخلی', field_type=EmployeeFieldDef.FieldType.TEXT,
            is_search_filter=False)
        response = self.client.get(reverse(
            'reports:run_standard', args=['daily']))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'مدرک تحصیلی')
        self.assertContains(response, 'نوع استخدام')
        self.assertContains(response, 'گروه شیفت')
        self.assertNotContains(response, 'یادداشت داخلی')
        self.assertContains(response, f'data-filter-id="{enabled.pk}"')

    def test_department_tree_is_nested_and_limited_to_visible_employees(self):
        root = Department.objects.create(name='مدیریت کل', code='D-ROOT')
        child = Department.objects.create(
            name='منابع انسانی', code='D-HR', parent=root)
        hidden = Department.objects.create(name='مالی', code='D-FIN')
        self.employee.department = child
        self.employee.save(update_fields=['department'])
        outside = Employee.objects.create(
            badge_no='RP-204', first_name='رضا', last_name='بیرون',
            department=hidden)
        user = User.objects.create_user(
            'tree-scope', password='x', employee=self.employee)
        role = Role.objects.create(name='درخت محدود', code='rp-tree-scope')
        role.permissions.add(Permission.objects.get_or_create(
            module='reports', action='report')[0])
        UserRole.objects.create(user=user, role=role)
        RoleDataScope.objects.create(role=role, module='reports', scope='self')
        self.client.force_login(user)
        response = self.client.get(reverse('reports:run_standard', args=['daily']))
        self.assertEqual(response.status_code, 200)
        nodes = {node['id']: node for node in response.context['department_nodes']}
        self.assertEqual(set(nodes), {root.pk, child.pk})
        self.assertEqual(nodes[root.pk]['subtree_ids'], f'{root.pk},{child.pk}')
        self.assertEqual(nodes[child.pk]['ancestor_ids'], str(root.pk))
        self.assertNotIn(outside.department_id, nodes)

    def test_user_selection_is_confined_to_report_scope(self):
        other = Employee.objects.create(
            badge_no='RP-202', first_name='سارا', last_name='محدود')
        date = jdatetime.date(1405, 1, 1).togregorian()
        for employee in (self.employee, other):
            DailyRecord.objects.create(
                employee=employee, date=date, worked_minutes=480,
                state=DailyRecord.State.ONTIME)
        user = User.objects.create_user(
            'scoped-report', password='x', employee=self.employee)
        role = Role.objects.create(name='گزارش شخصی', code='rp-self')
        role.permissions.add(Permission.objects.get_or_create(
            module='reports', action='report')[0])
        UserRole.objects.create(user=user, role=role)
        RoleDataScope.objects.create(role=role, module='reports', scope='self')
        self.client.force_login(user)
        response = self.client.get(
            reverse('reports:run_standard', args=['daily']), {
                'selectedBadgeNumbers': [self.employee.badge_no, other.badge_no],
                'isMonthly': 'true', 'year': '1405', 'month': '1',
            })
        self.assertEqual(response.status_code, 200)
        self.assertEqual(len(response.context['rows']), 1)
        self.assertContains(response, 'آزمایشی')
        self.assertNotContains(response, 'محدود')

    def test_employee_search_endpoint_matches_reference_filter_families(self):
        root = Department.objects.create(name='عملیات', code='D-OPS')
        child = Department.objects.create(
            name='اداری', code='D-ADMIN', parent=root)
        outside_department = Department.objects.create(name='فروش', code='D-SALES')
        emp_type = EmpType.objects.create(name='رسمی', code='ET-PERM')
        certificate = 'کارشناسی'
        AppSetting.objects.create(
            key='certificates', value=f'{certificate}|کارشناسی ارشد')
        custom_dropdown = EmployeeFieldDef.objects.create(
            title='گروه کاری', field_type=EmployeeFieldDef.FieldType.DROPDOWN,
            options='الف|ب', is_search_filter=True)
        custom_boolean = EmployeeFieldDef.objects.create(
            title='مشمول طرح', field_type=EmployeeFieldDef.FieldType.BOOLEAN,
            is_search_filter=True)
        custom_text = EmployeeFieldDef.objects.create(
            title='محل خدمت', field_type=EmployeeFieldDef.FieldType.TEXT,
            is_search_filter=True)
        self.employee.department = child
        self.employee.emp_type = emp_type
        self.employee.certificate = certificate
        self.employee.save(update_fields=['department', 'emp_type', 'certificate'])
        EmployeeFieldVal.objects.create(
            employee=self.employee, field_def=custom_dropdown, value='الف')
        EmployeeFieldVal.objects.create(
            employee=self.employee, field_def=custom_boolean, value='on')
        EmployeeFieldVal.objects.create(
            employee=self.employee, field_def=custom_text, value='مرکز')
        outside = Employee.objects.create(
            badge_no='RP-306', first_name='علی', last_name='خارج',
            department=outside_department, certificate=certificate)
        params = {
            'department': str(root.pk), 'certificate': certificate,
            'employment_type': str(emp_type.pk),
            'custom_filter': [str(custom_dropdown.pk), str(custom_boolean.pk),
                              str(custom_text.pk)],
            f'custom_{custom_dropdown.pk}': 'الف',
            f'custom_{custom_boolean.pk}': 'true',
            f'custom_{custom_text.pk}': 'مرکز',
        }
        response = self.client.get(reverse('reports:employee_search'), params)
        self.assertEqual(response.status_code, 200)
        self.assertEqual([item['badge_no'] for item in response.json()['results']],
                         [self.employee.badge_no])
        self.assertNotIn(outside.badge_no,
                         [item['badge_no'] for item in response.json()['results']])

        false_filter = self.client.get(reverse('reports:employee_search'), {
            'custom_filter': str(custom_boolean.pk),
            f'custom_{custom_boolean.pk}': 'false',
        })
        self.assertEqual([item['badge_no'] for item in false_filter.json()['results']],
                         [outside.badge_no])

    def test_employee_search_endpoint_enforces_report_scope_and_filters(self):
        other = Employee.objects.create(
            badge_no='RP-305', first_name='نگار', last_name='خارج از دامنه')
        user = User.objects.create_user(
            'employee-search-scope', password='x', employee=self.employee)
        role = Role.objects.create(name='جستجوی محدود', code='employee-search-scope')
        role.permissions.add(Permission.objects.get_or_create(
            module='reports', action='report')[0])
        UserRole.objects.create(user=user, role=role)
        RoleDataScope.objects.create(role=role, module='reports', scope='self')
        self.client.force_login(user)

        url = reverse('reports:employee_search')
        response = self.client.get(url, {'q': 'RP-'})
        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual([row['badge_no'] for row in payload['results']],
                         [self.employee.badge_no])
        denied_search = self.client.get(url, {'q': other.badge_no})
        self.assertEqual(denied_search.json()['results'], [])
        invalid_filter = self.client.get(url, {'employment_type': 'not-an-id'})
        self.assertEqual(invalid_filter.status_code, 400)

    def test_custom_punch_report_respects_employee_scope(self):
        other = Employee.objects.create(
            badge_no='RP-303', first_name='نیما', last_name='بیرون')
        date = jdatetime.date(1405, 1, 2).togregorian()
        for employee in (self.employee, other):
            Punch.objects.create(
                employee=employee, date=date, time=gregorian.time(8, 0),
                direction=Punch.Direction.IN)
        ReportDef.objects.create(
            title='تردد', code='punches-scope', module='punch',
            columns_json=['employee', 'date'],
            definition_json={'model': 'punch', 'sum': []})
        user = User.objects.create_user(
            'punch-scope', password='x', employee=self.employee)
        role = Role.objects.create(name='گزارش تردد شخصی', code='punch-scope-role')
        role.permissions.add(Permission.objects.get_or_create(
            module='reports', action='report')[0])
        UserRole.objects.create(user=user, role=role)
        RoleDataScope.objects.create(role=role, module='reports', scope='self')
        self.client.force_login(user)
        response = self.client.get(reverse(
            'reports:run_def', args=['punches-scope']))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(len(response.context['rows']), 1)
        self.assertContains(response, 'آزمایشی')
        self.assertNotContains(response, 'بیرون')

    def test_device_report_requires_device_permission_and_global_scope(self):
        user = User.objects.create_user('report-no-device', password='x')
        role = Role.objects.create(name='گزارش‌خوان محدود دستگاه', code='report-no-device')
        role.permissions.add(Permission.objects.get_or_create(
            module='reports', action='report')[0])
        UserRole.objects.create(user=user, role=role)
        RoleDataScope.objects.create(role=role, module='device', scope='all')
        self.client.force_login(user)
        response = self.client.get(reverse(
            'reports:run_standard', args=['devices']))
        self.assertEqual(response.status_code, 403)
