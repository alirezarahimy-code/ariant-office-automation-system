"""آزمون‌های پل دستگاه، انتقال مانده، نوع خروج، کارنامه."""
import datetime as gregorian
import json

from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import DailyRecord, ExitType, LeaveBalance, LeaveType
from apps.attendance.services import transfer_leave_balances
from apps.core.models import AppSetting
from apps.device.models import Device, RawRecord
from apps.device.services import convert_raw_to_punches, ingest_records
from apps.org.models import Employee

User = get_user_model()


class DeviceBridgeTests(TestCase):
    def setUp(self):
        self.device = Device.objects.create(name='ورودی', serial='SN-100')
        self.employee = Employee.objects.create(
            badge_no='1002', first_name='ا', last_name='ی')

    def test_ingest_idempotent(self):
        records = [{'pin': '1002', 'time': '2026-10-19 08:00:00'},
                   {'pin': '1002', 'time': '2026-10-19 17:00:00'}]
        r1 = ingest_records(self.device, records)
        self.assertEqual(r1['created'], 2)
        r2 = ingest_records(self.device, records)
        self.assertEqual(r2['created'], 0)
        self.assertEqual(r2['skipped'], 2)

    def test_convert_raw_to_punches(self):
        ingest_records(self.device, [
            {'pin': '1002', 'time': '2026-10-19 08:00:00'},
            {'pin': '1002', 'time': '2026-10-19 17:00:00'}])
        result = convert_raw_to_punches()
        self.assertEqual(result['converted'], 2)
        self.assertEqual(DailyRecord.objects.count(), 1)
        record = DailyRecord.objects.get()
        self.assertEqual(record.check_in, gregorian.time(8, 0))
        self.assertEqual(record.check_out, gregorian.time(17, 0))

    def test_convert_unknown_pin_is_error(self):
        ingest_records(self.device, [{'pin': '9999', 'time': '2026-10-19 08:00:00'}])
        result = convert_raw_to_punches()
        self.assertEqual(result['errors'], 1)
        self.assertEqual(RawRecord.objects.get().state, 'error')

    def test_api_token_required(self):
        resp = self.client.post(reverse('device:api_ingest'),
                                data=json.dumps({'serial': 'SN-100'}),
                                content_type='application/json')
        self.assertEqual(resp.status_code, 401)

    def test_api_ingest_ok(self):
        AppSetting.objects.create(key='device_api_token', value='secret-token')
        payload = {'serial': 'SN-100', 'convert': True,
                   'records': [{'pin': '1002', 'time': '2026-10-19 08:00:00'}]}
        resp = self.client.post(
            reverse('device:api_ingest'), data=json.dumps(payload),
            content_type='application/json',
            HTTP_X_DEVICE_TOKEN='secret-token')
        self.assertEqual(resp.status_code, 200)
        data = resp.json()
        self.assertTrue(data['ok'])
        self.assertEqual(data['created'], 1)
        self.assertEqual(data['converted'], 1)

    def test_api_works_with_csrf_enforced(self):
        """رگرسیون: POST واقعی HTTP (با CSRF فعال) باید ۲۰۰ بگیرد — معافیت CSRF اعمال شده."""
        from django.test import Client
        AppSetting.objects.create(key='device_api_token', value='secret-token')
        c = Client(enforce_csrf_checks=True)
        payload = {'serial': 'SN-100',
                   'records': [{'pin': '1002', 'time': '2026-10-19 08:00:00'}]}
        resp = c.post(reverse('device:api_ingest'), data=json.dumps(payload),
                      content_type='application/json',
                      HTTP_X_DEVICE_TOKEN='secret-token')
        self.assertEqual(resp.status_code, 200)
        self.assertTrue(resp.json()['ok'])

    def test_api_bad_payload(self):
        AppSetting.objects.create(key='device_api_token', value='t')
        resp = self.client.post(
            reverse('device:api_ingest'), data=json.dumps({'serial': 'NOPE'}),
            content_type='application/json', HTTP_X_DEVICE_TOKEN='t')
        self.assertEqual(resp.status_code, 400)


class LeaveTransferTests(TestCase):
    def setUp(self):
        self.employee = Employee.objects.create(
            badge_no='2001', first_name='م', last_name='ان')
        self.lt = LeaveType.objects.create(name='استحقاقی', code='annual',
                                           limit_per_year=30)

    def test_transfer_remaining(self):
        LeaveBalance.objects.create(
            employee=self.employee, leave_type=self.lt, jyear=1404,
            granted=30, used=22)  # مانده 8
        result = transfer_leave_balances(1404, 1405)
        self.assertEqual(result['transferred'], 1)
        target = LeaveBalance.objects.get(
            employee=self.employee, leave_type=self.lt, jyear=1405)
        self.assertEqual(float(target.granted), 8.0)
        self.assertEqual(float(target.used), 0.0)

    def test_transfer_view_requires_manage(self):
        user = User.objects.create_user('plain', password='x')
        self.client.force_login(user)
        resp = self.client.get(reverse('attendance:leave_transfer'))
        self.assertEqual(resp.status_code, 403)


class ParityTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_superuser('root', password='x')

    def test_exit_type_crud(self):
        self.client.force_login(self.user)
        self.client.post(reverse('attendance:exit_types'), {
            'name': 'مرخصی ساعتی استعلاجی', 'code': 'medical_exit'})
        self.assertEqual(ExitType.objects.count(), 1)
        resp = self.client.get(reverse('attendance:exit_types'))
        self.assertContains(resp, 'مرخصی ساعتی استعلاجی')

    def test_cardex_report(self):
        employee = Employee.objects.create(
            badge_no='3001', first_name='ک', last_name='ار')
        import jdatetime
        from apps.attendance.services import register_punch
        d = jdatetime.date(1404, 7, 6).togregorian()
        register_punch(employee, d, gregorian.time(8, 0), 'in')
        register_punch(employee, d, gregorian.time(16, 0), 'out')
        self.client.force_login(self.user)
        resp = self.client.get(reverse('reports:run_standard', args=['cardex']),
                               {'year': 1404, 'month': 7})
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'کارکرد')

    def test_visitors_and_cards_pages(self):
        self.client.force_login(self.user)
        self.assertEqual(self.client.get(reverse('device:visitors')).status_code,
                         200)
        self.assertEqual(self.client.get(reverse('device:cards')).status_code,
                         200)
        self.client.post(reverse('device:cards'), {'card_no': 'C-1'})
        self.client.post(reverse('device:visitors'), {'name': 'مهمان'})
        self.assertEqual(self.client.get(
            reverse('device:cards')).status_code, 200)
