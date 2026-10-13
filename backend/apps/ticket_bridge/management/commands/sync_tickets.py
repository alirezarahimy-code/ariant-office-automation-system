"""همگام‌سازی فقط‌خواندنی تیکت‌ها از سامانه PHP."""
from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand

from apps.ticket_bridge.client import BridgeNotConfigured, sync_tickets
from apps.ticket_bridge.models import IntegrationUserMap


class Command(BaseCommand):
    help = 'واکشی تیکت‌های کاربران از سامانه تیکتینگ (فقط‌خواندنی)'

    def add_arguments(self, parser):
        parser.add_argument(
            '--usernames', default='',
            help='فهرست نام‌های کاربری بیرونی با کاما؛ پیش‌فرض: همه نگاشت‌ها/کاربران فعال')

    def handle(self, *args, **options):
        raw = options.get('usernames', '')
        if raw:
            usernames = [u.strip() for u in raw.split(',') if u.strip()]
        else:
            usernames = list(
                IntegrationUserMap.objects.values_list('external_username', flat=True))
            if not usernames:
                User = get_user_model()
                usernames = list(
                    User.objects.filter(is_active=True).values_list('username', flat=True))
        if not usernames:
            self.stdout.write(self.style.WARNING('کاربری برای همگام‌سازی یافت نشد.'))
            return
        try:
            result = sync_tickets(usernames)
        except BridgeNotConfigured as exc:
            self.stdout.write(self.style.ERROR(str(exc)))
            return
        self.stdout.write(self.style.SUCCESS(
            f"واکشی {result['fetched']} تیکت — تازه: {result['created']}، به‌روز: {result['updated']}"))
