"""سرویس‌های انبار — نقطه ورود یکتای همه تغییرات موجودی.

سند (سربرگ+ردیف) ثبت می‌شود؛ با «قطعی شدن» رویدادهای انبار (StockMove)
تولید و موجودی اثر می‌پذیرد. ابطال سند، رویدادهایش را برمی‌دارد.
توابع کلاسیک receive/issue/transfer لایه سازگاری روی همین جریان‌اند.
"""
from __future__ import annotations

from decimal import Decimal

from django.db import transaction
from django.db.models import Sum
from django.utils import timezone

from .models import (CountTag, Item, ItemUnit, PriceList, PriceListItem,
                     SerialNumber, StockCount, StockCountLine, StockDocSequence,
                     StockDocument, StockDocumentLine, StockLayer, StockMove,
                     StockMoveCategory, Warehouse)


# ─────────────────────────────────────────────────────────────
# شماره‌گذاری سالانه اسناد
# ─────────────────────────────────────────────────────────────
DOC_PREFIXES = {
    StockDocument.DocType.RECEIVE: 'رس',
    StockDocument.DocType.ISSUE: 'ح',
    StockDocument.DocType.TRANSFER: 'ان',
    StockDocument.DocType.ADJUST: 'تعدیل',
}


def next_document_number(year: int, doc_type: str) -> tuple[str, int]:
    """شماره بعدی سند در سال (رس-1404-0001) — اتمیک با شمارنده اختصاصی."""
    with transaction.atomic():
        seq, _ = StockDocSequence.objects.get_or_create(year=year, doc_type=doc_type)
        seq.last_seq += 1
        seq.save(update_fields=['last_seq'])
        prefix = DOC_PREFIXES.get(doc_type, 'سند')
        return f'{prefix}-{year}-{seq.last_seq:04d}', seq.last_seq


# ─────────────────────────────────────────────────────────────
# ایجاد سند و ردیف
# ─────────────────────────────────────────────────────────────
def create_document(*, doc_type: str, warehouse: Warehouse, date,
                    created_by=None, category: str = StockMoveCategory.OTHER,
                    to_warehouse: Warehouse | None = None, reference: str = '',
                    description: str = '', reverses: StockDocument | None = None,
                    finalize: bool = False,
                    lines: list[dict] | None = None) -> StockDocument:
    """ثبت سند چندردیفی (موقت) — با finalize بلافاصله قطعی می‌شود."""
    if doc_type == StockDocument.DocType.TRANSFER:
        if to_warehouse is None:
            raise ValueError('برای سند انتقال، انبار مقصد لازم است.')
        if to_warehouse.pk == warehouse.pk:
            raise ValueError('انبار مبدأ و مقصد نمی‌توانند یکی باشند.')
    doc = StockDocument(
        doc_type=doc_type, category=category, warehouse=warehouse,
        to_warehouse=to_warehouse, date=date, reference=reference,
        description=description, reverses=reverses, created_by=created_by,
        status=StockDocument.Status.TEMPORARY)
    doc.assign_number()
    doc.save()
    for i, line_data in enumerate(lines or []):
        add_line(doc, sequence=i, **line_data)
    if finalize:
        confirm_document(doc, confirmed_by=created_by)
    return doc


def add_line(document: StockDocument, *, item: Item, quantity, sequence: int = 0,
             unit_name: str = '', unit_ratio=None, unit_price=None,
             expiry_date=None, batch_no: str = '', location=None,
             description: str = '', serials_raw: str = '') -> StockDocumentLine:
    """افزودن ردیف به سند موقت."""
    if document.status != StockDocument.Status.TEMPORARY:
        raise ValueError('فقط به سند موقت می‌توان ردیف افزود.')
    quantity = Decimal(str(quantity))
    if document.doc_type != StockDocument.DocType.ADJUST and quantity <= 0:
        raise ValueError('تعداد ردیف باید بزرگ‌تر از صفر باشد.')
    if unit_ratio is None:
        unit_ratio = Decimal('1')
        if not unit_name:
            unit_name = item.unit
        else:
            unit = item.units.filter(name=unit_name).first()
            if unit is not None:
                unit_ratio = unit.ratio
            elif unit_name != item.unit:
                raise ValueError(f'واحد «{unit_name}» برای {item.code} تعریف نشده است.')
    return StockDocumentLine.objects.create(
        document=document, item=item, quantity=quantity,
        unit_name=unit_name or item.unit, unit_ratio=unit_ratio,
        unit_price=unit_price, expiry_date=expiry_date, batch_no=batch_no,
        location=location or item.default_location, description=description,
        serials_raw=serials_raw, sequence=sequence)


