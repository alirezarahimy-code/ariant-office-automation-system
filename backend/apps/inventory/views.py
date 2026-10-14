"""نماهای انبار: فهرست کالا، کارت کالا، ثبت سند و انبارگردانی."""
from __future__ import annotations

from decimal import Decimal

import jdatetime
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.db.models import Q
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from .models import Item, StockCount, StockCountLine, StockMove, Warehouse
from .services import (apply_count, current_stock, issue, item_card,
                       low_stock_items, movement_report, inventory_value,
                       receive, transfer)


def _can_manage(user) -> bool:
    return user.is_superuser or user.has_perm('inventory.manage_inventory')


def _parse_jalali(raw: str):
    from apps.secretariat.views import _parse_jalali_input
    return _parse_jalali_input(raw)


@login_required
def item_list(request):
    q = request.GET.get('q', '').strip()
    items = Item.objects.all()
    if q:
        items = items.filter(
            Q(name__icontains=q) | Q(code__icontains=q) | Q(category__icontains=q))
    rows = [{'item': item, 'stock': current_stock(item)} for item in items]
    return render(request, 'inventory/list.html', {
        'rows': rows, 'q': q,
        'low': low_stock_items(), 'can_manage': _can_manage(request.user),
    })


@login_required
def item_detail(request, pk):
    item = get_object_or_404(Item, pk=pk)
    rows = item_card(item)
    return render(request, 'inventory/detail.html', {
        'item': item, 'rows': rows, 'stock': current_stock(item),
        'warehouses': Warehouse.objects.all(),
    })


@login_required
def move_new(request):
    if request.method != 'POST':
        return render(request, 'inventory/move_new.html', {
            'items': Item.objects.all(), 'warehouses': Warehouse.objects.all(),
        })
    item = get_object_or_404(Item, pk=request.POST.get('item_id'))
    warehouse = get_object_or_404(Warehouse, pk=request.POST.get('warehouse_id'))
    kind = request.POST.get('kind', StockMove.Kind.RECEIVE)
    category = request.POST.get('category', StockMove.Category.OTHER)
    if category not in StockMove.Category.values:
        category = StockMove.Category.OTHER
    jdate = _parse_jalali(request.POST.get('date', '')) or jdatetime.date.today()
    try:
        quantity = Decimal(request.POST.get('quantity', '0').replace(',', ''))
        unit_price = request.POST.get('unit_price', '').strip().replace(',', '')
        unit_price = Decimal(unit_price) if unit_price else None
        if kind == StockMove.Kind.ISSUE:
            move = issue(item=item, warehouse=warehouse, quantity=quantity,
                         date=jdate, reference=request.POST.get('reference', ''),
                         note=request.POST.get('note', ''), created_by=request.user,
                         category=category, unit_price=unit_price)
        elif kind == StockMove.Kind.RECEIVE:
            move = receive(item=item, warehouse=warehouse, quantity=quantity,
                           date=jdate, reference=request.POST.get('reference', ''),
                           note=request.POST.get('note', ''), created_by=request.user,
                           category=category, unit_price=unit_price)
        else:
            raise ValueError('نوع سند نامعتبر است.')
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
        return redirect('inventory:move_new')
    messages.success(request, f'{move.get_kind_display()} {item.code} ثبت شد.')
    return redirect('inventory:detail', pk=item.pk)


@login_required
def balance(request):
    rows = [{'item': item, 'stock': current_stock(item)} for item in
            Item.objects.all()]
    return render(request, 'inventory/balance.html', {
        'rows': rows, 'low': low_stock_items(),
        'warehouses': Warehouse.objects.all(),
    })


@login_required
@require_POST
def count_apply(request):
    if not _can_manage(request.user):
        messages.error(request, 'امکان انبارگردانی ندارید.')
        return redirect('inventory:balance')
    warehouse = get_object_or_404(Warehouse, pk=request.POST.get('warehouse_id'))
    jdate = _parse_jalali(request.POST.get('date', '')) or jdatetime.date.today()
    count = StockCount.objects.create(
        warehouse=warehouse, date=jdate, created_by=request.user)
    line_count = 0
    for item in Item.objects.all():
        raw = request.POST.get(f'count_{item.pk}', '').strip()
        if not raw:
            continue
        try:
            qty = Decimal(raw.replace(',', ''))
        except ArithmeticError:
            continue
        StockCountLine.objects.create(
            count=count, item=item, counted_quantity=qty)
        line_count += 1
    if line_count == 0:
        count.delete()
        messages.error(request, 'هیچ شمارشی ثبت نشد.')
        return redirect('inventory:balance')
    apply_count(count, created_by=request.user)
    messages.success(request,
                     f'انبارگردانی {warehouse} با {line_count} ردیف اعمال شد.')
    return redirect('inventory:balance')


@login_required
def move_print(request, pk):
    """چاپ سند رسید/حواله — سربرگ‌دار با شماره مدرک."""
    move = get_object_or_404(
        StockMove.objects.select_related('item', 'warehouse', 'created_by'), pk=pk)
    return render(request, 'inventory/move_print.html', {'move': move})


@login_required
def movement(request, pk):
    """ریزگردش کالا در بازه شمسی — با موجودی ابتدای بازه و پیوسته."""
    item = get_object_or_404(Item, pk=pk)
    from apps.secretariat.views import _parse_jalali_input
    date_from = _parse_jalali_input(request.GET.get('date_from', ''))
    date_to = _parse_jalali_input(request.GET.get('date_to', ''))
    warehouse = None
    if request.GET.get('warehouse'):
        warehouse = Warehouse.objects.filter(
            pk=request.GET.get('warehouse')).first()
    report = movement_report(item, date_from, date_to, warehouse)
    return render(request, 'inventory/movement.html', {
        'item': item, 'report': report,
        'warehouses': Warehouse.objects.all(),
        'date_from': request.GET.get('date_from', ''),
        'date_to': request.GET.get('date_to', ''),
        'warehouse': warehouse,
    })


@login_required
@require_POST
def transfer_new(request):
    """انتقال کالا بین انبارها — حواله مبدأ + رسید مقصد با یک مدرک."""
    item = get_object_or_404(Item, pk=request.POST.get('item_id'))
    from_wh = get_object_or_404(Warehouse, pk=request.POST.get('from_warehouse_id'))
    to_wh = get_object_or_404(Warehouse, pk=request.POST.get('to_warehouse_id'))
    jdate = _parse_jalali(request.POST.get('date', '')) or jdatetime.date.today()
    try:
        quantity = Decimal(
            request.POST.get('quantity', '0').replace(',', ''))
        transfer(item=item, from_warehouse=from_wh, to_warehouse=to_wh,
                 quantity=quantity, date=jdate,
                 reference=request.POST.get('reference', ''),
                 note=request.POST.get('note', ''), created_by=request.user)
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
        return redirect('inventory:move_new')
    messages.success(request,
                     f'انتقال {item.code} از {from_wh} به {to_wh} ثبت شد.')
    return redirect('inventory:detail', pk=item.pk)


@login_required
def value_report(request):
    """ارزش موجودی انبار — میانگین موزون قیمت خرید."""
    warehouse = None
    if request.GET.get('warehouse'):
        warehouse = Warehouse.objects.filter(
            pk=request.GET.get('warehouse')).first()
    data = inventory_value(warehouse)
    return render(request, 'inventory/value.html', {
        'data': data, 'warehouses': Warehouse.objects.all(),
        'warehouse': warehouse,
    })
