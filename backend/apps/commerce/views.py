"""نماهای بازرگانی حرفه‌ای: طرف تجاری، چرخه خرید، فروش، واردات و گزارش‌ها."""
from __future__ import annotations

from decimal import Decimal

import jdatetime
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from apps.core.services import feature_enabled

from .models import (BusinessParty, Contract, ImportShipment, Inquiry,
                     PaymentSchedule, PurchaseInvoice, PurchaseOrder,
                     PurchaseRequest, PurchaseRequestLine, SalesDocument)
from .services import (add_import_cost, add_inquiry, add_purchase_invoice_line,
                       add_purchase_order_line, add_sales_line, cancel_purchase_invoice,
                       cancel_purchase_order, cancel_sales_document,
                       confirm_purchase_order, create_contract,
                       create_import_shipment, create_installments,
                       create_purchase_invoice, create_purchase_order,
                       create_sales_document, decide_request,
                       finalize_purchase_invoice, finalize_sales_document,
                       landed_unit_costs, open_commitments, pay_installment,
                       receive_purchase_order, select_inquiry,
                       set_import_status, submit_request, three_way_match)


def _can_manage(user) -> bool:
    return user.is_superuser or user.has_perm('commerce.manage_commerce')


def _parse_jalali(raw: str):
    from apps.secretariat.views import _parse_jalali_input
    return _parse_jalali_input(raw)


def _parse_money(raw: str) -> Decimal:
    return Decimal((raw or '0').replace(',', '').strip() or '0')


def _parse_lines(post, prefix: str = 'line') -> list[dict]:
    """استخراج ردیف‌های فرم (فیلدهای ایندکس‌دار line_item_N ...)."""
    lines = []
    i = 0
    while i < 30:
        desc = post.get(f'{prefix}_desc_{i}', '').strip()
        item_id = post.get(f'{prefix}_item_{i}', '').strip()
        qty_raw = post.get(f'{prefix}_qty_{i}', '').strip().replace(',', '')
        if not desc and not item_id:
            i += 1
            if i > 3 and not any(post.get(f'{prefix}_desc_{j}', '').strip()
                                 or post.get(f'{prefix}_item_{j}', '').strip()
                                 for j in range(i, i + 3)):
                break
            continue
        item = None
        if item_id:
            from apps.inventory.models import Item
            item = Item.objects.filter(pk=item_id).first()
        lines.append({
            'item': item,
            'description': desc or (item.name if item else ''),
            'quantity': Decimal(qty_raw or '1'),
            'unit_price': _parse_money(post.get(f'{prefix}_price_{i}', '')),
            'unit_name': post.get(f'{prefix}_unit_{i}', '').strip() or 'عدد',
            'discount_percent': _parse_money(post.get(f'{prefix}_disc_{i}', '0') or '0'),
        })
        i += 1
    return lines


# ─────────────────────────────────────────────────────────────
# درخواست خرید (سازگار با نسخه پایه)
# ─────────────────────────────────────────────────────────────
@login_required
def purchase_list(request):
    tab = request.GET.get('tab', 'mine')
    qs = PurchaseRequest.objects.select_related('requested_by')
    if tab == 'mine':
        qs = qs.filter(requested_by=request.user)
    elif tab == 'pending':
        qs = qs.filter(status=PurchaseRequest.Status.SUBMITTED)
    elif not _can_manage(request.user):
        qs = qs.filter(requested_by=request.user)
    return render(request, 'commerce/list.html', {
        'requests': qs, 'tab': tab,
        'can_manage': _can_manage(request.user),
    })


@login_required
def purchase_new(request):
    if request.method != 'POST':
        return render(request, 'commerce/new.html', {
            'today': jdatetime.date.today().strftime('%Y/%m/%d'),
            'parties': BusinessParty.objects.filter(is_active=True),
            'priorities': PurchaseRequest.Priority.choices,
            'purchase_kinds': PurchaseRequest.PurchaseKind.choices,
        })
    title = request.POST.get('title', '').strip()
    if not title:
        messages.error(request, 'عنوان خرید لازم است.')
        return redirect('commerce:new')
    purchase = PurchaseRequest.objects.create(
        title=title,
        description=request.POST.get('description', '').strip(),
        estimated_cost=_parse_money(request.POST.get('estimated_cost', '')),
        needed_by=_parse_jalali(request.POST.get('needed_by', '')),
        priority=request.POST.get('priority', 'normal'),
        purchase_kind=request.POST.get('purchase_kind', 'inquiry'),
        department=request.POST.get('department', '').strip(),
        requested_by=request.user)
    for i in range(20):
        desc = request.POST.get(f'req_desc_{i}', '').strip()
        if not desc:
            continue
        PurchaseRequestLine.objects.create(
            request=purchase, description=desc,
            quantity=_parse_money(request.POST.get(f'req_qty_{i}', '1') or '1'),
            unit=request.POST.get(f'req_unit_{i}', '').strip() or 'عدد',
            estimated_price=_parse_money(request.POST.get(f'req_price_{i}', '')) or None)
    if request.POST.get('action') == 'submit':
        submit_request(purchase, request.user)
        messages.success(request, 'درخواست برای تأیید ارسال شد.')
    else:
        messages.success(request, 'پیش‌نویس درخواست ذخیره شد.')
    return redirect('commerce:detail', pk=purchase.pk)