# ─────────────────────────────────────────────────────────────
# قطعی کردن سند → تولید رویداد
# ─────────────────────────────────────────────────────────────
def _check_period_open(date) -> None:
    from apps.core.services import feature_enabled
    if not feature_enabled('inventory.financial_period'):
        return
    from .models import InventoryPeriod
    year = date.year if date else timezone.localdate().year
    period = InventoryPeriod.objects.filter(year=year).first()
    if period is not None and not period.is_open:
        raise ValueError(f'دوره مالی {year} بسته است؛ امکان ثبت سند نیست.')


def _layer_order(item: Item):
    """ترتیب مصرف لایه‌ها: FIFO قدیمی‌ترین، LIFO تازه‌ترین."""
    if item.costing_method == Item.CostingMethod.LIFO:
        return ['-received_date', '-id']
    return ['received_date', 'id']


def _update_fifo_layers(line: StockDocumentLine, move: StockMove) -> None:
    """به‌روزرسانی لایه‌های هزینه برای کالای FIFO/LIFO (ورود: لایه جدید، خروج: مصرف)."""
    from apps.core.services import feature_enabled
    item = line.item
    if (not feature_enabled('inventory.fifo')
            or item.costing_method == Item.CostingMethod.AVERAGE):
        return
    doc = line.document
    if doc.doc_type == StockDocument.DocType.RECEIVE:
        cost = line.unit_price if line.unit_price is not None else weighted_avg_price(item)
        StockLayer.objects.create(
            item=item, warehouse=move.warehouse, quantity_remaining=line.base_quantity,
            unit_cost=cost or 0, received_date=doc.date, source_line=line)
    elif doc.doc_type in (StockDocument.DocType.ISSUE, StockDocument.DocType.TRANSFER):
        to_consume = abs(line.base_quantity)
        layers = StockLayer.objects.filter(
            item=item, warehouse=move.warehouse,
            quantity_remaining__gt=0).order_by(*_layer_order(item))
        if doc.doc_type == StockDocument.DocType.TRANSFER and move.kind == StockMove.Kind.RECEIVE:
            # ورودی مقصد انتقال: لایه جدید با بهای مصرف‌شده مبدأ
            cost = move.unit_price if move.unit_price is not None else weighted_avg_price(item)
            StockLayer.objects.create(
                item=item, warehouse=move.warehouse, quantity_remaining=line.base_quantity,
                unit_cost=cost or 0, received_date=doc.date, source_line=line)
            return
        for layer in layers:
            if to_consume <= 0:
                break
            take = min(layer.quantity_remaining, to_consume)
            layer.quantity_remaining -= take
            layer.save(update_fields=['quantity_remaining', 'updated_at'])
            to_consume -= take


def _fifo_issue_cost(item: Item, warehouse: Warehouse, quantity) -> Decimal | None:
    """بهای تمام‌شده خروج FIFO/LIFO از روی لایه‌ها (بدون مصرف)."""
    to_see = Decimal(str(quantity))
    total = Decimal('0')
    for layer in StockLayer.objects.filter(
            item=item, warehouse=warehouse, quantity_remaining__gt=0
    ).order_by(*_layer_order(item)):
        if to_see <= 0:
            break
        take = min(layer.quantity_remaining, to_see)
        total += take * layer.unit_cost
        to_see -= take
    if to_see > 0:
        return None
    return (total / Decimal(str(quantity))).quantize(Decimal('1'))


