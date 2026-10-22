"""Offline parity and regression checks for T5Web T5-02 personnel forms."""
import datetime
import tempfile
from io import BytesIO

import jdatetime
from PIL import Image
from django.contrib.auth import get_user_model
from django.core.files.storage import default_storage
from django.core.files.uploadedfile import SimpleUploadedFile
from django.test import TestCase, override_settings
from django.urls import reverse
from django.utils import timezone

from apps.attendance.models import LeaveType
from apps.core.models import (AppSetting, Permission, Role, RoleDataScope,
                              UserRole)
from apps.device.models import Device, DeviceUserSync
from apps.reports.views import _certificate_choices
from .models import (Certificate, Department, Employee, EmployeeFieldDef,
                    EmployeeFieldVal, EmpType, ManagerProfile, Position)


class T5BaseDataFormTests(TestCase):
    def setUp(self):
        self.admin = get_user_model().objects.create_superuser(
            username='org-t5-admin', email='org-t5-admin@example.test',
            password='test-password')
        self.client.force_login(self.admin)

    def _post_data(self, **overrides):
        data = {
            'badge_no': 'T5-9001',
            'first_name': 'آزمایش',
            'last_name': 'پرسنل',
            'birthday': '',
            'hire_date': '',
            'end_work_date': '',
            'device_group': '1',
            'device_privilege': '0',
            'device_enable': 'on',
            'employment_active': 'on',
        }
        data.update(overrides)
        return data

    def test_userinfo_personnel_picker_searches_and_paginates_scoped_records(self):
        target = Employee.objects.create(
            badge_no='T5-SEARCH-9', personnel_no='PN-9',
            first_name='جستجوپذیر', last_name='کارمند')
        for number in range(55):
            Employee.objects.create(
                badge_no=f'T5-PAGE-{number:02d}', first_name='صفحه',
                last_name='آزمایشی')
        response = self.client.get(reverse('org:userinfo'), {'q': 'جستجوپذیر'})
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, target.badge_no)
        self.assertNotContains(response, 'T5-PAGE-00')

        response = self.client.get(reverse('org:userinfo'))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'صفحهٔ 1 از 2')

    def test_userinfo_ends_employment_without_hard_delete_and_revokes_account(self):
        employee = Employee.objects.create(
            badge_no='T5-END-1', first_name='پایان', last_name='همکاری',
            web_user=True, program_user=True)
        linked_user = get_user_model().objects.create_user(
            username='t5-end-user', password='test-password', employee=employee)
        response = self.client.post(
            reverse('org:userinfo_edit', args=[employee.pk]),
            self._post_data(badge_no=employee.badge_no,
                            end_work_date='1405-02-03', employment_active=''))
        self.assertEqual(response.status_code, 302)
        employee.refresh_from_db()
        linked_user.refresh_from_db()
        self.assertFalse(employee.is_active)
        self.assertEqual(employee.end_work_date,
                         jdatetime.date(1405, 2, 3).togregorian())
        self.assertFalse(linked_user.is_active)
        self.assertTrue(Employee.objects.filter(pk=employee.pk).exists())
        response = self.client.get(reverse('org:userinfo'), {'q': employee.badge_no})
        self.assertContains(response, employee.badge_no)

    def test_userinfo_converts_persian_jalali_dates_to_gregorian_storage(self):
        response = self.client.post(reverse('org:userinfo'), self._post_data(
            birthday='۱۳۶۵/۰۱/۱۵', hire_date='1400-01-01',
            end_work_date='1405.02.03'))

        self.assertEqual(response.status_code, 302)
        employee = Employee.objects.get(badge_no='T5-9001')
        self.assertEqual(
            employee.birthday, jdatetime.date(1365, 1, 15).togregorian())
        self.assertEqual(
            employee.hire_date, jdatetime.date(1400, 1, 1).togregorian())
        self.assertEqual(
            employee.end_work_date, jdatetime.date(1405, 2, 3).togregorian())

    def test_userinfo_persists_upload_and_valid_device_selections(self):
        upload_device = Device.objects.create(name='دستگاه ورودی', serial='T5-UP-1')
        valid_device = Device.objects.create(name='دستگاه مجاز', serial='T5-VAL-1')
        response = self.client.post(reverse('org:userinfo'), self._post_data(
            upload_device_ids=[str(upload_device.pk)],
            valid_device_ids=[str(valid_device.pk)]))
        self.assertEqual(response.status_code, 302)
        employee = Employee.objects.get(badge_no='T5-9001')
        self.assertEqual(list(employee.upload_devices.all()), [upload_device])
        self.assertEqual(list(employee.valid_devices.all()), [valid_device])
        DeviceUserSync.objects.create(
            device=upload_device, employee=employee,
            direction=DeviceUserSync.Direction.DOWN)
        response = self.client.post(
            reverse('org:userinfo_edit', args=[employee.pk]),
            {'action': 'queue_device_upload'}, follow=True)
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'برای ارسال به دستگاه‌های انتخاب‌شده در صف قرار گرفت')
        sync_row = DeviceUserSync.objects.get(
            device=upload_device, employee=employee,
            direction=DeviceUserSync.Direction.UP)
        self.assertEqual(sync_row.direction, DeviceUserSync.Direction.UP)
        self.assertEqual(sync_row.state, DeviceUserSync.State.PENDING)
        self.assertTrue(DeviceUserSync.objects.filter(
            device=upload_device, employee=employee,
            direction=DeviceUserSync.Direction.DOWN).exists())

        inactive_device = Device.objects.create(
            name='دستگاه غیرفعال', serial='T5-INACTIVE-1', is_active=False)
        employee.upload_devices.add(inactive_device)
        response = self.client.post(reverse('org:userinfo_edit', args=[employee.pk]),
                                    self._post_data(
                                        badge_no=employee.badge_no,
                                        upload_device_ids=[str(inactive_device.pk)],
                                        valid_device_ids=[]))
        self.assertEqual(response.status_code, 302)
        self.assertEqual(list(employee.upload_devices.all()), [inactive_device])
        self.assertFalse(employee.valid_devices.exists())

    def test_userinfo_does_not_allow_org_only_user_to_change_device_assignments(self):
        upload_device = Device.objects.create(name='موجود', serial='T5-ORG-1')
        requested_device = Device.objects.create(name='درخواستی', serial='T5-ORG-2')
        employee = Employee.objects.create(
            badge_no='T5-ORG-EMP', first_name='کاربر', last_name='محدود')
        employee.upload_devices.add(upload_device)
        role = Role.objects.create(name='مدیریت سازمان', code='t5-org-only')
        role.permissions.add(
            Permission.objects.get_or_create(module='org', action='manage')[0],
            Permission.objects.get_or_create(module='org', action='view')[0])
        RoleDataScope.objects.create(role=role, module='org', scope='all')
        actor = get_user_model().objects.create_user(
            username='t5-org-only', password='test-password')
        UserRole.objects.create(user=actor, role=role)
        self.client.force_login(actor)

        response = self.client.post(
            reverse('org:userinfo_edit', args=[employee.pk]),
            self._post_data(badge_no=employee.badge_no,
                            upload_device_ids=[str(requested_device.pk)],
                            valid_device_ids=[]))
        self.assertEqual(response.status_code, 302)
        self.assertEqual(list(employee.upload_devices.all()), [upload_device])
        self.assertFalse(employee.valid_devices.exists())

    def test_t5_manager_allowlists_limit_personnel_and_device_uploads(self):
        manager_employee = Employee.objects.create(
            badge_no='T5-MANAGER-SCOPE', first_name='مدیر', last_name='دامنه',
            program_user=True)
        allowed_employee = Employee.objects.create(
            badge_no='T5-MANAGER-ALLOWED', first_name='مجاز', last_name='پرسنل')
        outside_employee = Employee.objects.create(
            badge_no='T5-MANAGER-OUTSIDE', first_name='خارج', last_name='دامنه')
        allowed_device = Device.objects.create(
            name='دستگاه مجاز مدیر', serial='T5-MANAGER-DEVICE-OK')
        outside_device = Device.objects.create(
            name='دستگاه خارج از دامنه', serial='T5-MANAGER-DEVICE-NO')
        allowed_employee.upload_devices.add(allowed_device, outside_device)

        role = Role.objects.create(name='مدیر محدود', code='t5-manager-scoped')
        for module, action in (
                ('org', 'view'), ('org', 'manage'), ('device', 'manage'),
                ('attendance', 'manage')):
            role.permissions.add(Permission.objects.get_or_create(
                module=module, action=action)[0])
        for module in ('org', 'device', 'attendance'):
            RoleDataScope.objects.create(role=role, module=module, scope='all')
        actor = get_user_model().objects.create_user(
            username='t5-manager-scoped', password='test-password',
            employee=manager_employee)
        UserRole.objects.create(user=actor, role=role)
        profile = ManagerProfile.objects.create(employee=manager_employee)
        profile.allowed_users.add(allowed_employee)
        profile.allowed_devices.add(allowed_device)
        self.client.force_login(actor)

        response = self.client.get(reverse(
            'org:userinfo_edit', args=[allowed_employee.pk]))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            list(response.context['devices']), [allowed_device])
        self.assertEqual(self.client.get(reverse(
            'org:userinfo_edit', args=[outside_employee.pk])).status_code, 404)

        response = self.client.post(
            reverse('org:userinfo_edit', args=[allowed_employee.pk]),
            self._post_data(
                badge_no=allowed_employee.badge_no,
                upload_device_ids=[str(outside_device.pk)],
                valid_device_ids=[]))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'فهرست دستگاه‌های انتخاب‌شده معتبر نیست')
        response = self.client.post(
            reverse('org:userinfo_edit', args=[allowed_employee.pk]),
            self._post_data(
                badge_no=allowed_employee.badge_no,
                upload_device_ids=[str(allowed_device.pk)],
                valid_device_ids=[]))
        self.assertEqual(response.status_code, 302)
        allowed_employee.refresh_from_db()
        # Existing out-of-scope assignments are preserved, but cannot be added
        # or operated on by this manager.
        self.assertEqual(set(allowed_employee.upload_devices.all()),
                         {allowed_device, outside_device})

        self.client.post(
            reverse('org:userinfo_edit', args=[allowed_employee.pk]),
            {'action': 'queue_device_upload'})
        self.assertTrue(DeviceUserSync.objects.filter(
            device=allowed_device, employee=allowed_employee,
            direction=DeviceUserSync.Direction.UP).exists())
        self.assertFalse(DeviceUserSync.objects.filter(
            device=outside_device, employee=allowed_employee,
            direction=DeviceUserSync.Direction.UP).exists())

    def test_t5_device_user_operations_obey_manager_allowlists(self):
        manager_employee = Employee.objects.create(
            badge_no='T5-DEVICE-MANAGER', first_name='مدیر', last_name='دستگاه',
            program_user=True)
        allowed_employee = Employee.objects.create(
            badge_no='T5-DEVICE-ALLOWED', first_name='مجاز', last_name='ارسال',
            device_enable=True)
        outside_employee = Employee.objects.create(
            badge_no='T5-DEVICE-OUTSIDE', first_name='خارج', last_name='ارسال',
            device_enable=True)
        allowed_device = Device.objects.create(
            name='مجاز عملیات کاربر', serial='T5-DEVICE-USER-OK')
        outside_device = Device.objects.create(
            name='خارج عملیات کاربر', serial='T5-DEVICE-USER-NO')
        role = Role.objects.create(name='مدیر عملیات محدود',
                                   code='t5-device-manager-scope')
        for action in ('view', 'manage'):
            role.permissions.add(Permission.objects.get_or_create(
                module='device', action=action)[0])
        for module in ('device', 'attendance'):
            RoleDataScope.objects.create(role=role, module=module, scope='all')
        actor = get_user_model().objects.create_user(
            username='t5-device-manager', password='test-password',
            employee=manager_employee)
        UserRole.objects.create(user=actor, role=role)
        profile = ManagerProfile.objects.create(employee=manager_employee)
        profile.allowed_users.add(allowed_employee)
        profile.allowed_devices.add(allowed_device)
        self.client.force_login(actor)
        url = reverse('device:device_users')

        page = self.client.get(url)
        self.assertEqual(list(page.context['employees']), [allowed_employee])
        self.assertEqual(list(page.context['devices']), [allowed_device])
        denied = self.client.post(url, {
            'device_id': outside_device.pk, 'action': 'upload',
        })
        self.assertEqual(denied.status_code, 302)
        self.assertFalse(DeviceUserSync.objects.filter(
            device=outside_device, employee=allowed_employee).exists())
        self.assertFalse(DeviceUserSync.objects.filter(
            device=allowed_device, employee=outside_employee).exists())

        response = self.client.post(url, {
            'device_id': allowed_device.pk, 'action': 'upload',
        })
        self.assertEqual(response.status_code, 302)
        self.assertTrue(DeviceUserSync.objects.filter(
            device=allowed_device, employee=allowed_employee,
            direction=DeviceUserSync.Direction.UP,
            state=DeviceUserSync.State.PENDING).exists())

    def test_userinfo_uploads_private_normalized_photo_and_allows_removal(self):
        with tempfile.TemporaryDirectory() as media_root, override_settings(
                MEDIA_ROOT=media_root):
            image_buffer = BytesIO()
            Image.new('RGB', (24, 18), color='navy').save(image_buffer, format='PNG')
            upload = SimpleUploadedFile(
                'portrait.png', image_buffer.getvalue(), content_type='image/png')
            response = self.client.post(reverse('org:userinfo'), {
                **self._post_data(), 'photo': upload,
            })
            self.assertEqual(response.status_code, 302)
            employee = Employee.objects.get(badge_no='T5-9001')
            self.assertTrue(employee.photo.startswith('employee_photos/'))
            self.assertTrue(default_storage.exists(employee.photo))
            photo_response = self.client.get(reverse(
                'org:employee_photo', args=[employee.pk]))
            self.assertEqual(photo_response.status_code, 200)
            self.assertEqual(photo_response['Content-Type'], 'image/jpeg')
            self.assertIn('private', photo_response['Cache-Control'])
            photo_name = employee.photo

            response = self.client.post(
                reverse('org:userinfo_edit', args=[employee.pk]),
                {**self._post_data(badge_no=employee.badge_no), 'remove_photo': 'on'})
            self.assertEqual(response.status_code, 302)
            employee.refresh_from_db()
            self.assertEqual(employee.photo, '')
            self.assertFalse(default_storage.exists(photo_name))

    def test_userinfo_rejects_non_image_photo_upload(self):
        upload = SimpleUploadedFile(
            'fake.png', b'not an image', content_type='image/png')
        response = self.client.post(reverse('org:userinfo'), {
            **self._post_data(), 'photo': upload,
        })
        self.assertEqual(response.status_code, 200)
        self.assertFalse(Employee.objects.filter(badge_no='T5-9001').exists())
        self.assertContains(response, 'تصویر معتبر نیست')

    def test_userinfo_saves_jalali_access_validity_and_rejects_reversed_range(self):
        response = self.client.post(reverse('org:userinfo'), self._post_data(
            valid_from='on', valid_from_date='۱۴۰۵/۰۲/۰۱',
            valid_from_time='08:30', valid_to='on',
            valid_to_date='1405-02-10', valid_to_time='17:00'))
        self.assertEqual(response.status_code, 302)
        employee = Employee.objects.get(badge_no='T5-9001')
        self.assertEqual(timezone.localtime(employee.valid_from).date(),
                         jdatetime.date(1405, 2, 1).togregorian())
        self.assertEqual(timezone.localtime(employee.valid_from).time(), datetime.time(8, 30))
        self.assertEqual(timezone.localtime(employee.valid_to).date(),
                         jdatetime.date(1405, 2, 10).togregorian())

        edit_url = reverse('org:userinfo_edit', args=[employee.pk])
        response = self.client.get(edit_url)
        self.assertEqual(response.context['valid_from_values'], {
            'date': '1405-02-01', 'time': '08:30'})

        response = self.client.post(edit_url, self._post_data(
            valid_from='on', valid_from_date='1405-03-01',
            valid_from_time='09:00', valid_to='on',
            valid_to_date='1405-02-28', valid_to_time='17:00'))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'پایان اعتبار نمی‌تواند قبل از شروع آن باشد.')
        employee.refresh_from_db()
        self.assertEqual(timezone.localtime(employee.valid_from).date(),
                         jdatetime.date(1405, 2, 1).togregorian())

    def test_userinfo_accepts_iso_gregorian_date_and_shows_jalali_edit_value(self):
        response = self.client.post(reverse('org:userinfo'), self._post_data(
            birthday='2026-10-22'))
        self.assertEqual(response.status_code, 302)
        employee = Employee.objects.get(badge_no='T5-9001')
        self.assertEqual(employee.birthday.isoformat(), '2026-10-22')

        response = self.client.get(reverse('org:userinfo_edit', args=[employee.pk]))
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['date_values']['birthday'],
                         jdatetime.date.fromgregorian(
                             date=employee.birthday).strftime('%Y-%m-%d'))

    def test_depts_chart_moves_only_selected_employees_in_and_out(self):
        department = Department.objects.create(name='پشتیبانی', code='T5-CHART')
        inside = Employee.objects.create(
            badge_no='T5-CHART-IN', first_name='داخل', last_name='واحد',
            department=department)
        free = Employee.objects.create(
            badge_no='T5-CHART-FREE', first_name='بدون', last_name='واحد')
        other_department = Department.objects.create(name='دیگر', code='T5-OTHER')
        other = Employee.objects.create(
            badge_no='T5-CHART-OTHER', first_name='واحد', last_name='دیگر',
            department=other_department)
        unrelated = Employee.objects.create(
            badge_no='T5-CHART-UNRELATED', first_name='واحد', last_name='سوم',
            department=other_department)

        response = self.client.get(reverse('org:org_chart'), {'dept_id': department.pk})
        self.assertEqual(response.status_code, 200)
        self.assertEqual([item.pk for item in response.context['in_dept']], [inside.pk])
        self.assertIn(free.pk, [item.pk for item in response.context['outside_people']])
        self.assertIn(other.pk, [item.pk for item in response.context['outside_people']])

        response = self.client.post(reverse('org:org_chart'), {
            'action': 'employ', 'dept_id': str(department.pk),
            'badge_numbers': [free.badge_no],
        })
        self.assertEqual(response.status_code, 302)
        free.refresh_from_db()
        self.assertEqual(free.department_id, department.pk)

        response = self.client.post(reverse('org:org_chart'), {
            'action': 'employ', 'dept_id': str(department.pk),
            'badge_numbers': [other.badge_no],
        })
        self.assertEqual(response.status_code, 302)
        other.refresh_from_db()
        self.assertEqual(other.department_id, department.pk)

        response = self.client.post(reverse('org:org_chart'), {
            'action': 'fire', 'dept_id': str(department.pk),
            'badge_numbers': [inside.badge_no],
        })
        self.assertEqual(response.status_code, 302)
        inside.refresh_from_db()
        self.assertIsNone(inside.department_id)
        other.refresh_from_db()
        self.assertEqual(other.department_id, department.pk)

        rejected = self.client.post(reverse('org:org_chart'), {
            'action': 'fire', 'dept_id': str(department.pk),
            'badge_numbers': [unrelated.badge_no],
        })
        self.assertEqual(rejected.status_code, 302)
        unrelated.refresh_from_db()
        self.assertEqual(unrelated.department_id, other_department.pk)

    def test_t5_deputy_assignment_is_title_based_and_separate_from_workflow_deputy(self):
        principal = Position.objects.create(title='مدیر منابع انسانی')
        deputy = Position.objects.create(title='معاون منابع انسانی')
        another = Position.objects.create(title='سرپرست اداری')

        response = self.client.get(reverse('org:deputies'), {
            'title_id': principal.pk,
        })
        self.assertEqual(response.status_code, 200)
        response = self.client.post(reverse('org:deputies'), {
            'title_id': str(principal.pk),
            'deputy_title_ids': [str(deputy.pk), str(another.pk)],
        })
        self.assertEqual(response.status_code, 302)
        self.assertEqual(set(principal.deputy_titles.all()), {deputy, another})

        response = self.client.post(reverse('org:deputies'), {
            'title_id': str(principal.pk),
            'deputy_title_ids': [str(principal.pk)],
        })
        self.assertEqual(response.status_code, 302)
        self.assertEqual(set(principal.deputy_titles.all()), {deputy, another})
        self.assertEqual(reverse('org:deputies'), '/org/deputies/')
        self.assertEqual(reverse('attendance:deputy'), '/attendance/deputy/')

    def test_title_tree_fields_guard_access_and_cycle_protection(self):
        department = Department.objects.create(name='مالی', code='T5-TITLE-DEPT')
        response = self.client.post(reverse('org:titles'), {
            'action': 'add', 'title': 'مدیر واحد', 'display_name': 'مدیر مالی',
            'department_id': str(department.pk), 'email_address': 'boss@example.test',
            'address': 'ساختمان الف', 'duties': 'مدیریت واحد',
            'is_manager': 'on', 'is_guard': 'on',
            'guard_departments': [str(department.pk)],
        })
        self.assertEqual(response.status_code, 302)
        parent = Position.objects.get(title='مدیر واحد')
        self.assertTrue(parent.guard_departments.filter(pk=department.pk).exists())
        self.assertEqual(parent.display_name, 'مدیر مالی')

        response = self.client.post(reverse('org:titles'), {
            'action': 'add', 'title': 'کارشناس', 'display_name': 'کارشناس مالی',
            'parent_id': str(parent.pk), 'department_id': str(department.pk),
        })
        self.assertEqual(response.status_code, 302)
        child = Position.objects.get(title='کارشناس')
        response = self.client.get(reverse('org:titles'), {'edit': child.pk})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['selected'].parent_id, parent.pk)

        response = self.client.post(reverse('org:titles'), {
            'action': 'edit', 'position_id': str(parent.pk), 'title': parent.title,
            'display_name': parent.display_name, 'parent_id': str(child.pk),
        })
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'باعث چرخه در درخت می‌شود')
        parent.refresh_from_db()
        self.assertIsNone(parent.parent_id)

        response = self.client.post(reverse('org:titles'), {
            'action': 'delete', 'position_id': str(parent.pk),
        })
        self.assertEqual(response.status_code, 302)
        self.assertTrue(Position.objects.filter(pk=parent.pk).exists())

    def test_department_delete_blocks_references_and_allows_empty_unit(self):
        parent = Department.objects.create(name='ستاد', code='D-HQ')
        child = Department.objects.create(
            name='مالی', code='D-FIN', parent=parent)
        assigned = Department.objects.create(name='فروش', code='D-SALES')
        Employee.objects.create(
            badge_no='T5-6001', first_name='نام', last_name='خانواده',
            department=assigned)

        response = self.client.post(
            reverse('org:department_edit', args=[parent.pk]), {'action': 'delete'})
        self.assertEqual(response.status_code, 302)
        self.assertTrue(Department.objects.filter(pk=parent.pk).exists())

        response = self.client.post(reverse('org:department_admin'), {
            'action': 'delete_all', 'ids': [str(child.pk), str(assigned.pk)],
        })
        self.assertEqual(response.status_code, 302)
        self.assertTrue(Department.objects.filter(pk=child.pk).exists())
        self.assertTrue(Department.objects.filter(pk=assigned.pk).exists())

        empty = Department.objects.create(name='پشتیبانی', code='D-SUPPORT')
        response = self.client.post(
            reverse('org:department_edit', args=[empty.pk]), {'action': 'delete'})
        self.assertEqual(response.status_code, 302)
        self.assertFalse(Department.objects.filter(pk=empty.pk).exists())

    def test_custom_property_crud_preserves_personnel_values_on_deactivation(self):
        response = self.client.post(reverse('org:custom_fields'), {
            'action': 'add', 'title': 'ناحیه کاری', 'field_type': 'dropdown',
            'options': 'شمال|جنوب|شمال', 'is_search_filter': 'on',
        })
        self.assertEqual(response.status_code, 302)
        field = EmployeeFieldDef.objects.get(title='ناحیه کاری')
        self.assertEqual(field.options, 'شمال|جنوب')
        self.assertTrue(field.is_search_filter)

        employee = Employee.objects.create(
            badge_no='T5-7001', first_name='نام', last_name='خانواده')
        EmployeeFieldVal.objects.create(
            employee=employee, field_def=field, value='شمال')
        response = self.client.get(reverse('org:custom_fields'), {'edit': field.pk})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['selected'].pk, field.pk)

        response = self.client.post(reverse('org:custom_fields'), {
            'action': 'edit', 'field_id': str(field.pk), 'title': 'بخش کاری',
            'field_type': 'number', 'options': 'ignored',
        })
        self.assertEqual(response.status_code, 302)
        field.refresh_from_db()
        self.assertEqual(field.title, 'بخش کاری')
        self.assertEqual(field.options, '')
        self.assertFalse(field.is_search_filter)

        response = self.client.post(reverse('org:custom_fields'), {
            'action': 'delete', 'field_id': str(field.pk),
        })
        self.assertEqual(response.status_code, 302)
        field.refresh_from_db()
        self.assertFalse(field.is_active)
        self.assertEqual(EmployeeFieldVal.objects.get(
            employee=employee, field_def=field).value, 'شمال')

    def test_custom_dropdown_rejects_empty_option_list(self):
        response = self.client.post(reverse('org:custom_fields'), {
            'action': 'add', 'title': 'رده', 'field_type': 'dropdown',
            'options': ' |  | ',
        })
        self.assertEqual(response.status_code, 302)
        self.assertFalse(EmployeeFieldDef.objects.filter(title='رده').exists())

    def test_emp_type_crud_persists_allowed_leave_type_selection(self):
        leave_a = LeaveType.objects.create(name='مرخصی سالانه', code='t5-annual')
        leave_b = LeaveType.objects.create(name='ماموریت', code='t5-duty')
        response = self.client.post(reverse('org:emp_types'), {
            'action': 'add', 'name': 'رسمی', 'code': 'official-t5',
            'valid_leave_types': [str(leave_a.pk), str(leave_b.pk)],
        })
        self.assertEqual(response.status_code, 302)
        item = EmpType.objects.get(code='official-t5')
        self.assertEqual(set(item.valid_leave_types.all()), {leave_a, leave_b})

        response = self.client.get(reverse('org:emp_types'), {'edit': item.pk})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['selected_leave_ids'],
                         {leave_a.pk, leave_b.pk})

        response = self.client.post(reverse('org:emp_types'), {
            'action': 'edit', 'emp_type_id': str(item.pk),
            'name': 'کارشناس رسمی', 'code': 'official-t5',
            'valid_leave_types': [str(leave_a.pk)],
        })
        self.assertEqual(response.status_code, 302)
        item.refresh_from_db()
        self.assertEqual(item.name, 'کارشناس رسمی')
        self.assertEqual(list(item.valid_leave_types.values_list('pk', flat=True)),
                         [leave_a.pk])

    def test_emp_type_rejects_bad_ids_and_protects_assigned_types(self):
        leave = LeaveType.objects.create(name='مرخصی آزمون', code='t5-test')
        item = EmpType.objects.create(name='پیمانی', code='contract-t5')
        item.valid_leave_types.add(leave)
        employee = Employee.objects.create(
            badge_no='T5-8001', first_name='نام', last_name='خانواده',
            emp_type=item)
        response = self.client.post(reverse('org:emp_types'), {
            'action': 'edit', 'emp_type_id': str(item.pk), 'name': 'پیمانی',
            'code': item.code, 'valid_leave_types': ['not-an-id'],
        })
        self.assertEqual(response.status_code, 302)
        item.refresh_from_db()
        self.assertEqual(list(item.valid_leave_types.values_list('pk', flat=True)),
                         [leave.pk])

        response = self.client.post(reverse('org:emp_types'), {
            'action': 'delete', 'emp_type_id': str(item.pk),
        })
        self.assertEqual(response.status_code, 302)
        self.assertTrue(EmpType.objects.filter(pk=item.pk).exists())
        self.assertTrue(employee.emp_type_id)

    def test_certificate_catalog_crud_links_personnel_and_blocks_used_delete(self):
        response = self.client.post(reverse('org:certificates'), {
            'action': 'add', 'name': 'کارشناسی ارشد',
        })
        self.assertEqual(response.status_code, 302)
        certificate = Certificate.objects.get(name='کارشناسی ارشد')

        response = self.client.post(reverse('org:userinfo'), self._post_data(
            certificate_id=str(certificate.pk)))
        self.assertEqual(response.status_code, 302)
        employee = Employee.objects.get(badge_no='T5-9001')
        self.assertEqual(employee.certificate_ref_id, certificate.pk)
        self.assertEqual(employee.certificate, certificate.name)

        response = self.client.post(reverse('org:certificates'), {
            'action': 'edit', 'certificate_id': str(certificate.pk),
            'name': 'کارشناسی ارشد و بالاتر',
        })
        self.assertEqual(response.status_code, 302)
        employee.refresh_from_db()
        self.assertEqual(employee.certificate, 'کارشناسی ارشد و بالاتر')
        self.assertEqual(employee.certificate_ref.name, 'کارشناسی ارشد و بالاتر')

        response = self.client.post(reverse('org:certificates'), {
            'action': 'delete', 'certificate_id': str(certificate.pk),
        })
        self.assertEqual(response.status_code, 302)
        self.assertTrue(Certificate.objects.filter(pk=certificate.pk).exists())
        self.assertTrue(any('به پروندهٔ پرسنلی' in str(message)
                            for message in response.wsgi_request._messages))

    def test_legacy_personnel_certificate_can_be_kept_and_invalid_ids_are_rejected(self):
        employee = Employee.objects.create(
            badge_no='T5-9002', first_name='نام', last_name='خانواده',
            certificate='مدرک قدیمی')
        response = self.client.post(
            reverse('org:userinfo_edit', args=[employee.pk]), self._post_data(
                badge_no=employee.badge_no, certificate_id='legacy',
                legacy_certificate='مدرک قدیمی'))
        self.assertEqual(response.status_code, 302)
        employee.refresh_from_db()
        self.assertEqual(employee.certificate, 'مدرک قدیمی')
        self.assertIsNone(employee.certificate_ref_id)

        response = self.client.post(reverse('org:userinfo'), self._post_data(
            badge_no='T5-9003', certificate_id='not-an-id'))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'مدرک تحصیلی انتخاب‌شده معتبر نیست.')
        self.assertFalse(Employee.objects.filter(badge_no='T5-9003').exists())

    def test_reports_use_catalog_and_retain_legacy_fallback(self):
        AppSetting.objects.create(key='certificates', value='دیپلم|کاردانی')
        self.assertEqual(_certificate_choices(), ['دیپلم', 'کاردانی'])
        Certificate.objects.create(name='کارشناسی')
        self.assertEqual(_certificate_choices(), ['کارشناسی'])

    def test_invalid_personnel_date_is_reported_without_saving(self):
        response = self.client.post(reverse('org:userinfo'), self._post_data(
            birthday='1404-12-31'))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'تاریخ واردشده معتبر نیست.')
        self.assertEqual(response.context['date_values']['birthday'], '1404-12-31')
        self.assertFalse(Employee.objects.filter(badge_no='T5-9001').exists())

    def test_invalid_device_number_is_reported_without_server_error(self):
        response = self.client.post(reverse('org:userinfo'), self._post_data(
            device_group='not-a-number'))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'مقادیر عددی دستگاه معتبر نیستند.')
        self.assertFalse(Employee.objects.filter(badge_no='T5-9001').exists())
