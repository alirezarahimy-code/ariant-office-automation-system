from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import LeaveType, PreCard
from apps.core.models import Permission, Role, RoleDataScope, UserRole
from apps.device.models import Device
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
        self.assertEqual(self.client.get(url).status_code, 403)
        self.assertNotContains(
            self.client.get(reverse('attendance:dashboard')),
            reverse('attendance:pre_cards'))

        RoleDataScope.objects.filter(role=role, module='attendance').update(scope='all')
        response = self.client.get(url)
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'پیش‌کارت')
        self.assertContains(response, reverse('attendance:pre_cards'))