def _apply_serials(line: StockDocumentLine, warehouse: Warehouse) -> None:
    """ثبت/مصرف سریال‌های ردیف (کالای سریالی)."""
    from apps.core.services import feature_enabled
    if not feature_enabled('inventory.serial_expiry') or not line.item.track_serial:
        return
    doc = line.document
    codes = line.serial_codes
    if doc.doc_type == StockDocument.DocType.RECEIVE:
        for code in codes:
            serial, created = SerialNumber.objects.get_or_create(
                item=line.item, code=code,
                defaults={'warehouse': warehouse, 'status': SerialNumber.Status.IN_STOCK})
            if not created:
                serial.warehouse = warehouse
                serial.status = SerialNumber.Status.IN_STOCK
                serial.save(update_fields=['warehouse', 'status', 'updated_at'])
    elif doc.doc_type == StockDocument.DocType.ISSUE:
        for code in codes:
            SerialNumber.objects.filter(item=line.item, code=code).update(
                status=SerialNumber.Status.OUT, warehouse=None)


def _notify_low_stock(doc: StockDocument) -> None:
    """اعلان نقطه سفارش/حداقل موجودی پس از قطعی سند."""
    from apps.core.services import feature_enabled, send_notification
    if not feature_enabled('inventory.low_stock_alert'):
        return
    from django.contrib.auth import get_user_model
    User = get_user_model()
    targets = User.objects.filter(
        is_superuser=True) | User.objects.filter(
        user_permissions__codename='manage_inventory')
    seen_items = set()
    for line in doc.lines.select_related('item'):
        item = line.item
        if item.pk in seen_items:
            continue
        seen_items.add(item.pk)
        stock = current_stock(item)
        threshold = item.reorder_point or item.min_stock
        if threshold and stock <= threshold:
            title = f'هشدار موجودی: {item.name}'
            body = (f'موجودی {item.code} به {stock} رسیده '
                    f'(نقطه سفارش: {item.reorder_point}، حداقل: {item.min_stock}).')
            for user in set(targets):
                send_notification(user, title, body, f'/inventory/{item.pk}/')


def confirm_document(document: StockDocument, confirmed_by=None) -> StockDocument:
    """قطعی کردن سند: تولید رویدادها، کنترل موجودی، قیمت‌گذاری و اعلان."""
    if document.status == StockDocument.Status.FINAL:
        raise ValueError('این سند قبلاً قطعی شده است.')
    if document.status == StockDocument.Status.CANCELLED:
        raise ValueError('سند ابطال‌شده قابل قطعی نیست.')
    lines = list(document.lines.select_related('item', 'location'))
    if not lines:
        raise ValueError('سند بدون ردیف قابل قطعی نیست.')
    _check_period_open(document.date)
    with transaction.atomic():
        for line in lines:
            item = line.item
            qty = line.base_quantity
            price = line.unit_price
            if document.doc_type == StockDocument.DocType.RECEIVE:
                if qty <= 0:
                    raise ValueError('تعداد رسید باید بزرگ‌تر از صفر باشد.')
                move = StockMove.objects.create(
                    item=item, warehouse=document.warehouse, kind=StockMove.Kind.RECEIVE,
                    category=document.category, quantity=qty, unit_price=price,
                    date=document.date, reference=document.number,
                    note=line.description or document.description,
                    document=document, line=line, created_by=document.created_by)
                if price is not None:
                    item.last_purchase_price = price
                    item.save(update_fields=['last_purchase_price', 'updated_at'])
                _update_fifo_layers(line, move)
                _apply_serials(line, document.warehouse)
            elif document.doc_type == StockDocument.DocType.ISSUE:
                if qty <= 0:
                    raise ValueError('تعداد حواله باید بزرگ‌تر از صفر باشد.')
                available = current_stock(item, document.warehouse)
                if qty > available:
                    raise ValueError(
                        f'موجودی {item.code} در {document.warehouse} کافی نیست '
                        f'(موجودی: {available}).')
                if price is None and item.costing_method in (Item.CostingMethod.FIFO, Item.CostingMethod.LIFO):
                    price = _fifo_issue_cost(item, document.warehouse, qty)
                issue_move = StockMove.objects.create(
                    item=item, warehouse=document.warehouse, kind=StockMove.Kind.ISSUE,
                    category=document.category, quantity=qty, unit_price=price,
                    date=document.date, reference=document.number,
                    note=line.description or document.description,
                    document=document, line=line, created_by=document.created_by)
                _update_fifo_layers(line, issue_move)
                _apply_serials(line, document.warehouse)
            elif document.doc_type == StockDocument.DocType.TRANSFER:
                if qty <= 0:
                    raise ValueError('تعداد انتقال باید بزرگ‌تر از صفر باشد.')
                available = current_stock(item, document.warehouse)
                if qty > available:
                    raise ValueError(
                        f'موجودی {item.code} در {document.warehouse} کافی نیست '
                        f'(موجودی: {available}).')
                out_price = price
                if out_price is None and item.costing_method in (Item.CostingMethod.FIFO, Item.CostingMethod.LIFO):
                    out_price = _fifo_issue_cost(item, document.warehouse, qty)
                out_move = StockMove.objects.create(
                    item=item, warehouse=document.warehouse, kind=StockMove.Kind.ISSUE,
                    category=document.category, quantity=qty, unit_price=out_price,
                    date=document.date, reference=document.number,
                    note=line.description or f'انتقال به {document.to_warehouse.name}',
                    document=document, line=line, created_by=document.created_by)
                in_move = StockMove.objects.create(
                    item=item, warehouse=document.to_warehouse, kind=StockMove.Kind.RECEIVE,
                    category=document.category, quantity=qty, unit_price=out_price,
                    date=document.date, reference=document.number,
                    note=line.description or f'انتقال از {document.warehouse.name}',
                    document=document, line=line, created_by=document.created_by)
                _update_fifo_layers(line, out_move)
                _update_fifo_layers(line, in_move)
            elif document.doc_type == StockDocument.DocType.ADJUST:
                StockMove.objects.create(
                    item=item, warehouse=document.warehouse, kind=StockMove.Kind.ADJUST,
                    category=document.category, quantity=qty, unit_price=price,
                    date=document.date, reference=document.number,
                    note=line.description or document.description,
                    document=document, line=line, created_by=document.created_by)
        document.status = StockDocument.Status.FINAL
        document.confirmed_by = confirmed_by or document.created_by
        document.confirmed_at = timezone.now()
        document.save(update_fields=['status', 'confirmed_by', 'confirmed_at', 'updated_at'])
    _notify_low_stock(document)
    return document


