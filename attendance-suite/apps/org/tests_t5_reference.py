"""Offline parity and regression checks for T5Web T5-02 personnel forms."""
import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.core.models import AppSetting
from apps.reports.views import _certificate_choices
from .models import Certificate, Employee


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
