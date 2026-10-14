"""کاشت داده نمایشی تردد برای شش کاربر نمونه (ایدِمپوتنت)."""
import datetime
import random

import jdatetime
from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand

from apps.attendance.models import AttendanceRecord, WorkSchedule
from apps.attendance.services import default_schedule

# الگوی ساعتی هر کاربر نمونه (ورود، خروج)
PATTERNS = {
    'dabir': (7, 50, 16, 5),
    'modir': (8, 0, 17, 0),
    'karshenas': (8, 20, 16, 0),
    'emza': (7, 55, 16, 0),
    'modir-arshad': (8, 5, 17, 30),
    'karbar': (8, 10, 16, 0),
}


class Command(BaseCommand):
    help = 'کاشت تردد نمونه ۳۰ روز اخیر برای کاربران دمو (idempotent)'

    def handle(self, *args, **options):
        schedule = default_schedule()
        User = get_user_model()
        today = jdatetime.date.today()
        created = 0
        for username, (ih, im, oh, om) in PATTERNS.items():
            user = User.objects.filter(username=username).first()
            if user is None:
                continue
            rng = random.Random(username)  # قطعی و تکرارپذیر
            for back in range(1, 31):
                jdate = today - jdatetime.timedelta(days=back)
                if not WorkSchedule.objects.first().is_workday(jdate):
                    continue
                if rng.random() < 0.08:  # غیبت پراکنده
                    continue
                extra = rng.choice([0, 5, 10, 25, 40]) if rng.random() < 0.3 else 0
                check_in = (datetime.datetime(2000, 1, 1, ih, im)
                            + datetime.timedelta(minutes=extra)).time()
                check_out = datetime.time(oh, om)
                _, was_created = AttendanceRecord.objects.get_or_create(
                    user=user, date=jdate,
                    defaults={'check_in': check_in, 'check_out': check_out,
                              'source': AttendanceRecord.Source.IMPORT,
                              'note': 'داده نمایشی'})
                created += int(was_created)
        self.stdout.write(self.style.SUCCESS(
            f'تردد نمایشی — {created} ثبت تازه برای {len(PATTERNS)} کاربر.'))