def cancel_document(document: StockDocument, *, reason: str = '',
                    cancelled_by=None) -> StockDocument:
    """ابطال سند قطعی — رویدادهای سند برمی‌گردد و اثر موجودی خنثی می‌شود."""
    if document.status == StockDocument.Status.CANCELLED:
        raise ValueError('این سند قبلاً ابطال شده است.')
    if document.status != StockDocument.Status.FINAL:
        raise ValueError('فقط سند قطعی قابل ابطال است.')
    with transaction.atomic():
        # بازگردانی لایه‌های FIFO (در صورت فعال بودن)
        for line in document.lines.select_related('item'):
            if line.item.costing_method == Item.CostingMethod.AVERAGE:
                continue
            for move in line.moves.all():
                if move.kind == StockMove.Kind.RECEIVE and document.doc_type == StockDocument.DocType.RECEIVE:
                    StockLayer.objects.filter(source_line=line).delete()
                elif move.kind == StockMove.Kind.ISSUE:
                    StockLayer.objects.create(
                        item=line.item, warehouse=move.warehouse,
                        quantity_remaining=abs(line.base_quantity),
                        unit_cost=move.unit_price or 0,
                        received_date=document.date, source_line=line)
        document.moves.all().delete()
        document.status = StockDocument.Status.CANCELLED
        document.cancel_reason = reason[:300]
        document.cancelled_by = cancelled_by
        document.cancelled_at = timezone.now()
        document.save(update_fields=[
            'status', 'cancel_reason', 'cancelled_by', 'cancelled_at', 'updated_at'])
    return document


