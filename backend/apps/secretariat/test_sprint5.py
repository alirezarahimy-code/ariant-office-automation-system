"""آزمون اسپرینت ۵: مهلت‌ها، جست‌وجوی پیشرفته، جانشینی، گروه‌های پیش‌فرض، بار سبک."""
from datetime import date, timedelta

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Group
from django.core.management import call_command
from django.test import TestCase
from django.test.utils import CaptureQueriesContext
from django.urls import reverse
from django.db import connection

from apps.core.models import Delegation, Notification
from apps.secretariat.models import Letter, Office, Referral
from apps.secretariat.services import register_letter, visible_letters
from apps.secretariat.tests import give, make_office

User = get_user_model()


def jdays(delta: int) -> jdatetime.date:
    return jdatetime.date.fromgregorian(date=date.today() + timedelta(days=delta))


class DeadlineCommandTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.user = give(User.objects.create_user('u', password='x'), 'view_own_letter')
        self.overdue_letter = register_letter(
            self.office, 'incoming', 'نامه متأخر', created_by=self.user, deadline=jdays(-5))
        self.near_letter = register_letter(
            self.office, 'incoming', 'نامه نزدیک مهلت', created_by=self.user, deadline=jdays(2))
        self.far_letter = register_letter(
            self.office, 'incoming', 'نامه آینده', created_by=self.user, deadline=jdays(30))

    def test_overdue_and_near_notify_once(self):
        call_command('check_deadlines')
        titles = list(Notification.objects.filter(user=self.user).values_list('title', flat=True))
        self.assertTrue(any('از مهلت گذشت' in t for t in titles))
        self.assertTrue(any('به مهلت نزدیک است' in t for t in titles))
        self.assertFalse(any('نامه آینده' in t for t in titles))
        # اجرای دوم تکرار نخوانده نمی‌سازد
        call_command('check_deadlines')
        self.assertEqual(Notification.objects.filter(user=self.user).count(), len(titles))

    def test_pending_referral_recipient_notified(self):
        expert = User.objects.create_user('expert', password='x')
        Referral.objects.create(
            letter=self.overdue_letter, from_user=self.user, to_user=expert)
        call_command('check_deadlines')
        self.assertTrue(Notification.objects.filter(user=expert).exists())


class AdvancedSearchTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.viewer = give(User.objects.create_user('v', password='x'), 'view_all_letter')
        self.old = register_letter(
            self.office, 'incoming', 'نامه قدیمی', letter_date=jdatetime.date(1405, 7, 10))
        self.new = register_letter(
            self.office, 'incoming', 'نامه جدید', letter_date=jdatetime.date(1405, 7, 20))

    def test_date_range_filter(self):
        self.client.force_login(self.viewer)
        response = self.client.get(
            reverse('secretariat:letter_list'), {'date_from': '۱۴۰۵/۰۷/۱۵'})
        subjects = [letter.subject for letter in response.context['letters']]
        self.assertEqual(subjects, ['نامه جدید'])
        response = self.client.get(
            reverse('secretariat:letter_list'), {'date_to': '1405/07/15'})
        subjects = [letter.subject for letter in response.context['letters']]
        self.assertEqual(subjects, ['نامه قدیمی'])


class DelegationTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.boss = give(User.objects.create_user('boss', password='x'), 'view_own_letter')
        self.assistant = give(User.objects.create_user('assist', password='x'), 'view_own_letter')
        self.letter = register_letter(
            self.office, 'incoming', 'نامه مدیر', created_by=self.boss)

    def test_assistant_sees_nothing_without_delegation(self):
        self.assertNotIn(self.letter, visible_letters(self.assistant))

    def test_active_delegation_shares_own_scope(self):
        Delegation.objects.create(
            from_user=self.boss, to_user=self.assistant,
            start_date=date.today() - timedelta(days=1),
            end_date=date.today() + timedelta(days=5))
        self.assertIn(self.letter, visible_letters(self.assistant))

    def test_expired_delegation_grants_nothing(self):
        Delegation.objects.create(
            from_user=self.boss, to_user=self.assistant,
            start_date=date.today() - timedelta(days=10),
            end_date=date.today() - timedelta(days=5))
        self.assertNotIn(self.letter, visible_letters(self.assistant))

    def test_delegation_page_and_deactivate(self):
        delegation = Delegation.objects.create(
            from_user=self.boss, to_user=self.assistant,
            start_date=date.today(), end_date=date.today() + timedelta(days=3))
        self.client.force_login(self.boss)
        response = self.client.get(reverse('secretariat:delegations'))
        self.assertEqual(response.status_code, 200)
        self.client.post(reverse('secretariat:delegation_deactivate', args=[delegation.pk]))
        delegation.refresh_from_db()
        self.assertFalse(delegation.is_active)
        self.assertNotIn(self.letter, visible_letters(self.assistant))


class DefaultGroupsTests(TestCase):
    def test_groups_created_with_permissions(self):
        call_command('setup_default_groups')
        dabir = Group.objects.get(name='دبیر')
        codes = set(dabir.permissions.values_list('codename', flat=True))
        self.assertIn('manage_secretariat', codes)
        self.assertIn('view_all_letter', codes)
        self.assertTrue(Group.objects.filter(name='مدیر ارشد').exists())
        self.assertGreaterEqual(Group.objects.count(), 6)


class LightLoadTests(TestCase):
    """بار سبک: فهرست ۴۰ نامه‌ای باید با تعداد کوئری محدود رندر شود."""

    def test_letter_list_query_budget(self):
        office = make_office()
        viewer = give(User.objects.create_user('v', password='x'), 'view_all_letter')
        for i in range(40):
            register_letter(office, 'incoming', f'نامه {i}')
        self.client.force_login(viewer)
        with CaptureQueriesContext(connection) as ctx:
            response = self.client.get(reverse('secretariat:letter_list'))
        self.assertEqual(response.status_code, 200)
        self.assertLessEqual(len(ctx), 25, f'کوئری‌های فهرست: {len(ctx)}')
