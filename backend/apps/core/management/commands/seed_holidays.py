"""کاشت تعطیلات رسمی ثابت شمسی (idempotent).

تعطیلات قمری (نیمه شعبان، عید فطر، عید قربان، غدیر، تاسوعا/عاشورا، اربعین،
رحلت پیامبر و امام جعفر صادق) هر سال جابه‌جا می‌شوند و باید توسط مدیر
در پنل مدیریت یا این فرمان با --file اضافه شوند.
"""
import json
from pathlib import Path

from django.core.management.base import BaseCommand

from apps.core.models import Holiday

FIXED = [
    ('01-01', 'جشن نوروز'),
    ('01-02', 'عیدنوروز'),
    ('01-03', 'عیدنوروز'),
    ('01-04', 'عیدنوروز'),
    ('01-12', 'روز جمهوری اسلامی'),
    ('01-13', 'روز طبیعت (سیزده‌به‌در)'),
    ('03-14', 'رحلت امام خمینی'),
    ('03-15', 'قیام ۱۵ خرداد'),
    ('11-22', 'پیروزی انقلاب اسلامی'),
    ('12-29', 'ملی شدن صنعت نفت'),
]

LUNAR_NOTE = ('تعطیلات قمری (نیمه شعبان، عید فطر، قربان، غدیر، عاشورا، اربعین، '
              'رحلت پیامبر) هر سال جابه‌جا می‌شوند — با --file یا پنل مدیریت اضافه کنید.')


class Command(BaseCommand):
    help = 'کاشت تعطیلات رسمی ثابت شمسی (idempotent)'

    def add_arguments(self, parser):
        parser.add_argument('--years', default='1404,1405,1406',
                            help='سال‌های شمسی (جدا با کاما)')
        parser.add_argument('--file', default='',
                            help='JSON اختیاری: [{"date": "1404/05/15", "title": "..."}]')

    def handle(self, *args, **options):
        years = [int(y) for y in options['years'].split(',') if y.strip()]
        created = 0
        for year in years:
            for mmdd, title in FIXED:
                month, day = (int(p) for p in mmdd.split('-'))
                from jdatetime import date as jdate
                try:
                    jdate(year, month, day)
                except ValueError:
                    continue
                _, was_created = Holiday.objects.get_or_create(
                    date=jdate(year, month, day), defaults={'title': title})
                created += int(was_created)

        if options['file']:
            rows = json.loads(Path(options['file']).read_text(encoding='utf-8'))
            from apps.secretariat.views import _parse_jalali_input
            for row in rows:
                jdate_val = _parse_jalali_input(row.get('date', ''))
                if jdate_val is None:
                    continue
                _, was_created = Holiday.objects.get_or_create(
                    date=jdate_val, defaults={'title': row.get('title', 'تعطیل')})
                created += int(was_created)

        self.stdout.write(self.style.SUCCESS(
            f'تعطیلات رسمی — {created} رکورد تازه. {LUNAR_NOTE}'))
