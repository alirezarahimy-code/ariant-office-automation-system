"""گزارش آمادگی سوییچ TICKET_MODE=native — دوره موازی (بند ۴ docs/04).

مقایسه آینه PHP با زیرسامانه بومی: شمارش‌ها، وضعیت‌های ناشناخته،
کاربران نگاشت‌نشده و تیکت‌های باز. خروجی برای تصمیم انسان است؛
هیچ تغییری در داده نمی‌دهد.
"""
from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand

from apps.ticket_bridge.models import ExternalTicket, IntegrationUserMap
from apps.tickets.models import Ticket

User = get_user_model()


class Command(BaseCommand):
    help = 'گزارش آمادگی سوییچ تیکتینگ به حالت بومی (فقط خواندنی)'

    def add_arguments(self, parser):
        parser.add_argument('--system', default='php-ticketing')

    def handle(self, *args, **options):
        system = options['system']
        mirrors = ExternalTicket.objects.filter(system=system)
        natives = Ticket.objects.exclude(external_ref='')

        self.stdout.write(f'=== گزارش آمادگی سوییچ تیکتینگ ({system}) ===')
        self.stdout.write(f'تیکت‌های آینه: {mirrors.count()}')
        self.stdout.write(f'تیکت‌های بومی دارای ارجاع بیرونی: {natives.count()}')
        self.stdout.write(f'تیکت‌های بومی بدون ارجاع (دستی): '
                          f'{Ticket.objects.filter(external_ref="").count()}')

        missing = mirrors.count() - natives.count()
        if missing > 0:
            self.stdout.write(self.style.WARNING(
                f'⚠ {missing} تیکت آینه هنوز بومی نشده — '
                f'فرمان import_mirror_tickets را اجرا کنید.'))
        elif missing == 0:
            self.stdout.write(self.style.SUCCESS('✓ همه تیکت‌های آینه بومی شده‌اند.'))

        known_statuses = set(Ticket.EXTERNAL_STATUS_MAP)
        unknown = sorted({m.status for m in mirrors if m.status
                          and m.status not in known_statuses})
        if unknown:
            self.stdout.write(self.style.WARNING(
                f'⚠ وضعیت‌های بدون نگاشت (به «باز» برگردانده می‌شوند): '
                f'{", ".join(unknown)}'))
        else:
            self.stdout.write(self.style.SUCCESS('✓ همه وضعیت‌ها نگاشت دارند.'))

        mapped = {m.external_username for m in IntegrationUserMap.objects.all()}
        usernames = set(User.objects.values_list('username', flat=True))
        unmapped = sorted({m.requester_username for m in mirrors
                           if m.requester_username
                           and m.requester_username not in mapped
                           and m.requester_username not in usernames})
        if unmapped:
            self.stdout.write(self.style.WARNING(
                f'⚠ درخواست‌دهندگان بدون نگاشت کاربری: {", ".join(unmapped[:10])}'
                f'{" …" if len(unmapped) > 10 else ""} — '
                f'با IntegrationUserMap نگاشت دهید.'))
        else:
            self.stdout.write(self.style.SUCCESS('✓ نگاشت کاربران کامل است.'))

        open_native = natives.exclude(
            status__in=(Ticket.Status.RESOLVED, Ticket.Status.CLOSED)).count()
        self.stdout.write(f'تیکت‌های باز بومی: {open_native}')

        blockers = int(missing > 0) + int(bool(unknown)) + int(bool(unmapped))
        if blockers == 0:
            self.stdout.write(self.style.SUCCESS(
                '✓ آماده سوییچ: TICKET_MODE=native را در env تنظیم کنید.'))
        else:
            self.stdout.write(self.style.WARNING(
                f'⚠ {blockers} مانع سوییچ باقی است — پس از رفع، دوباره بررسی کنید.'))
