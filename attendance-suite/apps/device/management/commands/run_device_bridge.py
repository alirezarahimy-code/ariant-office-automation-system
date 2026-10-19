"""اجرای پیوسته دروازه دستگاه — جایگزین سرویس‌های ویندوزی قدیمی.

python manage.py run_device_bridge --interval 60
(یک پروسه معمولی؛ روی لینوکس با cron/systemd اختیاری، بدون هیچ سرویس ویندوزی)
"""
import time

from django.core.management.base import BaseCommand

from apps.device.gateway import run_gateway


class Command(BaseCommand):
    help = 'حلقه پیوسته همگام‌سازی دستگاه‌ها (ZK + یونیس)'

    def add_arguments(self, parser):
        parser.add_argument('--interval', type=int, default=60,
                            help='فاصله نوبت‌ها به ثانیه (پیش‌فرض ۶۰)')

    def handle(self, *args, **options):
        interval = max(5, int(options['interval']))
        self.stdout.write(f'دروازه دستگاه فعال شد — هر {interval} ثانیه')
        try:
            while True:
                result = run_gateway()
                self.stdout.write(str(result))
                time.sleep(interval)
        except KeyboardInterrupt:
            self.stdout.write('دروازه متوقف شد.')