def create_return_document(document: StockDocument, created_by=None) -> StockDocument:
    """سند برگشتی — آینه سند مبدأ (رسید↔حواله) با پیوند به سند اصلی و قطعی."""
    if document.status != StockDocument.Status.FINAL:
        raise ValueError('فقط از سند قطعی می‌توان سند برگشتی صادر کرد.')
    if document.doc_type == StockDocument.DocType.RECEIVE:
        new_type = StockDocument.DocType.ISSUE
        new_category = StockMoveCategory.RETURN_OUT
    elif document.doc_type == StockDocument.DocType.ISSUE:
        new_type = StockDocument.DocType.RECEIVE
        new_category = StockMoveCategory.RETURN_IN
    elif document.doc_type == StockDocument.DocType.TRANSFER:
        new_type = StockDocument.DocType.TRANSFER
        new_category = StockMoveCategory.TRANSFER
    else:
        new_type = StockDocument.DocType.ADJUST
        new_category = StockMoveCategory.ADJUSTMENT
    if new_type == StockDocument.DocType.TRANSFER:
        warehouse, to_warehouse = document.to_warehouse, document.warehouse
    else:
        warehouse, to_warehouse = document.warehouse, None
    lines = []
    for line in document.lines.all():
        quantity = -line.quantity if new_type == StockDocument.DocType.ADJUST else line.quantity
        lines.append({
            'item': line.item, 'quantity': quantity,
            'unit_name': line.unit_name, 'unit_ratio': line.unit_ratio,
            'unit_price': line.unit_price, 'expiry_date': line.expiry_date,
            'batch_no': line.batch_no, 'location': line.location,
            'description': f'برگشت از سند {document.number}'})
    doc = create_document(
        doc_type=new_type, warehouse=warehouse, to_warehouse=to_warehouse,
        date=document.date, created_by=created_by, category=new_category,
        reference=document.number,
        description=f'برگشت از {document.get_doc_type_display()} {document.number}',
        reverses=document, finalize=True, lines=lines)
    return doc


# ─────────────────────────────────────────────────────────────
# لایه سازگاری کلاسیک (تک‌قلمی) — رفتار قبلی روی اسناد
# ─────────────────────────────────────────────────────────────
def receive(*, item: Item, warehouse: Warehouse, quantity, date,
            reference: str = '', note: str = '', created_by=None,
            category: str = StockMoveCategory.OTHER,
            unit_price=None) -> StockMove:
    """ثبت رسید ورود کالا (سند تک‌ردیفی قطعی)."""
    quantity = Decimal(str(quantity))
    if quantity <= 0:
        raise ValueError('تعداد رسید باید بزرگ‌تر از صفر باشد.')
    doc = create_document(
        doc_type=StockDocument.DocType.RECEIVE, warehouse=warehouse, date=date,
        created_by=created_by, category=category, reference=reference,
        description=note, finalize=True,
        lines=[{'item': item, 'quantity': quantity, 'unit_price': unit_price,
                'description': note}])
    return doc.moves.first()


def issue(*, item: Item, warehouse: Warehouse, quantity, date,
          reference: str = '', note: str = '', created_by=None,
          category: str = StockMoveCategory.OTHER,
          unit_price=None) -> StockMove:
    """ثبت حواله خروج کالا — با کنترل موجودی."""
    quantity = Decimal(str(quantity))
    if quantity <= 0:
        raise ValueError('تعداد حواله باید بزرگ‌تر از صفر باشد.')
    available = current_stock(item, warehouse)
    if quantity > available:
        raise ValueError(
            f'موجودی {item.code} در {warehouse} کافی نیست (موجودی: {available}).')
    doc = create_document(
        doc_type=StockDocument.DocType.ISSUE, warehouse=warehouse, date=date,
        created_by=created_by, category=category, reference=reference,
        description=note, finalize=True,
        lines=[{'item': item, 'quantity': quantity, 'unit_price': unit_price,
                'description': note}])
    return doc.moves.first()


def transfer(*, item: Item, from_warehouse: Warehouse, to_warehouse: Warehouse,
             quantity, date, reference: str = '', note: str = '',
             created_by=None) -> tuple[StockMove, StockMove]:
    """انتقال بین انبارها — سند انتقال با حواله مبدأ + رسید مقصد."""
    if from_warehouse.pk == to_warehouse.pk:
        raise ValueError('انبار مبدأ و مقصد نمی‌توانند یکی باشند.')
    quantity = Decimal(str(quantity))
    available = current_stock(item, from_warehouse)
    if quantity > available:
        raise ValueError(
            f'موجودی {item.code} در {from_warehouse} کافی نیست (موجودی: {available}).')
    doc = create_document(
        doc_type=StockDocument.DocType.TRANSFER, warehouse=from_warehouse,
        to_warehouse=to_warehouse, date=date, created_by=created_by,
        category=StockMoveCategory.TRANSFER, reference=reference, description=note,
        finalize=True, lines=[{'item': item, 'quantity': quantity, 'description': note}])
    out_move = doc.moves.filter(warehouse=from_warehouse).first()
    in_move = doc.moves.filter(warehouse=to_warehouse).first()
    return out_move, in_move


