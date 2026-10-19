"""فرمان‌های دروازه دستگاه (چندبرند، بدون سرویس ویندوزی)."""
from django.core.management.base import BaseCommand

from apps.device.gateway import run_gateway


class Command(BaseCommand):
    help = 'یک نوبت همگام‌سازی: نظرسنجی دستگاه‌های ZK + پل‌های یونیس'

    def handle(self, *args, **options):
        result = run_gateway()
        self.stdout.write(str(result))
