"""کاشت داده نمایشی انبار: انبار مرکزی، کالاهای اداری و چند سند (idempotent)."""
from decimal import Decimal

import jdatetime
from django.core.management.base import BaseCommand

from apps.inventory.models import Item, StockMove, Warehouse

ITEMS = [
    ('K-100', 'کاغذ A4', 'بسته', 'لوازم التحریر', 10),
    ('K-101', 'خودکار آبی', 'عدد', 'لوازم التحریر', 50),
    ('K-102', 'تونر پرینتر', 'عدد', 'مصرفی', 3),
    ('K-103', 'پوشه اداری', 'عدد', 'لوازم التحریر', 30),
    ('K-104', 'باتری قلمی', 'بسته', 'مصرفی', 5),
]

MOVES = [
    # کد کالا، نوع، تعداد، روزهای پیش، مدرک
    ('K-100', 'receive', 40, 25, 'رسید ۱۰۲۱'),
    ('K-100', 'issue', 12, 10, 'حواله ۲۱۰'),
    ('K-101', 'receive', 200, 24, 'رسید ۱۰۲۲'),
    ('K-101', 'issue', 60, 8, 'حواله ۲۱۱'),
    ('K-102', 'receive', 6, 20, 'رسید ۱۰۲۳'),
    ('K-102', 'issue', 4, 5, 'حواله ۲۱۲'),
    ('K-103', 'receive', 100, 18, 'رسید ۱۰۲۴'),
    ('K-104', 'receive', 12, 15, 'رسید ۱۰۲۵'),
]


class Command(BaseCommand):
    help = 'کاشت داده نمایشی انبار (idempotent)'

    def handle(self, *args, **options):
        warehouse, _ = Warehouse.objects.get_or_create(name='انبار مرکزی')
        created = 0
        for code, name, unit, category, min_stock in ITEMS:
            _, was_created = Item.objects.get_or_create(
                code=code, defaults={'name': name, 'unit': unit,
                                     'category': category,
                                     'min_stock': Decimal(min_stock)})
            created += int(was_created)
        today = jdatetime.date.today()
        for code, kind, qty, days_back, ref in MOVES:
            item = Item.objects.get(code=code)
            exists = StockMove.objects.filter(
                item=item, kind=kind, reference=ref).exists()
            if exists:
                continue
            StockMove.objects.create(
                item=item, warehouse=warehouse, kind=kind,
                quantity=Decimal(qty), date=today - jdatetime.timedelta(days=days_back),
                reference=ref, note='داده نمایشی')
            created += 1
        self.stdout.write(self.style.SUCCESS(
            f'انبار نمایشی — {created} رکورد تازه.'))