# ─────────────────────────────────────────────────────────────
# موجودی، کارت کالا و ارزش‌گذاری
# ─────────────────────────────────────────────────────────────
def current_stock(item: Item, warehouse: Warehouse | None = None):
    """موجودی لحظه‌ای = جمع رویدادها (رسید +، حواله −، تعدیل)."""
    qs = StockMove.objects.filter(item=item)
    if warehouse is not None:
        qs = qs.filter(warehouse=warehouse)
    total = Decimal('0')
    for move in qs:
        total += move.signed_quantity
    return total


def item_card(item: Item, warehouse: Warehouse | None = None) -> list[dict]:
    """کارت کالا — رویدادها به ترتیب با موجودی پیوسته."""
    qs = StockMove.objects.filter(item=item).select_related(
        'warehouse', 'created_by', 'document')
    if warehouse is not None:
        qs = qs.filter(warehouse=warehouse)
    rows = []
    balance = Decimal('0')
    for move in qs.order_by('date', 'id'):
        balance += move.signed_quantity
        rows.append({'move': move, 'balance': balance})
    return rows


def weighted_avg_price(item: Item) -> Decimal:
    """میانگین موزون قیمت خرید از روی رسیدهای دارای قیمت."""
    received = Decimal('0')
    cost = Decimal('0')
    for move in StockMove.objects.filter(
            item=item, kind=StockMove.Kind.RECEIVE, unit_price__isnull=False):
        received += move.quantity
        cost += move.quantity * move.unit_price
    if received <= 0:
        return Decimal('0')
    return (cost / received).quantize(Decimal('1'))


def fifo_value(item: Item, warehouse: Warehouse | None = None) -> Decimal:
    """ارزش موجودی FIFO از لایه‌های باز خرید."""
    qs = StockLayer.objects.filter(item=item, quantity_remaining__gt=0)
    if warehouse is not None:
        qs = qs.filter(warehouse=warehouse)
    return sum((l.quantity_remaining * l.unit_cost for l in qs), Decimal('0'))


def inventory_value(warehouse: Warehouse | None = None) -> dict:
    """ارزش موجودی هر کالا (میانگین موزون یا FIFO) + جمع کل."""
    rows = []
    total = Decimal('0')
    for item in Item.objects.all():
        stock = current_stock(item, warehouse)
        if stock == 0:
            continue
        if item.costing_method in (Item.CostingMethod.FIFO, Item.CostingMethod.LIFO):
            value = fifo_value(item, warehouse)
            avg = (value / stock).quantize(Decimal('1')) if stock else Decimal('0')
        else:
            avg = weighted_avg_price(item)
            value = stock * avg
        total += value
        rows.append({'item': item, 'stock': stock, 'avg_price': avg,
                     'value': value})
    return {'rows': rows, 'total': total}


def movement_report(item: Item, date_from=None, date_to=None,
                    warehouse: Warehouse | None = None) -> dict:
    """ریزگردش کالا در بازه — با موجودی پیوسته (برای گزارش و چاپ)."""
    qs = StockMove.objects.filter(item=item).select_related(
        'warehouse', 'created_by', 'document')
    if warehouse is not None:
        qs = qs.filter(warehouse=warehouse)
    if date_from is not None:
        qs = qs.filter(date__gte=date_from.togregorian()
                       if hasattr(date_from, 'togregorian') else date_from)
    if date_to is not None:
        qs = qs.filter(date__lte=date_to.togregorian()
                       if hasattr(date_to, 'togregorian') else date_to)
    rows = []
    balance = Decimal('0')
    if date_from is not None:
        for move in StockMove.objects.filter(item=item).order_by('date', 'id'):
            if move.date > date_from:
                break
            if warehouse is None or move.warehouse_id == warehouse.pk:
                balance += move.signed_quantity
    opening = balance
    for move in qs.order_by('date', 'id'):
        balance += move.signed_quantity
        rows.append({'move': move, 'balance': balance})
    return {'opening': opening, 'rows': rows}


