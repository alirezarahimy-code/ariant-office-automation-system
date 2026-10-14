"""سرویس‌های انبار: رسید/حواله/تعدیل، موجودی لحظه‌ای و کارت کالا.

همه تغییرات موجودی فقط از طریق این سرویس‌ها انجام می‌شود تا
انسجام اسناد تضمین شود (الگوی هاب مرکزی مانند اعلان‌ها).
"""
from __future__ import annotations

from decimal import Decimal

from django.db.models import Sum

from .models import Item, StockCount, StockCountLine, StockMove, Warehouse


def receive(*, item: Item, warehouse: Warehouse, quantity, date,
            reference: str = '', note: str = '', created_by=None) -> StockMove:
    """ثبت رسید ورود کالا."""
    quantity = Decimal(str(quantity))
    if quantity <= 0:
        raise ValueError('تعداد رسید باید بزرگ‌تر از صفر باشد.')
    return StockMove.objects.create(
        item=item, warehouse=warehouse, kind=StockMove.Kind.RECEIVE,
        quantity=quantity, date=date, reference=reference, note=note,
        created_by=created_by)


def issue(*, item: Item, warehouse: Warehouse, quantity, date,
          reference: str = '', note: str = '', created_by=None) -> StockMove:
    """ثبت حواله خروج کالا — با کنترل موجودی."""
    quantity = Decimal(str(quantity))
    if quantity <= 0:
        raise ValueError('تعداد حواله باید بزرگ‌تر از صفر باشد.')
    available = current_stock(item, warehouse)
    if quantity > available:
        raise ValueError(
            f'موجودی {item.code} در {warehouse} کافی نیست (موجودی: {available}).')
    return StockMove.objects.create(
        item=item, warehouse=warehouse, kind=StockMove.Kind.ISSUE,
        quantity=quantity, date=date, reference=reference, note=note,
        created_by=created_by)


def current_stock(item: Item, warehouse: Warehouse | None = None):
    """موجودی لحظه‌ای = جمع اسناد امضا‌دار (رسید +، حواله −، تعدیل)."""
    qs = StockMove.objects.filter(item=item)
    if warehouse is not None:
        qs = qs.filter(warehouse=warehouse)
    total = Decimal('0')
    for move in qs:
        total += move.signed_quantity
    return total


def item_card(item: Item, warehouse: Warehouse | None = None) -> list[dict]:
    """کارت کالا — اسناد به ترتیب با موجودی پیوسته."""
    qs = StockMove.objects.filter(item=item).select_related(
        'warehouse', 'created_by')
    if warehouse is not None:
        qs = qs.filter(warehouse=warehouse)
    rows = []
    balance = Decimal('0')
    for move in qs.order_by('date', 'id'):
        balance += move.signed_quantity
        rows.append({'move': move, 'balance': balance})
    return rows


def apply_count(count: StockCount, created_by=None) -> StockCount:
    """اعمال انبارگردانی: اختلاف هر ردیف با موجودی → سند تعدیل."""
    if count.status == StockCount.Status.APPLIED:
        raise ValueError('این انبارگردانی قبلاً اعمال شده است.')
    for line in count.lines.select_related('item'):
        expected = current_stock(line.item, count.warehouse)
        diff = line.counted_quantity - expected
        if diff != 0:
            StockMove.objects.create(
                item=line.item, warehouse=count.warehouse,
                kind=StockMove.Kind.ADJUST, quantity=diff, date=count.date,
                reference=f'انبارگردانی #{count.pk}',
                note='تعدیل انبارگردانی', created_by=created_by)
    count.status = StockCount.Status.APPLIED
    count.save(update_fields=['status', 'updated_at'])
    return count


def low_stock_items(warehouse: Warehouse | None = None) -> list[dict]:
    """کالاهای زیر حداقل موجودی — برای هشدار داشبورد."""
    result = []
    for item in Item.objects.all():
        stock = current_stock(item, warehouse)
        if item.min_stock and stock < item.min_stock:
            result.append({'item': item, 'stock': stock})
    return result
