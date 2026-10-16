"""کاشت داده نمایشی انبار: گروه‌ها، کالاها، واحدها، قیمت و اسناد (idempotent)."""
from decimal import Decimal

import jdatetime
from django.core.management.base import BaseCommand

from apps.inventory.models import (Item, ItemGroup, ItemSpec, ItemUnit, PriceList,
                                   StockDocument, Warehouse, WarehouseLocation)
from apps.inventory.services import create_document

ITEMS = [
    ('K-100', 'کاغذ A4', 'بسته', 'لوازم التحریر', 10),
    ('K-101', 'خودکار آبی', 'عدد', 'لوازم التحریر', 50),
    ('K-102', 'تونر پرینتر', 'عدد', 'مصرفی', 3),
    ('K-103', 'پوشه اداری', 'عدد', 'لوازم التحریر', 30),
    ('K-104', 'باتری قلمی', 'بسته', 'مصرفی', 5),
]

MOVES = [
    # کد کالا، نوع، تعداد، روزهای پیش، مدرک، قیمت واحد
    ('K-100', 'receive', 40, 25, 'رسید ۱۰۲۱', 280000),
    ('K-100', 'issue', 12, 10, 'حواله ۲۱۰', None),
    ('K-101', 'receive', 200, 24, 'رسید ۱۰۲۲', 45000),
    ('K-101', 'issue', 60, 8, 'حواله ۲۱۱', None),
    ('K-102', 'receive', 6, 20, 'رسید ۱۰۲۳', 3200000),
    ('K-102', 'issue', 4, 5, 'حواله ۲۱۲', None),
    ('K-103', 'receive', 100, 18, 'رسید ۱۰۲۴', 95000),
    ('K-104', 'receive', 12, 15, 'رسید ۱۰۲۵', 180000),
]


class Command(BaseCommand):
    help = 'کاشت داده نمایشی انبار (idempotent)'

    def handle(self, *args, **options):
        warehouse, _ = Warehouse.objects.get_or_create(name='انبار مرکزی')
        loc, _ = WarehouseLocation.objects.get_or_create(
            warehouse=warehouse, code='A-1', defaults={'name': 'قفسه اول'})
        created = 0

        groups = {}
        for group_name in ['لوازم التحریر', 'مصرفی']:
            groups[group_name], was_created = ItemGroup.objects.get_or_create(
                name=group_name, parent=None)
            created += int(was_created)

        for code, name, unit, category, min_stock in ITEMS:
            item, was_created = Item.objects.get_or_create(
                code=code, defaults={
                    'name': name, 'unit': unit, 'category': category,
                    'group': groups[category], 'min_stock': Decimal(min_stock),
                    'reorder_point': Decimal(min_stock) * 2,
                    'default_location': loc})
            created += int(was_created)
            if was_created and code == 'K-100':
                ItemUnit.objects.create(item=item, name='کارتن', ratio=10)
                ItemSpec.objects.create(item=item, title='گرماژ', value='80 گرم')
                ItemSpec.objects.create(item=item, title='اندازه', value='A4')

        price_list, _ = PriceList.objects.get_or_create(
            name='قیمت فروش', defaults={'is_default': True})
        from apps.inventory.services import set_item_price
        for item in Item.objects.all():
            set_item_price(price_list, item,
                           (item.last_purchase_price or 100000) * 2)

        today = jdatetime.date.today()
        for code, kind, qty, days_back, ref, price in MOVES:
            item = Item.objects.get(code=code)
            exists = StockDocument.objects.filter(reference=ref).exists()
            if exists:
                continue
            create_document(
                doc_type=kind, warehouse=warehouse,
                date=today - jdatetime.timedelta(days=days_back),
                category=('purchase' if kind == 'receive' else 'consumption'),
                reference=ref, description='داده نمایشی', finalize=True,
                lines=[{'item': item, 'quantity': Decimal(qty),
                        'unit_price': Decimal(price) if price else None}])
            created += 1

        self.stdout.write(self.style.SUCCESS(
            f'انبار نمایشی — {created} رکورد تازه.'))
