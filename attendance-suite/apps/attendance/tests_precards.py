import datetime as gregorian

import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse
from django.utils import timezone

from apps.attendance.models import LeaveType, PreCard, Punch
from apps.attendance.services_precards import apply_pre_cards
from apps.core.models import Permission, Role, RoleDataScope, UserRole
from apps.device.models import Device, RawRecord
from apps.org.models import Employee

User = get_user_model()


class PreCardWorkflowTests(TestCase):
    def setUp(self):
        self.leave_type = LeaveType.objects.create(
            name='مرخصی آزمایشی', code='prec-test', is_active=True)
        self.device = Device.objects.create(
            name='دستگاه آزمایشی', serial='PRECARD-1')
        self.admin = User.objects.create_superuser('prec-admin', password='x')
        self.client.force_login(self.admin)

    def test_create_edit_and_delete_pre_card_configuration(self):
        url = reverse('attendance:pre_cards')
        response = self.client.post(url, {
            'CardBadgeNumber': '123456', 'ValidSecs': '1800',
            'LeaveTypeID': str(self.leave_type.pk),
            'MachineNumber': str(self.device.pk), 'OnlyFirst': 'true',
            'action': 'save',
        })
        self.assertEqual(response.status_code, 302)
        item = PreCard.objects.get(card_badge_number=123456)
        self.assertEqual(item.valid_secs, 1800)
        self.assertTrue(item.only_first)

        response = self.client.post(url, {
            'precard_id': str(item.pk), 'CardBadgeNumber': '123456',
            'ValidSecs': '3600', 'LeaveTypeID': str(self.leave_type.pk),
            'MachineNumber': str(self.device.pk), 'action': 'save',
        })
        self.assertEqual(response.status_code, 302)
        item.refresh_from_db()
        self.assertEqual(item.valid_secs, 3600)
        self.assertFalse(item.only_first)

        response = self.client.post(url, {
            'precard_id': str(item.pk), 'action': 'delete',
        })
        self.assertEqual(response.status_code, 302)
        self.assertFalse(PreCard.objects.exists())

    def test_invalid_duplicate_or_unknown_references_do_not_write(self):
        PreCard.objects.create(
            card_badge_number=123456, valid_secs=60,
            leave_type=self.leave_type, device=self.device)
        url = reverse('attendance:pre_cards')
        bad_inputs = (
            {'CardBadgeNumber': '123456', 'ValidSecs': '60',
             'LeaveTypeID': str(self.leave_type.pk),
             'MachineNumber': str(self.device.pk)},
            {'CardBadgeNumber': '123457', 'ValidSecs': '-1',
             'LeaveTypeID': str(self.leave_type.pk),
             'MachineNumber': str(self.device.pk)},
            {'CardBadgeNumber': '123458', 'ValidSecs': '60',
             'LeaveTypeID': '99999', 'MachineNumber': str(self.device.pk)},
            {'CardBadgeNumber': '123459', 'ValidSecs': '60',
             'LeaveTypeID': str(self.leave_type.pk), 'MachineNumber': 'bad'},
        )
        for data in bad_inputs:
            response = self.client.post(url, {**data, 'action': 'save'})
            self.assertEqual(response.status_code, 302)
        self.assertEqual(PreCard.objects.count(), 1)

    def test_apply_interval_changes_only_same_device_records_inside_window(self):
        from apps.org.models import Employee
        employee = Employee.objects.create(
            badge_no='888', first_name='الف', last_name='کارمند')
        day = gregorian.date(2026, 10, 21)
        def at(hour, minute, second):
            return timezone.make_aware(gregorian.datetime(
                day.year, day.month, day.day, hour, minute, second))

        PreCard.objects.create(
            card_badge_number=123456, valid_secs=60,
            leave_type=self.leave_type, device=self.device)
        seed = RawRecord.objects.create(
            device=self.device, pin='123456', punch_time=at(8, 0, 0))
        first = RawRecord.objects.create(
            device=self.device, pin=employee.badge_no, punch_time=at(8, 0, 10))
        second = RawRecord.objects.create(
            device=self.device, pin='foreign', punch_time=at(8, 0, 20))
        outside = RawRecord.objects.create(
            device=self.device, pin='late', punch_time=at(8, 1, 1))
        other_device = Device.objects.create(name='دیگر', serial='PRECARD-2')
        other = RawRecord.objects.create(
            device=other_device, pin='other-device', punch_time=at(8, 0, 15))
        punch = Punch.objects.create(
            employee=employee, device=self.device, date=day,
            time=gregorian.time(8, 0, 10), direction=Punch.Direction.IN,
            source=Punch.Source.DEVICE)

        result = apply_pre_cards(day, day)
        self.assertEqual(result['records_matched'], 2)
        self.assertEqual(result['records_changed'], 2)
        seed.refresh_from_db()
        first.refresh_from_db()
        second.refresh_from_db()
        outside.refresh_from_db()
        other.refresh_from_db()
        punch.refresh_from_db()
        self.assertEqual(seed.check_type, 0)
        self.assertEqual(first.check_type, self.leave_type.state_key)
        self.assertEqual(second.check_type, self.leave_type.state_key)
        self.assertEqual(outside.check_type, 0)
        self.assertEqual(other.check_type, 0)
        self.assertEqual(punch.check_type, self.leave_type.state_key)

    def test_only_first_changes_one_candidate_and_monthly_route_runs(self):
        day = jdatetime.date(1405, 7, 1).togregorian()
        start = timezone.make_aware(gregorian.datetime(
            day.year, day.month, day.day, 8, 0, 0))
        first_time = start + gregorian.timedelta(seconds=5)
        second_time = start + gregorian.timedelta(seconds=6)
        PreCard.objects.create(
            card_badge_number=789, valid_secs=60,
            leave_type=self.leave_type, device=self.device, only_first=True)
        RawRecord.objects.create(device=self.device, pin='789', punch_time=start)
        first = RawRecord.objects.create(
            device=self.device, pin='first', punch_time=first_time)
        second = RawRecord.objects.create(
            device=self.device, pin='second', punch_time=second_time)

        response = self.client.post(reverse('attendance:pre_cards_apply'), {
            'isMonthly': 'true', 'year': '1405', 'month': '7',
        })
        self.assertEqual(response.status_code, 302)
        first.refresh_from_db()
        second.refresh_from_db()
        self.assertEqual(first.check_type, self.leave_type.state_key)
        self.assertEqual(second.check_type, 0)

    def test_requires_both_manage_permissions_and_global_scopes(self):
        user = User.objects.create_user('limited', password='x')
        role = Role.objects.create(name='مدیر محدود', code='prec-limited')
        role.permissions.add(
            Permission.objects.get_or_create(
                module='attendance', action='manage')[0],
            Permission.objects.get_or_create(
                module='device', action='manage')[0],
            Permission.objects.get_or_create(
                module='attendance', action='view')[0],
            Permission.objects.get_or_create(
                module='device', action='view')[0],
        )
        UserRole.objects.create(user=user, role=role)
        RoleDataScope.objects.create(role=role, module='attendance', scope='self')
        RoleDataScope.objects.create(role=role, module='device', scope='all')
        self.client.force_login(user)
        url = reverse('attendance:pre_cards')
        apply_url = reverse('attendance:pre_cards_apply')
        self.assertEqual(self.client.get(url).status_code, 403)
        self.assertEqual(self.client.get(apply_url).status_code, 403)
        self.assertNotContains(
            self.client.get(reverse('attendance:dashboard')),
            reverse('attendance:pre_cards'))

        RoleDataScope.objects.filter(role=role, module='attendance').update(scope='all')
        response = self.client.get(url)
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'پیش‌کارت')
        self.assertContains(response, reverse('attendance:pre_cards'))