def low_stock_items(warehouse: Warehouse | None = None) -> list[dict]:
    """کالاهای زیر حداقل موجودی یا نقطه سفارش — برای هشدار داشبورد."""
    result = []
    for item in Item.objects.filter(is_active=True):
        stock = current_stock(item, warehouse)
        threshold = item.reorder_point or item.min_stock
        if threshold and stock < threshold:
            result.append({'item': item, 'stock': stock})
    return result


# ─────────────────────────────────────────────────────────────
# انبارگردانی حرفه‌ای
# ─────────────────────────────────────────────────────────────
def apply_count(count: StockCount, created_by=None) -> StockCount:
    """اعمال انبارگردانی: سند اصلاحی خودکار (کسری/اضافی) + رویداد تعدیل."""
    if count.status == StockCount.Status.APPLIED:
        raise ValueError('این انبارگردانی قبلاً اعمال شده است.')
    from apps.core.services import feature_enabled
    auto_docs = feature_enabled('inventory.count_auto_docs')
    lines_data = []
    for line in count.lines.select_related('item'):
        expected = current_stock(line.item, count.warehouse)
        diff = line.counted_quantity - expected
        line.expected_quantity = expected
        line.save(update_fields=['expected_quantity', 'updated_at'])
        if diff != 0:
            lines_data.append({
                'item': line.item, 'quantity': diff,
                'description': f'تعدیل انبارگردانی #{count.pk}'})
    if auto_docs and lines_data:
        create_document(
            doc_type=StockDocument.DocType.ADJUST, warehouse=count.warehouse,
            date=count.date, created_by=created_by or count.created_by,
            category=StockMoveCategory.ADJUSTMENT,
            reference=f'انبارگردانی #{count.pk}',
            description=f'سند اصلاحی انبارگردانی {count.warehouse}',
            finalize=True, lines=lines_data)
    count.status = StockCount.Status.APPLIED
    count.save(update_fields=['status', 'updated_at'])
    return count


def generate_count_tags(count: StockCount, quantity: int = 10,
                        items=None) -> list[CountTag]:
    """صدور تگ‌های چاپی انبارگردانی با کد یکتا (T{شمارش}-{ردیف})."""
    from apps.core.services import feature_enabled
    if not feature_enabled('inventory.count_tags'):
        raise ValueError('قابلیت تگ انبارگردانی غیرفعال است.')
    tags = []
    start = count.tags.count() + 1
    item_list = list(items) if items is not None else [None] * quantity
    for i, item in enumerate(item_list):
        code = f'T{count.pk}-{start + i:04d}'
        tags.append(CountTag.objects.create(count=count, code=code, item=item))
    return tags


def import_count_rows(count: StockCount, rows: list[tuple], stage: int = 1) -> int:
    """ورود شمارش از اکسل/CSV: ردیف‌های (کالا، تعداد) — مرحله ۱ یا ۲."""
    from apps.core.services import feature_enabled
    if not feature_enabled('inventory.count_excel'):
        raise ValueError('قابلیت ورود شمارش از اکسل غیرفعال است.')
    updated = 0
    for item, qty in rows:
        if qty is None:
            continue
        qty = Decimal(str(qty))
        line, _ = StockCountLine.objects.get_or_create(
            count=count, item=item,
            defaults={'counted_quantity': qty, 'stage1_quantity': qty})
        if stage == 1:
            line.stage1_quantity = qty
        else:
            line.stage2_quantity = qty
        line.counted_quantity = qty
        line.save(update_fields=[
            'stage1_quantity', 'stage2_quantity', 'counted_quantity', 'updated_at'])
        updated += 1
    return updated


# ─────────────────────────────────────────────────────────────
# قیمت‌گذاری
# ─────────────────────────────────────────────────────────────
def set_item_price(price_list: PriceList, item: Item, price) -> PriceListItem:
    entry, _ = PriceListItem.objects.update_or_create(
        price_list=price_list, item=item, defaults={'price': Decimal(str(price))})
    return entry


