"""آزمون‌های داشبورد گزارش‌ها."""
import jdatetime
from datetime import time as datetime_time
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from apps.meetings.models import Meeting
from apps.reports.views import ACTIONABLE_STATUSES
from apps.secretariat.models import Letter, Referral
from apps.secretariat.services import register_letter
from apps.secretariat.tests import make_office
from apps.tasks.services import create_task

User = get_user_model()


def give_perm(user, dotted):
    app, code = dotted.split('.')
    user.user_permissions.add(
        Permission.objects.get(codename=code, content_type__app_label=app))
    return user


class DashboardTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.user = User.objects.create_user('reporter', password='pass1234!')
        self.manager = give_perm(
            User.objects.create_user('manager', password='pass1234!'),
            'secretariat.view_all_letter')

    def test_anonymous_redirected(self):
        resp = self.client.get(reverse('reports:dashboard'))
        self.assertEqual(resp.status_code, 302)

    def test_dashboard_renders(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('reports:dashboard'))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'داشبورد')

    def test_kpis_count_correctly(self):
        today = jdatetime.date.today()
        create_task(title='وظیفه باز', responsible=self.user, created_by=self.user)
        create_task(title='وظیفه عقب', responsible=self.user, created_by=self.user,
                    due_jalali=today - jdatetime.timedelta(days=2))
        letter = register_letter(self.office, Letter.LetterType.INCOMING, 'نامه آزمون',
                                 created_by=self.user)
        Referral.objects.create(letter=letter, from_user=self.user, to_user=self.user,
                                status=Referral.Status.PENDING)
        Meeting.objects.create(title='جلسه امروز', jalali_date=today,
                               start_time=datetime_time(10, 0), organizer=self.user,
                               status=Meeting.Status.SCHEDULED)
        self.client.force_login(self.user)
        resp = self.client.get(reverse('reports:dashboard'))
        self.assertEqual(resp.context['open_tasks'], 2)
        self.assertEqual(resp.context['overdue_tasks'], 1)
        self.assertEqual(resp.context['my_pending'], 1)
        self.assertEqual(resp.context['month_meetings'], 1)

    def test_manager_block_hidden_for_regular_user(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('reports:dashboard'))
        self.assertFalse(resp.context['is_manager'])
        self.assertNotContains(resp, 'نمای سازمانی')

    def test_manager_block_visible(self):
        register_letter(self.office, Letter.LetterType.INCOMING, 'سازمانی',
                        created_by=self.manager,
                        deadline=jdatetime.date.today() - jdatetime.timedelta(days=1),
                        status=Letter.Status.IN_PROGRESS)
        self.client.force_login(self.manager)
        resp = self.client.get(reverse('reports:dashboard'))
        self.assertTrue(resp.context['is_manager'])
        self.assertContains(resp, 'نمای سازمانی')
        self.assertEqual(resp.context['org_overdue'], 1)
        # هر سه نوع نامه در نمودار ماهانه حاضرند
        self.assertEqual(len(resp.context['org_month_by_type']), 3)

    def test_actionable_statuses_whitelist(self):
        """فقط وضعیت‌های قابل اقدام مشمول عقب‌افتادگی‌اند."""
        self.assertNotIn(Letter.Status.DRAFT, ACTIONABLE_STATUSES)
        self.assertNotIn(Letter.Status.CLOSED, ACTIONABLE_STATUSES)
        self.assertNotIn(Letter.Status.ARCHIVED, ACTIONABLE_STATUSES)
        self.assertIn(Letter.Status.IN_PROGRESS, ACTIONABLE_STATUSES)
