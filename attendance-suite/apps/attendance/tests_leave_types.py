from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import LeaveType, PreCard, Request
from apps.attendance.services import create_request, employee_leave_types
from apps.device.models import Device
from apps.org.models import Employee, EmpType

User = get_user_model()


class LeaveTypeReferenceFormTests(TestCase):
    def setUp(self):
        self.client.force_login(User.objects.create_superuser('lt-admin', password='x'))
        self.url = reverse('attendance:leave_types')

    def payload(self, **overrides):
        data = {
            'action': 'save', 'leave_type_id': '-1',
            'LeaveName': 'مرخصی ساعتی', 'Symbol': 'م‌س',
            'LeaveType1': LeaveType.LeaveKind.LEAVE, 'IsHourly': 'true',
            'Color': '#2a74cc', 'StateKey': '7',
            'MaxHoursInADay': '06:00', 'MaxHoursInAMonth': '12:30',
            'MaxDailyInYear': '30', 'MaxDailyInMonth': '2.5',
            'EachXHoursADay': '08:00', 'GetInType': LeaveType.GetInType.MONTH_FIRST,
            'ForNextYear': '9', 'MaxMinus': '0',
        }
        data.update(overrides)
        return data

    def test_create_and_edit_full_reference_configuration(self):
        LeaveType.objects.create(name='نوع قبلی', code='previous')
        response = self.client.get(self.url)
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'StateKey')
        self.assertContains(response, 'delete_selected')

        response = self.client.post(self.url, self.payload())
        self.assertEqual(response.status_code, 302)
        row = LeaveType.objects.get(symbol='م‌س')
        self.assertEqual(row.code, 'م‌س')
        self.assertEqual(row.state_key, 7)
        self.assertEqual(row.max_hours_in_a_day_seconds, 6 * 3600)
        self.assertEqual(row.max_hours_in_a_month_seconds, 12 * 3600 + 30 * 60)

        daily_payload = self.payload(
            leave_type_id=str(row.pk), LeaveName='مرخصی روزانه',
            IsHourly='false', MaxDailyInYear='24', MaxDailyInMonth='1.5')
        # The reference form disables and omits fields unrelated to daily leave.
        for disabled_field in ('StateKey', 'MaxHoursInADay', 'MaxHoursInAMonth'):
            daily_payload.pop(disabled_field)
        response = self.client.post(self.url, daily_payload)
        self.assertEqual(response.status_code, 302)
        row.refresh_from_db()
        self.assertFalse(row.is_hourly)
        self.assertEqual(row.state_key, 7)
        self.assertEqual(row.max_hours_in_a_day_seconds, 6 * 3600)
        self.assertEqual(str(row.max_daily_in_year), '24.00')
        self.assertEqual(str(row.max_daily_in_month), '1.50')

    def test_invalid_input_does_not_create_or_change_rows(self):
        response = self.client.post(self.url, self.payload(
            MaxHoursInADay='six hours', StateKey='7'))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'زمان باید به صورت ساعت:دقیقه')
        self.assertNotContains(response, 'id="leaveTypePanel" class="card" style="display:none"')
        self.assertFalse(LeaveType.objects.exists())

        row = LeaveType.objects.create(name='قدیمی', code='old')
        response = self.client.post(self.url, self.payload(
            leave_type_id=str(row.pk), Symbol='نماد-جدید',
            MaxHoursInADay='99:99'))
        self.assertEqual(response.status_code, 200)
        row.refresh_from_db()
        self.assertEqual(row.name, 'قدیمی')

    def test_bulk_delete_is_all_or_nothing_and_preserves_referenced_types(self):
        first = LeaveType.objects.create(name='اول', code='first')
        second = LeaveType.objects.create(name='دوم', code='second')
        response = self.client.post(self.url, {
            'action': 'delete_selected', 'selected_ids': [str(first.pk), str(second.pk)],
        })
        self.assertEqual(response.status_code, 302)
        self.assertFalse(LeaveType.objects.filter(pk__in=[first.pk, second.pk]).exists())

        safe = LeaveType.objects.create(name='آزاد', code='safe')
        protected = LeaveType.objects.create(name='مصرف‌شده', code='protected')
        device = Device.objects.create(name='دستگاه گروهی', serial='LT-BULK-DEVICE')
        PreCard.objects.create(
            card_badge_number=909091, valid_secs=30,
            leave_type=protected, device=device)
        response = self.client.post(self.url, {
            'action': 'delete_selected',
            'selected_ids': [str(safe.pk), str(protected.pk)],
        })
        self.assertEqual(response.status_code, 302)
        self.assertTrue(LeaveType.objects.filter(pk=safe.pk).exists())
        self.assertTrue(LeaveType.objects.filter(pk=protected.pk).exists())

    def test_pre_card_reference_prevents_deleting_leave_type(self):
        leave_type = LeaveType.objects.create(name='حالت', code='state')
        device = Device.objects.create(name='دستگاه', serial='LT-DEVICE')
        PreCard.objects.create(
            card_badge_number=909090, valid_secs=30,
            leave_type=leave_type, device=device)
        response = self.client.post(self.url, {
            'action': 'delete', 'leave_type_id': str(leave_type.pk),
        })
        self.assertEqual(response.status_code, 302)
        self.assertTrue(LeaveType.objects.filter(pk=leave_type.pk).exists())

    def test_employment_type_leave_allowlist_is_enforced_on_request_write(self):
        allowed = LeaveType.objects.create(name='مجاز', code='emp-allowed')
        denied = LeaveType.objects.create(name='نامجاز', code='emp-denied')
        emp_type = EmpType.objects.create(name='قراردادی', code='contract')
        emp_type.valid_leave_types.add(allowed)
        employee = Employee.objects.create(
            badge_no='EMP-LEAVE-1', first_name='کارمند', last_name='قراردادی',
            emp_type=emp_type)

        self.assertEqual(list(employee_leave_types(employee)), [allowed])
        with self.assertRaisesRegex(ValueError, 'برای نوع استخدام'):
            create_request(Request.Kind.LEAVE_DAILY, employee, leave_type=denied)
        req = create_request(Request.Kind.LEAVE_DAILY, employee, leave_type=allowed)
        self.assertEqual(req.leave_type_id, allowed.pk)

    def test_empty_employment_type_leave_allowlist_requires_configuration(self):
        unconfigured = LeaveType.objects.create(name='قدیمی', code='emp-legacy')
        emp_type = EmpType.objects.create(name='دائمی', code='permanent')
        employee = Employee.objects.create(
            badge_no='EMP-LEAVE-2', first_name='کارمند', last_name='دائمی',
            emp_type=emp_type)
        self.assertFalse(employee_leave_types(employee).exists())
        with self.assertRaisesRegex(ValueError, 'تنظیم نشده است'):
            create_request(Request.Kind.LEAVE_DAILY, employee,
                           leave_type=unconfigured)
