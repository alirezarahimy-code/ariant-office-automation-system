"""آزمون‌های پردازش نهایی: ورود CSV، گردش‌کار، گزارش‌ساز پیشرفته، اعلان‌ها."""
import datetime as gregorian
import io

import jdatetime
from django.contrib.auth import get_user_model
from django.core.files.uploadedfile import SimpleUploadedFile
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import (
    AttendanceCalendar, CalendarHoliday, LeaveDescription, Punch, Request,
    ScheduleDefinition, TemporarySchedule, WorkflowRule,
)
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


class LeaveDescriptionTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_superuser('leave-admin', password='x')
        self.client.force_login(self.admin)

    def test_create_edit_and_delete_leave_description(self):
        url = reverse('attendance:leave_descriptions')
        response = self.client.post(url, {
            'description': 'ویزیت پزشک',
            'for_leave_type': str(LeaveDescription.ForLeaveType.LEAVE),
        })
        self.assertEqual(response.status_code, 302)
        row = LeaveDescription.objects.get()
        self.assertEqual(row.description, 'ویزیت پزشک')
        self.assertEqual(row.for_leave_type, LeaveDescription.ForLeaveType.LEAVE)

        edit_url = reverse('attendance:leave_description_edit', args=[row.pk])
        response = self.client.post(edit_url, {
            'description': 'مراجعه درمانی',
            'for_leave_type': str(LeaveDescription.ForLeaveType.DUTY),
        })
        self.assertEqual(response.status_code, 302)
        row.refresh_from_db()
        self.assertEqual(row.description, 'مراجعه درمانی')
        self.assertEqual(row.for_leave_type, LeaveDescription.ForLeaveType.DUTY)

        response = self.client.post(edit_url, {'action': 'delete'})
        self.assertEqual(response.status_code, 302)
        self.assertFalse(LeaveDescription.objects.exists())

    def test_invalid_type_is_rejected_without_saving(self):
        response = self.client.post(reverse('attendance:leave_descriptions'), {
            'description': 'نامعتبر', 'for_leave_type': '99',
        })
        self.assertEqual(response.status_code, 200)
        self.assertFalse(LeaveDescription.objects.exists())

    def test_management_requires_permission(self):
        user = User.objects.create_user('leave-reader', password='x')
        self.client.force_login(user)
        response = self.client.get(reverse('attendance:leave_descriptions'))
        self.assertEqual(response.status_code, 403)


class EmployeeExcelImportTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_superuser('excel-admin', password='x')
        self.client.force_login(self.admin)

    def _workbook_file(self):
        from openpyxl import Workbook
        output = io.BytesIO()
        workbook = Workbook()
        sheet = workbook.active
        sheet.append(['کد', 'نام', 'نام خانوادگی', 'دستگاه', 'ایمیل',
                      'تلفن', 'نشانی', 'شماره پرسنلی', 'جنسیت'])
        sheet.append(['8101', 'مینا', 'آزمایشی', 'دستگاه در', 'mina@example.com',
                      '555-0100', 'نشانی ۱', 'P-8101', 'زن'])
        sheet.append(['8102', '', 'نامعتبر', '', '', '', '', '', ''])
        sheet.append(['8101', 'تکراری', 'کارمند', '', '', '', '', '', 'مرد'])
        workbook.save(output)
        return SimpleUploadedFile(
            'personnel.xlsx', output.getvalue(),
            content_type='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')

    def _mapping(self):
        return {
            'selected_fields': [
                'badge_no', 'first_name', 'last_name', 'device_name', 'email',
                'tel', 'address', 'personnel_no', 'gender',
            ],
            'column_badge_no': '1', 'column_first_name': '2',
            'column_last_name': '3', 'column_device_name': '4',
            'column_email': '5', 'column_tel': '6', 'column_address': '7',
            'column_personnel_no': '8', 'column_gender': '9',
            'from_row': '2', 'to_row': '4',
        }

    def test_excel_preview_then_import_only_valid_rows(self):
        data = self._mapping()
        data.update({'action': 'preview', 'file': self._workbook_file()})
        response = self.client.post(reverse('org:import_excel'), data)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['valid_count'], 1)
        self.assertEqual(response.context['invalid_count'], 2)
        self.assertFalse(Employee.objects.exists())

        response = self.client.post(reverse('org:import_excel'), {'action': 'commit'})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['result']['imported'], 1)
        self.assertEqual(response.context['result']['skipped'], 0)
        employee = Employee.objects.get(badge_no='8101')
        self.assertEqual(employee.device_name, 'دستگاه در')
        self.assertEqual(employee.gender, 'F')
        self.assertEqual(employee.personnel_no, 'P-8101')

    def test_excel_import_rejects_duplicate_column_mapping(self):
        data = self._mapping()
        data['column_first_name'] = '1'
        data.update({'action': 'preview', 'file': self._workbook_file()})
        response = self.client.post(reverse('org:import_excel'), data)
        self.assertEqual(response.status_code, 302)
        self.assertFalse(Employee.objects.exists())

    def test_excel_import_requires_org_management(self):
        user = User.objects.create_user('excel-reader', password='x')
        self.client.force_login(user)
        self.assertEqual(self.client.get(reverse('org:import_excel')).status_code, 403)

    def test_excel_import_rejects_malformed_workbook(self):
        upload = SimpleUploadedFile('broken.xlsx', b'not an xlsx archive')
        data = self._mapping()
        data.update({'action': 'preview', 'file': upload})
        response = self.client.post(reverse('org:import_excel'), data)
        self.assertEqual(response.status_code, 302)
        self.assertFalse(Employee.objects.exists())


class ValidLeaveTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_superuser('permit-admin', password='x')
        self.employee = Employee.objects.create(
            badge_no='8201', first_name='پ', last_name='رسان')
        self.client.force_login(self.admin)

    def test_valid_leave_create_edit_delete(self):
        url = reverse('attendance:valid_leaves')
        response = self.client.post(url, {
            'employee_id': self.employee.pk,
            'from_date': '1405/02/01', 'to_date': '1405/02/03',
            'valid_hours': '02:30', 'description': 'مجوز ساعتی',
        })
        self.assertEqual(response.status_code, 302)
        permit = self.employee.valid_leave_permits.get()
        self.assertEqual(permit.valid_minutes, 150)
        self.assertEqual(permit.description, 'مجوز ساعتی')

        edit_url = reverse('attendance:valid_leave_edit', args=[permit.pk])
        response = self.client.post(edit_url, {
            'employee_id': self.employee.pk,
            'from_date': '1405/02/02', 'to_date': '1405/02/04',
            'valid_hours': '01:15', 'description': 'ویرایش مجوز',
        })
        self.assertEqual(response.status_code, 302)
        permit.refresh_from_db()
        self.assertEqual(permit.valid_minutes, 75)
        self.assertEqual(permit.description, 'ویرایش مجوز')

        response = self.client.post(edit_url, {'action': 'delete'})
        self.assertEqual(response.status_code, 302)
        self.assertFalse(self.employee.valid_leave_permits.exists())

    def test_invalid_permit_range_is_rejected(self):
        response = self.client.post(reverse('attendance:valid_leaves'), {
            'employee_id': self.employee.pk,
            'from_date': '1405/02/03', 'to_date': '1405/02/01',
            'valid_hours': '02:00',
        })
        self.assertEqual(response.status_code, 200)
        self.assertFalse(self.employee.valid_leave_permits.exists())


