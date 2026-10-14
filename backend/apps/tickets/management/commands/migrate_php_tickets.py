"""درون‌ریزی تیکت‌های سامانه PHP از فایل JSON خروجی → آینه → بومی.

قالب فایل JSON (فهرست اشیا):
[
  {
    "external_id": "IT-1042",
    "subject": "مشکل پرینتر",
    "status": "assigned",
    "priority": "high",
    "service_group": "سخت‌افزار",
    "requester_username": "karbar",
    "created_label": "1403/05/12"
  }
]

ایدِمپوتنت: کلید یکتا «سامانه:شناسه» است. پس از پر شدن آینه، فرمان
import_mirror_tickets اجرا می‌شود تا تیکت‌های بومی هم به‌روز شوند.
"""
import json
from pathlib import Path

from django.core.management import call_command
from django.core.management.base import BaseCommand, CommandError

from apps.ticket_bridge.models import ExternalTicket

FIELDS = ('external_id', 'subject', 'status', 'priority', 'service_group',
          'requester_username', 'created_label')


class Command(BaseCommand):
    help = 'درون‌ریزی تیکت‌های PHP از فایل JSON به آینه و زیرسامانه بومی'

    def add_arguments(self, parser):
        parser.add_argument('file', help='مسیر فایل JSON خروجی سامانه PHP')
        parser.add_argument('--system', default='php-ticketing',
                            help='نام سامانه مبدأ (پیش‌فرض: php-ticketing)')

    def handle(self, *args, **options):
        path = Path(options['file'])
        if not path.exists():
            raise CommandError(f'فایل یافت نشد: {path}')
        try:
            rows = json.loads(path.read_text(encoding='utf-8'))
        except json.JSONDecodeError as exc:
            raise CommandError(f'JSON نامعتبر: {exc}')
        if not isinstance(rows, list):
            raise CommandError('ساختار فایل باید فهرست JSON باشد.')

        system = options['system']
        fresh = updated = 0
        for row in rows:
            if not isinstance(row, dict) or not row.get('external_id') \
                    or not row.get('subject'):
                self.stderr.write(f'ردیف ناقص نادیده گرفته شد: {row!r:.120}')
                continue
            defaults = {field: str(row.get(field, '') or '') for field in FIELDS}
            _, was_created = ExternalTicket.objects.update_or_create(
                system=system, external_id=defaults.pop('external_id'),
                defaults=defaults)
            fresh += int(was_created)
            updated += int(not was_created)
        self.stdout.write(self.style.SUCCESS(
            f'درون‌ریزی آینه {system} — تازه: {fresh}، به‌روز: {updated}'))
        call_command('import_mirror_tickets', system=system)
