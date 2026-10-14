"""ارسال گزارش‌های زمان‌بندی‌شده — روزانه/هفتگی/ماهانه با ایمیل (پیوست CSV).

با cron اجرا شود (deploy/cron.example). --force همه را بدون توجه به
آخرین ارسال، می‌فرستد (برای آزمون و ارسال دستی).
"""
import datetime
import io

from django.conf import settings
from django.core.mail import EmailMessage
from django.core.management.base import BaseCommand
from django.utils import timezone

from apps.reports.datasets import run_report
from apps.reports.export import csv_response
from apps.reports.models import ScheduledReport

INTERVALS = {
    ScheduledReport.Period.DAILY: datetime.timedelta(hours=20),
    ScheduledReport.Period.WEEKLY: datetime.timedelta(days=6),
    ScheduledReport.Period.MONTHLY: datetime.timedelta(days=28),
}


class Command(BaseCommand):
    help = 'ارسال گزارش‌های زمان‌بندی‌شده دوره‌ای (ایمیل + پیوست CSV)'

    def add_arguments(self, parser):
        parser.add_argument('--force', action='store_true',
                            help='ارسال بدون توجه به دوره')

    def handle(self, *args, **options):
        now = timezone.now()
        reports = ScheduledReport.objects.filter(is_active=True)
        sent = 0
        for report in reports:
            interval = INTERVALS[report.period]
            if not options['force'] and report.last_sent_at \
                    and (now - report.last_sent_at) < interval:
                continue
            try:
                title, headers, rows = run_report(report.slug)
            except ValueError as exc:
                self.stderr.write(f'{report.name}: {exc}')
                continue
            response = csv_response(title, headers, rows)
            recipients = report.email_list
            if not recipients:
                self.stderr.write(f'{report.name}: گیرنده‌ای ثبت نشده.')
                continue
            mail = EmailMessage(
                subject=f'گزارش دوره‌ای: {report.name}',
                body=f'گزارش «{report.name}» پیوست است.',
                from_email=getattr(settings, 'DEFAULT_FROM_EMAIL',
                                   'noreply@ariant.local'),
                to=recipients)
            mail.attach(f'{title}.csv', response.content, 'text/csv')
            try:
                mail.send(fail_silently=False)
            except Exception as exc:  # noqa: BLE001
                self.stderr.write(f'خطای ارسال {report.name}: {exc}')
                continue
            report.last_sent_at = now
            report.save(update_fields=['last_sent_at', 'updated_at'])
            sent += 1
        self.stdout.write(self.style.SUCCESS(
            f'گزارش‌های دوره‌ای — {sent} مورد ارسال شد.'))