class ScheduleRebuildTests(TestCase):
    def setUp(self):
        self.admin = User.objects.create_superuser('schedule-admin', password='x')
        self.employee = Employee.objects.create(
            badge_no='7100', first_name='کارمند', last_name='آزمایشی')
        self.client.force_login(self.admin)

    def _schedule_data(self, **overrides):
        data = {
            'name': 'اداری', 'symbol': 'A',
            'from_time': '08:00', 'to_time': '16:00',
            'start_from': '07:00', 'end_from': '11:00',
            'start_to': '15:00', 'end_to': '19:00',
            'ramadan_from_time': '08:00', 'ramadan_to_time': '14:00',
            'late': '00:15', 'early': '00:15', 'suspend': '00:00',
            'interval_before': '00:00', 'work_days': '1',
            'work_minutes': '480', 'color': '#00ff00',
            'before_i': 'on', 'after_i': 'on',
            'rest_time': 'on', 'rest_from': ['12:00'], 'rest_to': ['12:30'],
        }
        data.update(overrides)
        return data

    def test_schedule_definition_create_edit_delete(self):
        url = reverse('attendance:schedule_definitions')
        response = self.client.post(url, self._schedule_data())
        self.assertEqual(response.status_code, 302)
        schedule = ScheduleDefinition.objects.get()
        self.assertEqual(schedule.from_time, gregorian.time(8, 0))
        self.assertEqual(schedule.late_minutes, 15)
        self.assertEqual(schedule.rest_times, [['12:00', '12:30']])

        edit_url = reverse('attendance:schedule_definition_edit', args=[schedule.pk])
        response = self.client.post(edit_url, self._schedule_data(name='تابستان'))
        self.assertEqual(response.status_code, 302)
        schedule.refresh_from_db()
        self.assertEqual(schedule.name, 'تابستان')

        response = self.client.post(edit_url, {'action': 'delete'})
        self.assertEqual(response.status_code, 302)
        self.assertFalse(ScheduleDefinition.objects.exists())

    def test_schedule_rejects_bad_duration(self):
        response = self.client.post(
            reverse('attendance:schedule_definitions'),
            self._schedule_data(late='00:90'))
        self.assertEqual(response.status_code, 200)
        self.assertFalse(ScheduleDefinition.objects.exists())

    def test_calendar_holiday_and_temporary_schedule_affect_day(self):
        calendar = AttendanceCalendar.objects.create(name='کاری')
        response = self.client.post(
            reverse('attendance:calendar_holidays', args=[calendar.pk]),
            {'date': '1405/01/02', 'description': 'روز سازمانی'})
        self.assertEqual(response.status_code, 200)
        holiday = CalendarHoliday.objects.get(calendar=calendar)
        self.assertEqual(holiday.description, 'روز سازمانی')

        schedule = ScheduleDefinition.objects.create(
            name='اداری', symbol='A', from_time=gregorian.time(8),
            to_time=gregorian.time(16), late_minutes=15, early_minutes=15,
            work_days=1, work_minutes=480, calendar=calendar)
        day = jdatetime.date(1405, 1, 2).togregorian()
        TemporarySchedule.objects.create(
            employee=self.employee, date=day, schedule=schedule)
        from apps.attendance.services import day_metrics, shift_for_employee
        self.assertEqual(shift_for_employee(self.employee, day), schedule)
        result = day_metrics(day, gregorian.time(9), gregorian.time(15), self.employee)
        self.assertEqual(result['state'], 'holiday')

    def test_group_temporary_schedule_is_saved_and_used_for_calculation(self):
        schedule = ScheduleDefinition.objects.create(
            name='اداری', symbol='A', from_time=gregorian.time(8),
            to_time=gregorian.time(16), late_minutes=15, early_minutes=15,
            work_days=1, work_minutes=480)
        day = jdatetime.date(1405, 1, 3).togregorian()
        date_text = '1405/01/03'
        response = self.client.post(reverse('attendance:temp_schedules'), {
            'action': 'show', 'employee_id': [str(self.employee.pk)],
            'start_date': date_text, 'end_date': date_text,
        })
        self.assertEqual(response.status_code, 302)
        response = self.client.get(response['Location'])
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, self.employee.badge_no)

        response = self.client.post(reverse('attendance:temp_schedules'), {
            'action': 'save_day', 'employee_id': [str(self.employee.pk)],
            'day': date_text, 'schedule_id': str(schedule.pk),
            'start_date': date_text, 'end_date': date_text,
        })
        self.assertEqual(response.status_code, 302)
        self.assertTrue(TemporarySchedule.objects.filter(
            employee=self.employee, date=day, schedule=schedule).exists())

        from apps.attendance.services import day_metrics
        result = day_metrics(day, gregorian.time(8, 20), gregorian.time(15, 50),
                             self.employee)
        self.assertEqual(result['late_minutes'], 5)
        response = self.client.get(reverse('attendance:temp_schedules'), {
            'employee_id': self.employee.pk,
            'start_date': date_text, 'end_date': date_text,
        })
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'اداری')


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
            ('attendance:valid_leaves', []),
            ('attendance:schedule_definitions', []), ('attendance:temp_schedules', []),
            ('attendance:holidays', []),
            ('attendance:leave_descriptions', []),
            ('core:system_logs', []), ('core:change_pass', []),
            ('device:device_users', []), ('device:gates', []),
            ('device:operations', []), ('device:usb_import', []),
        ]
        for name, args in names:
            resp = self.client.get(reverse(name, args=args))
            self.assertIn(resp.status_code, (200, 302), f'{name} -> {resp.status_code}')

    def test_t5_menu_keeps_reference_base_data_order(self):
        from django.urls import reverse
        response = self.client.get(reverse('attendance:dashboard'))
        self.assertEqual(response.status_code, 200)
        base_group = next(group for group in response.context['t5_menu_groups']
                          if group['title'] == 'اطلاعات پایه')
        labels = [item['label'] for item in base_group['items']]
        self.assertLess(labels.index('نوع خروج'), labels.index('شرح خروج'))
        self.assertLess(labels.index('شرح خروج'), labels.index('تقویم'))
        import_item = next(item for item in base_group['items']
                           if item['label'] == 'ورود اطلاعات کاربران از Excel')
        self.assertEqual(import_item['url'], reverse('org:import_excel'))

    def test_t5_attendance_menu_matches_reference_order(self):
        from django.urls import reverse
        response = self.client.get(reverse('attendance:dashboard'))
        group = next(item for item in response.context['t5_menu_groups']
                     if item['title'] == 'اطلاعات حضور و غیاب')
        self.assertEqual([item['label'] for item in group['items']], [
            'جدول زمانی', 'شیفت', 'اختصاص شیفت به پرسنل',
            'شیفت‌های موقت', 'اختصاص قوانین',
        ])

    def test_unis_usb_bridge_is_not_exposed_as_a_t5_menu_feature(self):
        from django.urls import reverse
        from apps.core.models import Permission, Role, User, UserRole
        from apps.t5web.menu import t5web_navigation
        user = User.objects.create_user('usb-menu-user', password='x')
        role = Role.objects.create(name='USB ops', code='usb-menu-ops')
        role.permissions.add(
            Permission.objects.get_or_create(module='attendance', action='manage')[0],
            Permission.objects.get_or_create(module='device', action='manage')[0],
        )
        UserRole.objects.create(user=user, role=role)
        request = type('Request', (), {'user': user})()
        items = [entry for group in t5web_navigation(request)['t5_menu_groups']
                 for entry in group['items']]
        self.assertNotIn('مدیریت اطلاعات USB', [item['label'] for item in items])
        self.assertNotIn(reverse('device:usb_import'), [item['url'] for item in items])

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
        self.assertEqual(resp.status_code, 200)
        from apps.attendance.models import Punch
        punch = Punch.objects.get(employee__badge_no='9002', source='web')
        self.assertEqual(punch.direction, Punch.Direction.IN)
        self.assertEqual(punch.check_type, 0)

    def test_insert_record_accepts_reference_field_names_and_jalali_date(self):
        from django.urls import reverse
        from apps.org.models import Employee
        Employee.objects.create(badge_no='9003', first_name='م', last_name='ت')
        response = self.client.post(reverse('attendance:insert_record'), {
            'selectedBadgeNumbers': ['9003'], 'dateHijri': '1405/07/28',
            'timeOfDay': '09:15', 'checkType': '1', 'isFreeze': 'false',
        })
        self.assertEqual(response.status_code, 200)
        from apps.attendance.models import Punch
        punch = Punch.objects.get(employee__badge_no='9003')
        self.assertEqual(punch.date, jdatetime.date(1405, 7, 28).togregorian())
        self.assertEqual(punch.time, gregorian.time(9, 15))
        self.assertEqual(punch.direction, Punch.Direction.OUT)
        self.assertEqual(punch.check_type, 1)

    def test_insert_record_rejects_unmapped_check_types_and_empty_selection(self):
        from django.urls import reverse
        from apps.org.models import Employee
        Employee.objects.create(badge_no='9004', first_name='ن', last_name='ت')
        url = reverse('attendance:insert_record')
        response = self.client.post(url, {
            'selectedBadgeNumbers': ['9004'], 'dateHijri': '1405/07/28',
            'timeOfDay': '08:00', 'checkType': '7',
        })
        self.assertEqual(response.status_code, 200)
        from apps.attendance.models import Punch
        self.assertFalse(Punch.objects.filter(employee__badge_no='9004').exists())
        response = self.client.post(url, {
            'dateHijri': '1405/07/28', 'timeOfDay': '08:00', 'checkType': '0',
        })
        self.assertEqual(response.status_code, 200)
        self.assertFalse(Punch.objects.exists())

    def test_insert_record_freezes_scoped_badge_and_rejects_external_referrer(self):
        from django.urls import reverse
        from apps.org.models import Employee
        Employee.objects.create(badge_no='9005', first_name='ث', last_name='ت')
        response = self.client.get(reverse('attendance:insert_record'), {
            'badgeNumber': '9005', 'referrerURL': 'https://evil.example/steal',
        })
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'name="isFreeze" value="true"')
        self.assertContains(response, 'name="badgeNumber" value="9005"')
        self.assertNotContains(response, 'https://evil.example/steal')
