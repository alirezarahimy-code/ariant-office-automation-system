"""آزمون فرمان‌های گذار تیکتینگ: درون‌ریزی فایل و گزارش آمادگی سوییچ."""
import json
from io import StringIO
from pathlib import Path

from django.core.management import call_command
from django.test import TestCase

from apps.ticket_bridge.models import ExternalTicket, IntegrationUserMap
from apps.tickets.models import Ticket

SAMPLE = [
    {'external_id': 'IT-1', 'subject': 'مشکل شبکه', 'status': 'assigned',
     'priority': 'high', 'service_group': 'شبکه',
     'requester_username': 'karbar', 'created_label': '1403/05/12'},
    {'external_id': 'IT-2', 'subject': 'نصب نرم‌افزار', 'status': 'weird_status',
     'priority': 'low', 'service_group': 'نرم‌افزار',
     'requester_username': 'unknown_user', 'created_label': '1403/05/13'},
]


class MigratePhpTicketsTests(TestCase):
    def setUp(self):
        self.path = Path('/tmp/php_tickets_export.json')
        self.path.write_text(json.dumps(SAMPLE, ensure_ascii=False),
                             encoding='utf-8')

    def test_import_creates_mirrors_and_natives(self):
        out = StringIO()
        call_command('migrate_php_tickets', str(self.path), stdout=out)
        self.assertEqual(ExternalTicket.objects.count(), 2)
        self.assertEqual(Ticket.objects.filter(external_ref__startswith='php-ticketing:').count(), 2)
        ticket = Ticket.objects.get(external_ref='php-ticketing:IT-1')
        self.assertEqual(ticket.status, Ticket.Status.IN_PROGRESS)

    def test_import_is_idempotent(self):
        call_command('migrate_php_tickets', str(self.path))
        call_command('migrate_php_tickets', str(self.path))
        self.assertEqual(ExternalTicket.objects.count(), 2)
        self.assertEqual(
            Ticket.objects.filter(external_ref__startswith='php-ticketing:').count(), 2)

    def test_unknown_status_falls_back_to_open(self):
        call_command('migrate_php_tickets', str(self.path))
        ticket = Ticket.objects.get(external_ref='php-ticketing:IT-2')
        self.assertEqual(ticket.status, Ticket.Status.OPEN)


class CutoverCheckTests(TestCase):
    def test_report_flags_blockers(self):
        ExternalTicket.objects.create(
            system='php-ticketing', external_id='IT-9', subject='نمونه',
            status='assigned', requester_username='nobody')
        out = StringIO()
        call_command('ticket_cutover_check', stdout=out)
        text = out.getvalue()
        self.assertIn('مانع', text)
        self.assertIn('nobody', text)

    def test_report_ready_when_clean(self):
        out = StringIO()
        call_command('ticket_cutover_check', stdout=out)
        self.assertIn('آماده سوییچ', out.getvalue())

    def test_mapped_user_not_flagged(self):
        from django.contrib.auth import get_user_model
        user = get_user_model().objects.create_user('karbar', password='x')
        IntegrationUserMap.objects.create(
            platform_user=user, external_username='karbar_php')
        ExternalTicket.objects.create(
            system='php-ticketing', external_id='IT-10', subject='نمونه',
            status='assigned', requester_username='karbar_php')
        call_command('import_mirror_tickets')  # بومی‌سازی تا «مانع» باقی نماند
        out = StringIO()
        call_command('ticket_cutover_check', stdout=out)
        self.assertIn('آماده سوییچ', out.getvalue())
