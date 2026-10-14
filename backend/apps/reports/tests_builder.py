"""آزمون‌های گزارش‌ساز: مجموعه‌داده‌ها، خروجی‌ها و ارسال دوره‌ای."""
from django.contrib.auth import get_user_model
from django.core import mail
from django.core.management import call_command
from django.test import TestCase
from django.urls import reverse

from apps.reports.datasets import REPORTS, report_titles, run_report
from apps.reports.export import csv_response, xlsx_response
from apps.reports.models import ScheduledReport

User = get_user_model()


class DatasetTests(TestCase):
    def test_registry_lists_all_reports(self):
        titles = dict(report_titles())
        self.assertEqual(set(titles), {'letters', 'tasks', 'attendance', 'tickets'})

    def test_run_report_unknown_raises(self):
        with self.assertRaises(ValueError):
            run_report('nope')

    def test_run_report_empty(self):
        title, headers, rows = run_report('tasks')
        self.assertTrue(title)
        self.assertTrue(headers)
        self.assertEqual(rows, [])


class ExportTests(TestCase):
    def test_csv_response_has_bom_and_headers(self):
        response = csv_response('گزارش', ['الف', 'ب'], [['۱', '۲']])
        self.assertEqual(response.status_code, 200)
        content = response.content.decode('utf-8')
        self.assertTrue(content.startswith('﻿'))
        self.assertIn('الف', content)

    def test_xlsx_response(self):
        response = xlsx_response('گزارش', ['الف'], [['۱']])
        self.assertEqual(response.status_code, 200)
        self.assertIn('spreadsheetml', response['Content-Type'])


class BuilderViewTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')

    def test_builder_requires_login(self):
        resp = self.client.get(reverse('reports:builder'))
        self.assertEqual(resp.status_code, 302)

    def test_builder_renders(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('reports:builder'))
        self.assertEqual(resp.status_code, 200)

    def test_export_csv(self):
        self.client.force_login(self.user)
        resp = self.client.get(
            reverse('reports:export') + '?report=tasks&format=csv')
        self.assertEqual(resp.status_code, 200)
        self.assertIn('text/csv', resp['Content-Type'])

    def test_export_print(self):
        self.client.force_login(self.user)
        resp = self.client.get(
            reverse('reports:export') + '?report=tasks&format=print')
        self.assertEqual(resp.status_code, 200)


class ScheduledDispatchTests(TestCase):
    def setUp(self):
        self.report = ScheduledReport.objects.create(
            name='گزارش هفتگی وظایف', slug='tasks',
            period=ScheduledReport.Period.WEEKLY,
            emails='boss@example.com\nvice@example.com')

    def test_dispatch_sends_and_stamps(self):
        call_command('dispatch_scheduled_reports')
        self.assertEqual(len(mail.outbox), 1)
        self.assertEqual(mail.outbox[0].to, ['boss@example.com', 'vice@example.com'])
        self.report.refresh_from_db()
        self.assertIsNotNone(self.report.last_sent_at)

    def test_dispatch_skips_within_period(self):
        call_command('dispatch_scheduled_reports')
        call_command('dispatch_scheduled_reports')
        self.assertEqual(len(mail.outbox), 1)

    def test_dispatch_force_resends(self):
        call_command('dispatch_scheduled_reports')
        call_command('dispatch_scheduled_reports', '--force')
        self.assertEqual(len(mail.outbox), 2)

    def test_dispatch_unknown_slug_skipped(self):
        self.report.slug = 'nope'
        self.report.save()
        call_command('dispatch_scheduled_reports')
        self.assertEqual(len(mail.outbox), 0)

    def test_dispatch_inactive_skipped(self):
        self.report.is_active = False
        self.report.save()
        call_command('dispatch_scheduled_reports')
        self.assertEqual(len(mail.outbox), 0)
