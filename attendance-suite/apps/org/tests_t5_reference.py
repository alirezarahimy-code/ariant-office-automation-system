"""Offline parity and regression checks for T5Web T5-02 personnel forms."""
import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import LeaveType
from apps.core.models import AppSetting
from apps.reports.views import _certificate_choices
from .models import (Certificate, Department, Employee, EmployeeFieldDef,
                    EmployeeFieldVal, EmpType)


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
        }
        data.update(overrides)
        return data

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
