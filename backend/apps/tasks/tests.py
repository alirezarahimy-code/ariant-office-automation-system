"""آزمون‌های وظایف و تقویم سازمانی."""
import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from apps.secretariat.models import Letter
from apps.secretariat.services import register_letter
from apps.secretariat.tests import make_office
from apps.tasks.models import Task, TaskEvent
from apps.tasks.services import change_status, create_task, update_progress

User = get_user_model()


def make_user(username, first='کاربر', last='آزمون', **kw):
    return User.objects.create_user(username=username, password='pass1234!',
                                    first_name=first, last_name=last, **kw)


def give_perm(user, dotted, *more):
    for item in (dotted, *more):
        app, code = item.split('.')
        user.user_permissions.add(
            Permission.objects.get(codename=code, content_type__app_label=app))
    return user


class TaskServiceTests(TestCase):
    def setUp(self):
        self.boss = make_user('boss')
        self.worker = make_user('worker')

    def test_create_task_notifies_responsible(self):
        task = create_task(title='گزارش مهر', responsible=self.worker, created_by=self.boss)
        self.assertTrue(TaskEvent.objects.filter(
            task=task, action=TaskEvent.Action.CREATED).exists())
        self.assertTrue(self.worker.notifications.filter(title__contains='وظیفه تازه').exists())

    def test_progress_100_marks_done(self):
        task = create_task(title='ت', responsible=self.worker, created_by=self.boss)
        update_progress(task, self.worker, 100)
        task.refresh_from_db()
        self.assertEqual(task.status, Task.Status.DONE)

    def test_partial_progress_sets_in_progress(self):
        task = create_task(title='ت', responsible=self.worker, created_by=self.boss)
        update_progress(task, self.worker, 40)
        self.assertEqual(task.status, Task.Status.IN_PROGRESS)

    def test_change_status_validates(self):
        task = create_task(title='ت', responsible=self.worker, created_by=self.boss)
        self.assertIsNone(change_status(task, self.worker, 'bogus'))
        self.assertIsNotNone(change_status(task, self.worker, Task.Status.CANCELLED))


class TaskViewTests(TestCase):
    def setUp(self):
        self.office = make_office('واحد فناوری')
        self.boss = make_user('boss')
        self.worker = make_user('worker')
        self.other = make_user('other')
        self.task = create_task(title='وظیفه محرمانه', responsible=self.worker,
                                created_by=self.boss)

    def test_stranger_redirected_from_detail(self):
        self.client.force_login(self.other)
        resp = self.client.get(reverse('tasks:detail', args=[self.task.pk]))
        self.assertEqual(resp.status_code, 302)

    def test_responsible_sees_detail(self):
        self.client.force_login(self.worker)
        resp = self.client.get(reverse('tasks:detail', args=[self.task.pk]))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'وظیفه محرمانه')

    def test_stranger_cannot_post_progress(self):
        self.client.force_login(self.other)
        resp = self.client.post(reverse('tasks:progress', args=[self.task.pk]),
                                {'percent': '50'})
        self.assertEqual(resp.status_code, 302)
        self.task.refresh_from_db()
        self.assertEqual(self.task.progress, 0)

    def test_overdue_tab(self):
        task = create_task(title='عقب‌افتاده', responsible=self.worker,
                           due_jalali=jdatetime.date.today() - jdatetime.timedelta(days=3),
                           created_by=self.boss)
        self.assertTrue(task.is_overdue)
        self.client.force_login(self.worker)
        resp = self.client.get(reverse('tasks:list') + '?tab=overdue')
        self.assertContains(resp, 'عقب‌افتاده')

    def test_manager_sees_all(self):
        give_perm(self.boss, 'tasks.manage_tasks')
        self.client.force_login(self.boss)
        resp = self.client.get(reverse('tasks:list') + '?tab=all')
        self.assertEqual(resp.status_code, 200)

    def test_from_letter_requires_refer_perm(self):
        letter = register_letter(self.office, Letter.LetterType.INTERNAL,
                                 'درخواست تجهیزات', created_by=self.worker,
                                 priority=Letter.Priority.URGENT)
        give_perm(self.worker, 'secretariat.view_own_letter')
        self.client.force_login(self.worker)
        resp = self.client.post(reverse('tasks:from_letter', args=[letter.pk]),
                                {'title': 'پیگیری نامه'})
        self.assertEqual(resp.status_code, 302)
        self.assertFalse(Task.objects.filter(title='پیگیری نامه').exists())
        give_perm(self.worker, 'secretariat.refer_letter')
        resp = self.client.post(reverse('tasks:from_letter', args=[letter.pk]),
                                {'title': 'پیگیری نامه'})
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(Task.objects.filter(title='پیگیری نامه', source_letter=letter).exists())


class CalendarTests(TestCase):
    def setUp(self):
        self.user = make_user('cal')

    def test_calendar_renders_current_month(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('tasks:calendar'))
        self.assertEqual(resp.status_code, 200)
        today = jdatetime.date.today()
        self.assertEqual(resp.context['year'], today.year)
        self.assertEqual(resp.context['month'], today.month)
        # ساختار هفتگی کامل و راست‌به‌چپ
        self.assertEqual(len(resp.context['weeks'][0]), 7)

    def test_calendar_navigation(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('tasks:calendar') + '?y=1404&m=7')
        self.assertEqual(resp.context['month'], 7)
        self.assertEqual(resp.context['prev'], {'y': 1404, 'm': 6})
        resp = self.client.get(reverse('tasks:calendar') + '?y=1404&m=1')
        self.assertEqual(resp.context['prev'], {'y': 1403, 'm': 12})
        resp = self.client.get(reverse('tasks:calendar') + '?y=1404&m=12')
        self.assertEqual(resp.context['next'], {'y': 1405, 'm': 1})

    def test_calendar_shows_due_task(self):
        today = jdatetime.date.today()
        create_task(title='وظیفه تقویمی', responsible=self.user,
                    due_jalali=today, created_by=self.user)
        self.client.force_login(self.user)
        resp = self.client.get(reverse('tasks:calendar'),
                               {'y': today.year, 'm': today.month})
        self.assertContains(resp, 'وظیفه تقویمی')

    def test_esfand_length(self):
        from apps.tasks.views import _month_days
        self.assertEqual(_month_days(1403, 12), 30)  # سال کبیسه
        self.assertEqual(_month_days(1404, 12), 29)