@login_required
def purchase_detail(request, pk):
    purchase = get_object_or_404(
        PurchaseRequest.objects.select_related('requested_by', 'decided_by'),
        pk=pk)
    if purchase.requested_by != request.user and not _can_manage(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('commerce:list')
    return render(request, 'commerce/detail.html', {
        'purchase': purchase, 'can_manage': _can_manage(request.user),
        'is_owner': purchase.requested_by == request.user,
        'parties': BusinessParty.objects.filter(is_active=True),
    })


@login_required
@require_POST
def purchase_submit(request, pk):
    purchase = get_object_or_404(PurchaseRequest, pk=pk)
    if purchase.requested_by != request.user and not _can_manage(request.user):
        return redirect('commerce:list')
    submit_request(purchase, request.user)
    messages.success(request, 'درخواست برای تأیید ارسال شد.')
    return redirect('commerce:detail', pk=pk)


@login_required
@require_POST
def purchase_decide(request, pk):
    if not _can_manage(request.user):
        messages.error(request, 'امکان تأیید/رد ندارید.')
        return redirect('commerce:list')
    purchase = get_object_or_404(PurchaseRequest, pk=pk)
    decide_request(purchase, request.user,
                   approve=request.POST.get('decision') == 'approve',
                   note=request.POST.get('note', ''))
    messages.success(request, 'درخواست بررسی شد.')
    return redirect('commerce:detail', pk=pk)


@login_required
@require_POST
def inquiry_add(request, pk):
    purchase = get_object_or_404(PurchaseRequest, pk=pk)
    if purchase.requested_by != request.user and not _can_manage(request.user):
        return redirect('commerce:list')
    supplier = None
    if request.POST.get('supplier_id'):
        supplier = BusinessParty.objects.filter(
            pk=request.POST.get('supplier_id')).first()
    try:
        inquiry = add_inquiry(
            purchase=purchase, supplier=supplier,
            supplier_name=request.POST.get('supplier_name', '').strip(),
            quote_amount=_parse_money(request.POST.get('quote_amount', '')),
            quote_note=request.POST.get('quote_note', ''),
            delivery_days=int(request.POST.get('delivery_days', '0') or '0'))
    except Exception as exc:  # noqa: BLE001 — پیام تمیز به کاربر
        messages.error(request, f'استعلام ثبت نشد: {exc}')
        return redirect('commerce:detail', pk=pk)
    messages.success(request, f'پیشنهاد {inquiry.supplier_name} ثبت شد.')
    return redirect('commerce:detail', pk=pk)


@login_required
@require_POST
def inquiry_select(request, pk, inquiry_pk):
    purchase = get_object_or_404(PurchaseRequest, pk=pk)
    if purchase.requested_by != request.user and not _can_manage(request.user):
        return redirect('commerce:list')
    inquiry = get_object_or_404(Inquiry, pk=inquiry_pk, request=purchase)
    select_inquiry(inquiry)
    messages.success(request, f'پیشنهاد {inquiry.supplier_name} انتخاب شد.')
    return redirect('commerce:detail', pk=pk)


@login_required
def contract_list(request):
    contracts = Contract.objects.select_related('purchase_request', 'party_ref')
    return render(request, 'commerce/contracts.html', {
        'contracts': contracts,
        'can_manage': _can_manage(request.user),
        'kinds': Contract.Kind.choices,
        'parties': BusinessParty.objects.filter(is_active=True),
    })


@login_required
@require_POST
def contract_new(request):
    if not _can_manage(request.user):
        messages.error(request, 'امکان ثبت قرارداد ندارید.')
        return redirect('commerce:contract_list')
    title = request.POST.get('title', '').strip()
    if not title:
        messages.error(request, 'عنوان قرارداد لازم است.')
        return redirect('commerce:contract_list')
    purchase = None
    if request.POST.get('purchase_id'):
        purchase = PurchaseRequest.objects.filter(
            pk=request.POST.get('purchase_id')).first()
    party_ref = None
    if request.POST.get('party_id'):
        party_ref = BusinessParty.objects.filter(pk=request.POST.get('party_id')).first()
    create_contract(
        title=title, party=request.POST.get('party', '').strip(),
        amount=_parse_money(request.POST.get('amount', '')),
        start_date=_parse_jalali(request.POST.get('start_date', ''))
        or jdatetime.date.today(),
        end_date=_parse_jalali(request.POST.get('end_date', '')),
        purchase=purchase, description=request.POST.get('description', ''),
        kind=request.POST.get('kind', 'purchase'), party_ref=party_ref,
        guarantee_amount=_parse_money(request.POST.get('guarantee_amount', '')))
    messages.success(request, 'قرارداد ثبت شد.')
    return redirect('commerce:contract_list')


# ─────────────────────────────────────────────────────────────
# طرف‌های تجاری
# ─────────────────────────────────────────────────────────────
@login_required
def party_list(request):
    if not feature_enabled('commerce.parties'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.parties'})
    q = request.GET.get('q', '').strip()
    kind = request.GET.get('kind', '')
    parties = BusinessParty.objects.all()
    if q:
        from django.db.models import Q
        parties = parties.filter(
            Q(name__icontains=q) | Q(code__icontains=q)
            | Q(national_id__icontains=q) | Q(economic_code__icontains=q))
    if kind in BusinessParty.Kind.values:
        parties = parties.filter(kind=kind)
    return render(request, 'commerce/party_list.html', {
        'parties': parties, 'q': q, 'kind': kind,
        'kinds': BusinessParty.Kind.choices,
        'can_manage': _can_manage(request.user),
    })


@login_required
def party_new(request):
    if request.method != 'POST':
        return render(request, 'commerce/party_form.html',
                      {'parties': BusinessParty.objects.all(),
                       'kinds': BusinessParty.Kind.choices})
    if not _can_manage(request.user):
        messages.error(request, 'امکان ثبت طرف تجاری ندارید.')
        return redirect('commerce:party_list')
    party = BusinessParty.objects.create(
        code=BusinessParty.next_code(),
        name=request.POST.get('name', '').strip(),
        kind=request.POST.get('kind', 'supplier'),
        economic_code=request.POST.get('economic_code', '').strip(),
        national_id=request.POST.get('national_id', '').strip(),
        phone=request.POST.get('phone', '').strip(),
        mobile=request.POST.get('mobile', '').strip(),
        email=request.POST.get('email', '').strip(),
        address=request.POST.get('address', '').strip(),
        contact_person=request.POST.get('contact_person', '').strip(),
        payment_terms_days=int(request.POST.get('payment_terms_days', '0') or '0'),
        payment_terms_note=request.POST.get('payment_terms_note', '').strip(),
        credit_limit=_parse_money(request.POST.get('credit_limit', '')),
        currency=request.POST.get('currency', 'IRR') or 'IRR',
        bank_name=request.POST.get('bank_name', '').strip(),
        account_no=request.POST.get('account_no', '').strip(),
        sheba=request.POST.get('sheba', '').strip(),
        notes=request.POST.get('notes', '').strip())
    messages.success(request, f'طرف تجاری {party.code} ثبت شد.')
    return redirect('commerce:party_detail', pk=party.pk)


@login_required
def party_detail(request, pk):
    if not feature_enabled('commerce.parties'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.parties'})
    party = get_object_or_404(BusinessParty, pk=pk)
    return render(request, 'commerce/party_detail.html', {
        'party': party,
        'orders': party.purchase_orders.all()[:20],
        'invoices': party.purchase_invoices.all()[:20],
        'sales': party.sales_documents.all()[:20],
        'inquiries': party.inquiries.all()[:10],
        'contracts': party.contracts.all()[:10],
        'imports': party.import_shipments.all()[:10],
        'can_manage': _can_manage(request.user),
    })


# ─────────────────────────────────────────────────────────────
# سفارش خرید
# ─────────────────────────────────────────────────────────────
@login_required
def order_list(request):
    if not feature_enabled('commerce.purchase_orders'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.purchase_orders'})
    orders = PurchaseOrder.objects.select_related('supplier', 'created_by')
    status = request.GET.get('status', '')
    if status in PurchaseOrder.Status.values:
        orders = orders.filter(status=status)
    return render(request, 'commerce/order_list.html', {
        'orders': orders, 'status': status,
        'statuses': PurchaseOrder.Status.choices,
        'can_manage': _can_manage(request.user),
    })


@login_required
def order_new(request):
    if not feature_enabled('commerce.purchase_orders'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.purchase_orders'})
    if request.method != 'POST':
        from apps.inventory.models import Item, Warehouse
        return render(request, 'commerce/order_form.html', {
            'parties': BusinessParty.objects.filter(is_active=True),
            'items': Item.objects.filter(is_active=True),
            'warehouses': Warehouse.objects.all(),
            'requests': PurchaseRequest.objects.filter(
                status=PurchaseRequest.Status.APPROVED),
            'contracts': Contract.objects.filter(status=Contract.Status.ACTIVE),
        })
    supplier = get_object_or_404(BusinessParty, pk=request.POST.get('supplier_id'))
    from apps.inventory.models import Warehouse
    lines = _parse_lines(request.POST)
    if not lines:
        messages.error(request, 'سفارش حداقل یک ردیف لازم دارد.')
        return redirect('commerce:order_new')
    try:
        order = create_purchase_order(
            supplier=supplier, date=_parse_jalali(request.POST.get('date', ''))
            or jdatetime.date.today(), created_by=request.user,
            request=PurchaseRequest.objects.filter(
                pk=request.POST.get('request_id') or 0).first(),
            contract=Contract.objects.filter(pk=request.POST.get('contract_id') or 0).first(),
            warehouse=Warehouse.objects.filter(
                pk=request.POST.get('warehouse_id') or 0).first(),
            delivery_date=_parse_jalali(request.POST.get('delivery_date', '')),
            description=request.POST.get('description', ''),
            tax_percent=_parse_money(request.POST.get('tax_percent', '0') or '0'),
            discount_amount=_parse_money(request.POST.get('discount_amount', '')),
            extra_costs=_parse_money(request.POST.get('extra_costs', '')),
            lines=lines, confirm=request.POST.get('action') == 'confirm')
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
        return redirect('commerce:order_new')
    messages.success(request, f'سفارش {order.number} ثبت شد.')
    return redirect('commerce:order_detail', pk=order.pk)


@login_required
def order_detail(request, pk):
    if not feature_enabled('commerce.purchase_orders'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.purchase_orders'})
    order = get_object_or_404(
        PurchaseOrder.objects.select_related('supplier', 'warehouse', 'created_by',
                                             'confirmed_by'),
        pk=pk)
    return render(request, 'commerce/order_detail.html', {
        'order': order,
        'lines': order.lines.select_related('item'),
        'receipts': order.receipts.select_related('document', 'created_by'),
        'invoices': order.invoices.all(),
        'match': three_way_match(order) if feature_enabled('commerce.three_way_match') else [],
        'can_manage': _can_manage(request.user),
        'from_inventory': from_inventory_warehouses(),
    })


def from_inventory_warehouses():
    from apps.inventory.models import Warehouse
    return Warehouse.objects.all()


@login_required
@require_POST
def order_confirm(request, pk):
    order = get_object_or_404(PurchaseOrder, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان تأیید سفارش ندارید.')
        return redirect('commerce:order_detail', pk=pk)
    try:
        confirm_purchase_order(order, confirmed_by=request.user)
        messages.success(request, f'سفارش {order.number} تأیید شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('commerce:order_detail', pk=pk)


@login_required
@require_POST
def order_cancel(request, pk):
    order = get_object_or_404(PurchaseOrder, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان لغو سفارش ندارید.')
        return redirect('commerce:order_detail', pk=pk)
    try:
        cancel_purchase_order(order, reason=request.POST.get('reason', ''),
                              cancelled_by=request.user)
        messages.success(request, f'سفارش {order.number} لغو شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('commerce:order_detail', pk=pk)


@login_required
@require_POST
def order_receive(request, pk):
    """رسید انبار از سفارش — ایجاد سند رسید انبار در ماژول انبار."""
    order = get_object_or_404(PurchaseOrder, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان ثبت رسید ندارید.')
        return redirect('commerce:order_detail', pk=pk)
    if not feature_enabled('commerce.goods_receipt'):
        messages.error(request, 'قابلیت رسید از سفارش غیرفعال است.')
        return redirect('commerce:order_detail', pk=pk)
    from apps.inventory.models import Warehouse
    warehouse = Warehouse.objects.filter(pk=request.POST.get('warehouse_id')).first()
    if warehouse is None:
        warehouse = order.warehouse
    if warehouse is None:
        messages.error(request, 'انبار تحویل را انتخاب کنید.')
        return redirect('commerce:order_detail', pk=pk)
    rows = []
    for line in order.lines.all():
        raw = request.POST.get(f'receive_{line.pk}', '').strip().replace(',', '')
        if not raw:
            continue
        try:
            rows.append((line, Decimal(raw)))
        except ArithmeticError:
            continue
    try:
        receive_purchase_order(
            order, rows, warehouse=warehouse,
            date=_parse_jalali(request.POST.get('date', '')) or jdatetime.date.today(),
            created_by=request.user, note=request.POST.get('note', ''))
        messages.success(request, f'رسید انبار سفارش {order.number} ثبت شد.')
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
    return redirect('commerce:order_detail', pk=pk)


@login_required
def order_print(request, pk):
    order = get_object_or_404(
        PurchaseOrder.objects.select_related('supplier', 'warehouse'), pk=pk)
    return render(request, 'commerce/order_print.html', {
        'order': order, 'lines': order.lines.select_related('item'),
    })


# ─────────────────────────────────────────────────────────────
# فاکتور خرید
# ─────────────────────────────────────────────────────────────
@login_required
def invoice_list(request):
    if not feature_enabled('commerce.purchase_invoices'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.purchase_invoices'})
    invoices = PurchaseInvoice.objects.select_related('supplier', 'created_by')
    status = request.GET.get('status', '')
    if status in PurchaseInvoice.Status.values:
        invoices = invoices.filter(status=status)
    return render(request, 'commerce/invoice_list.html', {
        'invoices': invoices, 'status': status,
        'statuses': PurchaseInvoice.Status.choices,
        'kinds': PurchaseInvoice.Kind.choices,
        'can_manage': _can_manage(request.user),
    })


@login_required
def invoice_new(request):
    if not feature_enabled('commerce.purchase_invoices'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.purchase_invoices'})
    if request.method != 'POST':
        from apps.inventory.models import Item
        return render(request, 'commerce/invoice_form.html', {
            'parties': BusinessParty.objects.filter(is_active=True),
            'items': Item.objects.filter(is_active=True),
            'orders': PurchaseOrder.objects.exclude(
                status=PurchaseOrder.Status.CANCELLED),
            'kinds': PurchaseInvoice.Kind.choices,
        })
    supplier = get_object_or_404(BusinessParty, pk=request.POST.get('supplier_id'))
    order = PurchaseOrder.objects.filter(pk=request.POST.get('order_id') or 0).first()
    lines = _parse_lines(request.POST)
    if not lines:
        messages.error(request, 'فاکتور حداقل یک ردیف لازم دارد.')
        return redirect('commerce:invoice_new')
    try:
        invoice = create_purchase_invoice(
            supplier=supplier, date=_parse_jalali(request.POST.get('date', ''))
            or jdatetime.date.today(), created_by=request.user, order=order,
            invoice_no=request.POST.get('invoice_no', '').strip(),
            kind=request.POST.get('kind', 'goods'),
            description=request.POST.get('description', ''),
            tax_percent=_parse_money(request.POST.get('tax_percent', '0') or '0'),
            discount_amount=_parse_money(request.POST.get('discount_amount', '')),
            extra_costs=_parse_money(request.POST.get('extra_costs', '')),
            lines=lines, finalize=request.POST.get('action') == 'finalize')
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
        return redirect('commerce:invoice_new')
    messages.success(request, f'فاکتور خرید {invoice.number} ثبت شد.')
    return redirect('commerce:invoice_detail', pk=invoice.pk)


@login_required
def invoice_detail(request, pk):
    if not feature_enabled('commerce.purchase_invoices'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.purchase_invoices'})
    invoice = get_object_or_404(
        PurchaseInvoice.objects.select_related('supplier', 'order', 'created_by'),
        pk=pk)
    return render(request, 'commerce/invoice_detail.html', {
        'invoice': invoice,
        'lines': invoice.lines.select_related('item', 'order_line'),
        'installments': invoice.installments.all(),
        'can_manage': _can_manage(request.user),
    })


@login_required
@require_POST
def invoice_finalize(request, pk):
    invoice = get_object_or_404(PurchaseInvoice, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان قطعی فاکتور ندارید.')
        return redirect('commerce:invoice_detail', pk=pk)
    try:
        finalize_purchase_invoice(invoice, confirmed_by=request.user)
        messages.success(request, f'فاکتور {invoice.number} قطعی شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('commerce:invoice_detail', pk=pk)


@login_required
@require_POST
def invoice_cancel(request, pk):
    invoice = get_object_or_404(PurchaseInvoice, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان ابطال فاکتور ندارید.')
        return redirect('commerce:invoice_detail', pk=pk)
    try:
        cancel_purchase_invoice(invoice, reason=request.POST.get('reason', ''),
                                cancelled_by=request.user)
        messages.success(request, f'فاکتور {invoice.number} ابطال شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('commerce:invoice_detail', pk=pk)


@login_required
def invoice_print(request, pk):
    invoice = get_object_or_404(
        PurchaseInvoice.objects.select_related('supplier'), pk=pk)
    return render(request, 'commerce/invoice_print.html', {
        'invoice': invoice, 'lines': invoice.lines.all(),
    })


@login_required
@require_POST
def invoice_installments(request, pk):
    """تقسیط فاکتور — سررسیدها به تفکیک."""
    invoice = get_object_or_404(PurchaseInvoice, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان تقسیط ندارید.')
        return redirect('commerce:invoice_detail', pk=pk)
    schedule = []
    for i in range(12):
        raw_amount = request.POST.get(f'inst_amount_{i}', '').strip().replace(',', '')
        raw_date = request.POST.get(f'inst_date_{i}', '').strip()
        if not raw_amount or not raw_date:
            continue
        due = _parse_jalali(raw_date)
        if due:
            schedule.append((due, Decimal(raw_amount)))
    if not schedule:
        messages.error(request, 'حداقل یک قسط وارد کنید.')
        return redirect('commerce:invoice_detail', pk=pk)
    create_installments(purchase_invoice=invoice, schedule=schedule)
    messages.success(request, f'{len(schedule)} قسط ثبت شد.')
    return redirect('commerce:invoice_detail', pk=pk)


@login_required
@require_POST
def installment_pay(request, pk, inst_pk):
    invoice = get_object_or_404(PurchaseInvoice, pk=pk)
    installment = get_object_or_404(PaymentSchedule, pk=inst_pk,
                                    purchase_invoice=invoice)
    if not _can_manage(request.user):
        messages.error(request, 'امکان ثبت پرداخت ندارید.')
        return redirect('commerce:invoice_detail', pk=pk)
    try:
        pay_installment(installment,
                        paid_date=_parse_jalali(request.POST.get('paid_date', ''))
                        or jdatetime.date.today())
        messages.success(request, 'قسط پرداخت شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('commerce:invoice_detail', pk=pk)


# ─────────────────────────────────────────────────────────────
# فروش
# ─────────────────────────────────────────────────────────────
@login_required
def sales_list(request):
    if not feature_enabled('commerce.sales'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.sales'})
    docs = SalesDocument.objects.select_related('customer', 'created_by')
    kind = request.GET.get('kind', '')
    if kind in SalesDocument.Kind.values:
        docs = docs.filter(kind=kind)
    return render(request, 'commerce/sales_list.html', {
        'docs': docs, 'kind': kind, 'kinds': SalesDocument.Kind.choices,
        'can_manage': _can_manage(request.user),
    })


@login_required
def sales_new(request):
    if not feature_enabled('commerce.sales'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.sales'})
    if request.method != 'POST':
        from apps.inventory.models import Item, Warehouse
        return render(request, 'commerce/sales_form.html', {
            'parties': BusinessParty.objects.filter(
                is_active=True).exclude(kind=BusinessParty.Kind.SUPPLIER),
            'items': Item.objects.filter(is_active=True),
            'warehouses': Warehouse.objects.all(),
            'kinds': SalesDocument.Kind.choices,
        })
    customer = get_object_or_404(BusinessParty, pk=request.POST.get('customer_id'))
    from apps.inventory.models import Warehouse
    lines = _parse_lines(request.POST)
    if not lines:
        messages.error(request, 'سند حداقل یک ردیف لازم دارد.')
        return redirect('commerce:sales_new')
    try:
        doc = create_sales_document(
            customer=customer, kind=request.POST.get('kind', 'invoice'),
            date=_parse_jalali(request.POST.get('date', '')) or jdatetime.date.today(),
            created_by=request.user,
            warehouse=Warehouse.objects.filter(
                pk=request.POST.get('warehouse_id') or 0).first(),
            due_date=_parse_jalali(request.POST.get('due_date', '')),
            description=request.POST.get('description', ''),
            tax_percent=_parse_money(request.POST.get('tax_percent', '0') or '0'),
            discount_amount=_parse_money(request.POST.get('discount_amount', '')),
            lines=lines, finalize=request.POST.get('action') == 'finalize')
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
        return redirect('commerce:sales_new')
    messages.success(request, f'{doc.get_kind_display()} {doc.number} ثبت شد.')
    return redirect('commerce:sales_detail', pk=doc.pk)


@login_required
def sales_detail(request, pk):
    if not feature_enabled('commerce.sales'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.sales'})
    doc = get_object_or_404(
        SalesDocument.objects.select_related('customer', 'warehouse', 'created_by'),
        pk=pk)
    return render(request, 'commerce/sales_detail.html', {
        'doc': doc, 'lines': doc.lines.select_related('item'),
        'installments': doc.installments.all(),
        'can_manage': _can_manage(request.user),
    })


@login_required
@require_POST
def sales_finalize(request, pk):
    doc = get_object_or_404(SalesDocument, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان قطعی سند ندارید.')
        return redirect('commerce:sales_detail', pk=pk)
    try:
        finalize_sales_document(doc, confirmed_by=request.user)
        messages.success(request, f'{doc.get_kind_display()} {doc.number} قطعی شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('commerce:sales_detail', pk=pk)


@login_required
@require_POST
def sales_cancel(request, pk):
    doc = get_object_or_404(SalesDocument, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان ابطال سند ندارید.')
        return redirect('commerce:sales_detail', pk=pk)
    try:
        cancel_sales_document(doc, reason=request.POST.get('reason', ''),
                              cancelled_by=request.user)
        messages.success(request, f'سند {doc.number} ابطال شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('commerce:sales_detail', pk=pk)


@login_required
def sales_print(request, pk):
    doc = get_object_or_404(SalesDocument.objects.select_related('customer'), pk=pk)
    return render(request, 'commerce/sales_print.html', {
        'doc': doc, 'lines': doc.lines.all(),
    })


# ─────────────────────────────────────────────────────────────
# خرید خارجی
# ─────────────────────────────────────────────────────────────
@login_required
def import_list(request):
    if not feature_enabled('commerce.import_orders'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.import_orders'})
    shipments = ImportShipment.objects.select_related('supplier', 'order')
    return render(request, 'commerce/import_list.html', {
        'shipments': shipments, 'statuses': ImportShipment.Status.choices,
        'can_manage': _can_manage(request.user),
    })


@login_required
def import_new(request):
    if not feature_enabled('commerce.import_orders'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.import_orders'})
    if request.method != 'POST':
        return render(request, 'commerce/import_form.html', {
            'parties': BusinessParty.objects.filter(is_active=True),
            'orders': PurchaseOrder.objects.exclude(
                status=PurchaseOrder.Status.CANCELLED),
        })
    supplier = get_object_or_404(BusinessParty, pk=request.POST.get('supplier_id'))
    try:
        shipment = create_import_shipment(
            supplier=supplier, created_by=request.user,
            order=PurchaseOrder.objects.filter(pk=request.POST.get('order_id') or 0).first(),
            currency=request.POST.get('currency', 'USD') or 'USD',
            exchange_rate=_parse_money(request.POST.get('exchange_rate', '1') or '1'),
            goods_value=_parse_money(request.POST.get('goods_value', '')),
            proforma_no=request.POST.get('proforma_no', '').strip(),
            lc_no=request.POST.get('lc_no', '').strip(),
            bl_no=request.POST.get('bl_no', '').strip(),
            customs_no=request.POST.get('customs_no', '').strip(),
            proforma_date=_parse_jalali(request.POST.get('proforma_date', '')),
            bl_date=_parse_jalali(request.POST.get('bl_date', '')),
            customs_date=_parse_jalali(request.POST.get('customs_date', '')),
            cost_freight=_parse_money(request.POST.get('cost_freight', '')),
            cost_insurance=_parse_money(request.POST.get('cost_insurance', '')),
            cost_customs=_parse_money(request.POST.get('cost_customs', '')),
            cost_clearing=_parse_money(request.POST.get('cost_clearing', '')),
            description=request.POST.get('description', ''))
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
        return redirect('commerce:import_new')
    messages.success(request, f'محموله {shipment.number} ثبت شد.')
    return redirect('commerce:import_detail', pk=shipment.pk)


@login_required
def import_detail(request, pk):
    if not feature_enabled('commerce.import_orders'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.import_orders'})
    shipment = get_object_or_404(
        ImportShipment.objects.select_related('supplier', 'order'), pk=pk)
    landed = []
    if feature_enabled('commerce.import_costs'):
        landed = landed_unit_costs(shipment)
    return render(request, 'commerce/import_detail.html', {
        'shipment': shipment, 'costs': shipment.costs.all(), 'landed': landed,
        'statuses': ImportShipment.Status.choices,
        'can_manage': _can_manage(request.user),
    })


@login_required
@require_POST
def import_cost_add(request, pk):
    shipment = get_object_or_404(ImportShipment, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان ثبت هزینه ندارید.')
        return redirect('commerce:import_detail', pk=pk)
    try:
        add_import_cost(shipment, title=request.POST.get('title', '').strip(),
                        stage=request.POST.get('stage', '').strip(),
                        amount=_parse_money(request.POST.get('amount', '')))
        messages.success(request, 'هزینه ثبت شد.')
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
    return redirect('commerce:import_detail', pk=pk)


@login_required
@require_POST
def import_status(request, pk):
    shipment = get_object_or_404(ImportShipment, pk=pk)
    if not _can_manage(request.user):
        messages.error(request, 'امکان تغییر وضعیت ندارید.')
        return redirect('commerce:import_detail', pk=pk)
    try:
        set_import_status(shipment, request.POST.get('status', ''))
        messages.success(request, 'وضعیت محموله به‌روزرسانی شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('commerce:import_detail', pk=pk)


# ─────────────────────────────────────────────────────────────
# گزارش‌ها و داشبورد
# ─────────────────────────────────────────────────────────────
@login_required
def dashboard(request):
    """داشبورد بازرگانی — اسناد، تعهدات باز و هشدارها."""
    return render(request, 'commerce/dashboard.html', {
        'requests': PurchaseRequest.objects.all()[:6],
        'orders': PurchaseOrder.objects.all()[:6],
        'invoices': PurchaseInvoice.objects.all()[:6],
        'sales': SalesDocument.objects.all()[:6],
        'commitments': open_commitments()[:8] if feature_enabled('commerce.commitments') else [],
        'can_manage': _can_manage(request.user),
    })


@login_required
def report_commitments(request):
    if not feature_enabled('commerce.commitments'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.commitments'})
    rows = open_commitments()
    return render(request, 'commerce/report_commitments.html', {
        'rows': rows,
        'total': sum(int(r['installment'].amount) for r in rows),
    })


@login_required
def report_purchases(request):
    """گزارش خرید به تفکیک تأمین‌کننده — بازه شمسی."""
    from apps.secretariat.views import _parse_jalali_input
    date_from = _parse_jalali_input(request.GET.get('date_from', ''))
    date_to = _parse_jalali_input(request.GET.get('date_to', ''))
    invoices = PurchaseInvoice.objects.filter(
        status=PurchaseInvoice.Status.FINAL).select_related('supplier')
    if date_from:
        invoices = invoices.filter(date__gte=date_from.togregorian())
    if date_to:
        invoices = invoices.filter(date__lte=date_to.togregorian())
    summary: dict = {}
    for inv in invoices:
        row = summary.setdefault(
            inv.supplier_id, {'supplier': inv.supplier, 'count': 0, 'total': 0})
        row['count'] += 1
        row['total'] += inv.total
    rows = sorted(summary.values(), key=lambda r: -r['total'])
    return render(request, 'commerce/report_purchases.html', {
        'rows': rows, 'invoices': invoices[:100],
        'date_from': request.GET.get('date_from', ''),
        'date_to': request.GET.get('date_to', ''),
        'total': sum(r['total'] for r in rows),
    })


@login_required
def report_sales(request):
    from apps.secretariat.views import _parse_jalali_input
    date_from = _parse_jalali_input(request.GET.get('date_from', ''))
    date_to = _parse_jalali_input(request.GET.get('date_to', ''))
    docs = SalesDocument.objects.filter(
        status=SalesDocument.Status.FINAL).select_related('customer')
    if date_from:
        docs = docs.filter(date__gte=date_from.togregorian())
    if date_to:
        docs = docs.filter(date__lte=date_to.togregorian())
    summary: dict = {}
    for doc in docs:
        row = summary.setdefault(
            doc.customer_id, {'customer': doc.customer, 'count': 0, 'total': 0})
        row['count'] += 1
        row['total'] += doc.total
    rows = sorted(summary.values(), key=lambda r: -r['total'])
    return render(request, 'commerce/report_sales.html', {
        'rows': rows, 'docs': docs[:100],
        'date_from': request.GET.get('date_from', ''),
        'date_to': request.GET.get('date_to', ''),
        'total': sum(r['total'] for r in rows),
    })


@login_required
def purchases_excel(request):
    if not feature_enabled('commerce.excel_reports'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'commerce.excel_reports'})
    from apps.inventory.views import _excel_response
    invoices = PurchaseInvoice.objects.select_related('supplier').all()
    rows = [[str(inv.date), inv.number, inv.invoice_no, inv.supplier.name,
             inv.get_kind_display(), inv.get_status_display(),
             float(inv.subtotal), float(inv.discount_amount),
             float(inv.tax_percent), float(inv.total)]
            for inv in invoices]
    return _excel_response(
        'purchases.xlsx',
        ['تاریخ', 'شماره سند', 'شماره فاکتور', 'تأمین‌کننده', 'نوع', 'وضعیت',
         'جمع', 'تخفیف', 'مالیات ٪', 'کل'],
        rows)