def apply_batch_pricing(price_list: PriceList, percent: float) -> int:
    """قیمت‌گذاری دسته‌ای — افزایش/کاهش درصدی همه قیمت‌های یک لیست."""
    from apps.core.services import feature_enabled
    if not feature_enabled('inventory.batch_pricing'):
        raise ValueError('قابلیت قیمت‌گذاری دسته‌ای غیرفعال است.')
    factor = Decimal(str(100 + float(percent))) / Decimal('100')
    count = 0
    for entry in price_list.entries.all():
        entry.price = (entry.price * factor).quantize(Decimal('1'))
        entry.save(update_fields=['price', 'updated_at'])
        count += 1
    return count


# ─────────────────────────────────────────────────────────────
# گزارش‌های تکمیلی
# ─────────────────────────────────────────────────────────────
def no_movement_items(date_from, date_to, warehouse: Warehouse | None = None) -> list:
    """کالاهای بدون گردش در بازه (گزارش راکد)."""
    qs = Item.objects.filter(is_active=True)
    result = []
    for item in qs:
        moves = StockMove.objects.filter(item=item)
        if warehouse is not None:
            moves = moves.filter(warehouse=warehouse)
        if date_from is not None:
            moves = moves.filter(date__gte=date_from.togregorian()
                                 if hasattr(date_from, 'togregorian') else date_from)
        if date_to is not None:
            moves = moves.filter(date__lte=date_to.togregorian()
                                 if hasattr(date_to, 'togregorian') else date_to)
        if not moves.exists():
            result.append({'item': item, 'stock': current_stock(item, warehouse)})
    return result


def expiry_report(warehouse: Warehouse | None = None) -> list[dict]:
    """گزارش انقضا/بچ — ردیف‌های دارای تاریخ انقضای گذشته یا نزدیک."""
    import datetime
    import jdatetime
    today = jdatetime.date.today()
    rows = []
    seen = set()
    lines = StockDocumentLine.objects.filter(
        expiry_date__isnull=False, document__status=StockDocument.Status.FINAL
    ).select_related('item', 'document', 'document__warehouse')
    for line in lines:
        key = (line.item_id, line.batch_no, str(line.expiry_date))
        if key in seen:
            continue
        if warehouse is not None and line.document.warehouse_id != warehouse.pk:
            continue
        seen.add(key)
        days = (line.expiry_date - today).days
        rows.append({'item': line.item, 'batch_no': line.batch_no,
                     'expiry_date': line.expiry_date, 'days_left': days,
                     'warehouse': line.document.warehouse,
                     'quantity': line.base_quantity})
    rows.sort(key=lambda r: r['days_left'])
    return rows


def serial_report(item: Item | None = None) -> list:
    """گزارش سریال‌ها — وضعیت در انبار/خارج."""
    qs = SerialNumber.objects.select_related('item', 'warehouse')
    if item is not None:
        qs = qs.filter(item=item)
    return list(qs)


def visible_warehouses(user) -> list[Warehouse]:
    """انبارهای قابل مشاهده کاربر — انبارداری به تفکیک کاربر."""
    from apps.core.services import feature_enabled
    if (user.is_superuser or user.has_perm('inventory.manage_inventory')
            or not feature_enabled('inventory.warehouse_users')):
        return list(Warehouse.objects.all())
    result = []
    for wh in Warehouse.objects.prefetch_related('users'):
        keepers = list(wh.users.values_list('pk', flat=True))
        if not keepers or user.pk in keepers:
            result.append(wh)
    return result


def export_accounting_rows(date_from=None, date_to=None) -> list[dict]:
    """خروجی اسناد برای حسابداری — ردیف‌های رویداد در بازه."""
    qs = StockMove.objects.select_related(
        'item', 'warehouse', 'document').order_by('date', 'id')
    if date_from is not None:
        qs = qs.filter(date__gte=date_from.togregorian()
                       if hasattr(date_from, 'togregorian') else date_from)
    if date_to is not None:
        qs = qs.filter(date__lte=date_to.togregorian()
                       if hasattr(date_to, 'togregorian') else date_to)
    rows = []
    for move in qs:
        rows.append({
            'date': move.date, 'number': move.document.number if move.document else '',
            'item_code': move.item.code, 'item_name': move.item.name,
            'warehouse': move.warehouse.name, 'kind': move.get_kind_display(),
            'quantity': move.signed_quantity,
            'unit_price': move.unit_price or 0,
            'value': abs(move.quantity) * (move.unit_price or 0),
            'reference': move.reference, 'note': move.note})
    return rows
