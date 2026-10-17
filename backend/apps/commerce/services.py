"""سرویس‌های بازرگانی — نقطه ورود یکتای چرخه خرید/فروش/واردات.

چرخه خرید (P2P): درخواست → استعلام → سفارش → رسید انبار (سند انبار) →
فاکتور → تطبیق سه‌طرفه → تقسیط/تسویه. فروش: پیش‌فاکتور → فاکتور (حواله انبار) → برگشت.
"""
from __future__ import annotations

from decimal import Decimal

from django.db import transaction
from django.utils import timezone

from .models import (BusinessParty, CommerceSequence, Contract, GoodsReceipt,
                     ImportCost, ImportShipment, Inquiry, PaymentSchedule,
                     PurchaseInvoice, PurchaseInvoiceLine, PurchaseOrder,
                     PurchaseOrderLine, PurchaseRequest, SalesDocument,
                     SalesDocumentLine)


# ─────────────────────────────────────────────────────────────
# شماره‌گذاری سالانه اسناد بازرگانی
# ─────────────────────────────────────────────────────────────
def next_commerce_number(year: int, doc_type: str) -> tuple[str, int]:
    """شماره بعدی سند در سال (PO-1404-0001) — اتمیک با شمارنده اختصاصی."""
    with transaction.atomic():
        seq, _ = CommerceSequence.objects.get_or_create(year=year, doc_type=doc_type)
        seq.last_seq += 1
        seq.save(update_fields=['last_seq'])
        return f'{doc_type}-{year}-{seq.last_seq:04d}', seq.last_seq


def _managers():
    from django.contrib.auth import get_user_model
    User = get_user_model()
    return User.objects.filter(is_active=True, is_superuser=True)


# ─────────────────────────────────────────────────────────────
# درخواست خرید و استعلام (سازگار با نسخه پایه)
# ─────────────────────────────────────────────────────────────
def submit_request(purchase: PurchaseRequest, user) -> PurchaseRequest:
    """ارسال درخواست برای تأیید + اعلان مدیران."""
    purchase.status = PurchaseRequest.Status.SUBMITTED
    purchase.save(update_fields=['status', 'updated_at'])
    for manager in _managers():
        if manager.pk != user.pk:
            send = _notify
            send(manager, f'درخواست خرید تازه: {purchase.title}',
                 purchase.description[:200], f'/commerce/{purchase.pk}/')
    return purchase


def _notify(user, title, body='', url=''):
    from apps.core.services import send_notification
    send_notification(user, title, body, url)


def decide_request(purchase: PurchaseRequest, user, *, approve: bool,
                   note: str = '') -> PurchaseRequest:
    """تأیید/رد درخواست + اعلان درخواست‌کننده."""
    purchase.status = (PurchaseRequest.Status.APPROVED if approve
                       else PurchaseRequest.Status.REJECTED)
    purchase.decided_by = user
    purchase.decision_note = note[:300]
    purchase.save(update_fields=['status', 'decided_by', 'decision_note',
                                 'updated_at'])
    _notify(purchase.requested_by,
            f'درخواست خرید {purchase.title} ' + ('تأیید شد' if approve else 'رد شد'),
            note[:200], f'/commerce/{purchase.pk}/')
    return purchase


def add_inquiry(*, purchase: PurchaseRequest, supplier_name: str,
                quote_amount, quote_note: str = '', supplier: BusinessParty | None = None,
                valid_until=None, delivery_days: int = 0) -> Inquiry:
    if supplier is not None and not supplier_name:
        supplier_name = supplier.name
    return Inquiry.objects.create(
        request=purchase, supplier=supplier, supplier_name=supplier_name,
        quote_amount=quote_amount, quote_note=quote_note[:300],
        valid_until=valid_until, delivery_days=delivery_days)


def select_inquiry(inquiry: Inquiry) -> Inquiry:
    """انتخاب پیشنهاد برنده — سایر پیشنهادها کنار می‌روند."""
    inquiry.request.inquiries.update(selected=False)
    inquiry.selected = True
    inquiry.save(update_fields=['selected', 'updated_at'])
    return inquiry


def create_contract(*, title: str, party: str, amount, start_date,
                    end_date=None, purchase: PurchaseRequest | None = None,
                    description: str = '', kind: str = 'purchase',
                    party_ref: BusinessParty | None = None,
                    guarantee_amount=0) -> Contract:
    contract = Contract.objects.create(
        title=title, party=party or (party_ref.name if party_ref else ''),
        party_ref=party_ref, amount=amount, start_date=start_date,
        end_date=end_date, purchase_request=purchase, kind=kind,
        guarantee_amount=guarantee_amount, description=description[:2000])
    if purchase is not None:
        purchase.status = PurchaseRequest.Status.DONE
        purchase.save(update_fields=['status', 'updated_at'])
    return contract


# ─────────────────────────────────────────────────────────────
# سفارش خرید
# ─────────────────────────────────────────────────────────────
def create_purchase_order(*, supplier: BusinessParty, date, created_by=None,
                          request: PurchaseRequest | None = None,
                          inquiry: Inquiry | None = None,
                          contract: Contract | None = None,
                          warehouse=None, delivery_date=None,
                          description: str = '', currency: str = 'IRR',
                          exchange_rate=1, tax_percent=0, discount_amount=0,
                          extra_costs=0, lines: list[dict] | None = None,
                          confirm: bool = False) -> PurchaseOrder:
    """ثبت سفارش خرید چندردیفی — با شماره خودکار سالانه."""
    order = PurchaseOrder(
        supplier=supplier, request=request, inquiry=inquiry, contract=contract,
        date=date, delivery_date=delivery_date, warehouse=warehouse,
        description=description[:300], currency=currency,
        exchange_rate=exchange_rate or 1, tax_percent=tax_percent,
        discount_amount=discount_amount, extra_costs=extra_costs,
        created_by=created_by, status=PurchaseOrder.Status.DRAFT)
    order.assign_number()
    order.save()
    for i, line_data in enumerate(lines or []):
        add_purchase_order_line(order, sequence=i, **line_data)
    if confirm:
        confirm_purchase_order(order, confirmed_by=created_by)
    return order


def add_purchase_order_line(order: PurchaseOrder, *, description: str,
                            quantity, unit_price, item=None, unit_name: str = 'عدد',
                            discount_percent=0, sequence: int = 0) -> PurchaseOrderLine:
    if not order.is_editable:
        raise ValueError('فقط سفارش پیش‌نویس یا تأییدشده قابل ویرایش است.')
    quantity = Decimal(str(quantity))
    if quantity <= 0:
        raise ValueError('تعداد ردیف باید بزرگ‌تر از صفر باشد.')
    return PurchaseOrderLine.objects.create(
        order=order, item=item, description=description[:300], quantity=quantity,
        unit_name=unit_name, unit_price=Decimal(str(unit_price)),
        discount_percent=discount_percent)


def confirm_purchase_order(order: PurchaseOrder, confirmed_by=None) -> PurchaseOrder:
    if order.status == PurchaseOrder.Status.CANCELLED:
        raise ValueError('سفارش لغوشده قابل تأیید نیست.')
    if not order.lines.exists():
        raise ValueError('سفارش بدون ردیف قابل تأیید نیست.')
    order.status = PurchaseOrder.Status.CONFIRMED
    order.confirmed_by = confirmed_by or order.created_by
    order.confirmed_at = timezone.now()
    order.save(update_fields=['status', 'confirmed_by', 'confirmed_at', 'updated_at'])
    if order.created_by is not None:
        _notify(order.created_by,
                f'سفارش خرید {order.number} تأیید شد',
                f'تأمین‌کننده: {order.supplier.name}', f'/commerce/orders/{order.pk}/')
    return order


def cancel_purchase_order(order: PurchaseOrder, *, reason: str = '',
                          cancelled_by=None) -> PurchaseOrder:
    if order.status == PurchaseOrder.Status.CANCELLED:
        raise ValueError('این سفارش قبلاً لغو شده است.')
    if GoodsReceipt.objects.filter(order=order).exists():
        raise ValueError('سفارش دارای رسید انبار است؛ قابل لغو نیست.')
    order.status = PurchaseOrder.Status.CANCELLED
    order.cancel_reason = reason[:300]
    order.cancelled_at = timezone.now()
    order.save(update_fields=['status', 'cancel_reason', 'cancelled_at', 'updated_at'])
    return order


def receive_purchase_order(order: PurchaseOrder, rows: list[tuple],
                           *, warehouse, date, created_by=None,
                           note: str = '') -> GoodsReceipt:
    """رسید انبار از سفارش — ایجاد سند رسید انبار + به‌روزرسانی تحویل‌شده‌ها.

    rows: لیست (ردیف سفارش، تعداد تحویلی)
    """
    from apps.inventory.models import StockDocument, StockMoveCategory
    from apps.inventory.services import create_document as create_stock_document
    if order.status not in (PurchaseOrder.Status.CONFIRMED, PurchaseOrder.Status.PARTIAL):
        raise ValueError('فقط سفارش تأییدشده/تحویل جزئی قابل رسید است.')
    if not rows:
        raise ValueError('حداقل یک ردیف برای رسید لازم است.')
    with transaction.atomic():
        lines = []
        for order_line, qty in rows:
            qty = Decimal(str(qty))
            remaining = order_line.quantity - order_line.received_quantity
            if qty <= 0:
                raise ValueError('تعداد رسید باید بزرگ‌تر از صفر باشد.')
            if qty > remaining:
                raise ValueError(
                    f'تعداد رسید {order_line.description} از مانده سفارش بیشتر است '
                    f'(مانده: {remaining}).')
            lines.append({
                'item': order_line.item, 'quantity': qty,
                'unit_price': order_line.unit_price,
                'description': f'رسید سفارش {order.number}'})
        document = create_stock_document(
            doc_type='receive', warehouse=warehouse, date=date, created_by=created_by,
            category=StockMoveCategory.PURCHASE, reference=order.number,
            description=note or f'رسید سفارش خرید {order.number}',
            finalize=True, lines=lines)
        receipt = GoodsReceipt.objects.create(
            order=order, document=document, date=date, created_by=created_by,
            note=note[:300])
        for order_line, qty in rows:
            order_line.received_quantity += Decimal(str(qty))
            order_line.save(update_fields=['received_quantity', 'updated_at'])
        _update_order_receipt_status(order)
    return receipt


def _update_order_receipt_status(order: PurchaseOrder) -> None:
    lines = list(order.lines.all())
    fully = all(l.received_quantity >= l.quantity for l in lines)
    any_received = any(l.received_quantity > 0 for l in lines)
    if fully:
        order.status = PurchaseOrder.Status.RECEIVED
    elif any_received:
        order.status = PurchaseOrder.Status.PARTIAL
    order.save(update_fields=['status', 'updated_at'])


# ─────────────────────────────────────────────────────────────
# فاکتور خرید
# ─────────────────────────────────────────────────────────────
def create_purchase_invoice(*, supplier: BusinessParty, date, created_by=None,
                            order: PurchaseOrder | None = None,
                            invoice_no: str = '', kind: str = 'goods',
                            description: str = '', currency: str = 'IRR',
                            exchange_rate=1, tax_percent=0, discount_amount=0,
                            extra_costs=0, lines: list[dict] | None = None,
                            finalize: bool = False) -> PurchaseInvoice:
    """ثبت فاکتور خرید (کالا/خدمت/دارایی) — چندردیفی با شماره خودکار."""
    invoice = PurchaseInvoice(
        supplier=supplier, order=order, invoice_no=invoice_no[:40], kind=kind,
        date=date, description=description[:300], currency=currency,
        exchange_rate=exchange_rate or 1, tax_percent=tax_percent,
        discount_amount=discount_amount, extra_costs=extra_costs,
        created_by=created_by, status=PurchaseInvoice.Status.DRAFT)
    invoice.assign_number()
    invoice.save()
    for i, line_data in enumerate(lines or []):
        add_purchase_invoice_line(invoice, **line_data)
    if finalize:
        finalize_purchase_invoice(invoice, confirmed_by=created_by)
    return invoice


def add_purchase_invoice_line(invoice: PurchaseInvoice, *, description: str,
                              quantity, unit_price, item=None,
                              unit_name: str = 'عدد', discount_percent=0,
                              order_line: PurchaseOrderLine | None = None
                              ) -> PurchaseInvoiceLine:
    if not invoice.is_editable:
        raise ValueError('فقط فاکتور پیش‌نویس قابل ویرایش است.')
    quantity = Decimal(str(quantity))
    if quantity <= 0:
        raise ValueError('تعداد ردیف باید بزرگ‌تر از صفر باشد.')
    return PurchaseInvoiceLine.objects.create(
        invoice=invoice, order_line=order_line, item=item,
        description=description[:300], quantity=quantity, unit_name=unit_name,
        unit_price=Decimal(str(unit_price)), discount_percent=discount_percent)


def finalize_purchase_invoice(invoice: PurchaseInvoice, confirmed_by=None) -> PurchaseInvoice:
    """قطعی فاکتور خرید — به‌روزرسانی تعداد فاکتورشده سفارش."""
    if invoice.status == PurchaseInvoice.Status.FINAL:
        raise ValueError('این فاکتور قبلاً قطعی شده است.')
    if invoice.status == PurchaseInvoice.Status.CANCELLED:
        raise ValueError('فاکتور ابطال‌شده قابل قطعی نیست.')
    if not invoice.lines.exists():
        raise ValueError('فاکتور بدون ردیف قابل قطعی نیست.')
    with transaction.atomic():
        for line in invoice.lines.select_related('order_line'):
            if line.order_line_id:
                line.order_line.invoiced_quantity += line.quantity
                line.order_line.save(update_fields=['invoiced_quantity', 'updated_at'])
        invoice.status = PurchaseInvoice.Status.FINAL
        invoice.confirmed_by = confirmed_by or invoice.created_by
        invoice.confirmed_at = timezone.now()
        invoice.save(update_fields=['status', 'confirmed_by', 'confirmed_at', 'updated_at'])
    return invoice


def cancel_purchase_invoice(invoice: PurchaseInvoice, *, reason: str = '',
                            cancelled_by=None) -> PurchaseInvoice:
    if invoice.status == PurchaseInvoice.Status.CANCELLED:
        raise ValueError('این فاکتور قبلاً ابطال شده است.')
    with transaction.atomic():
        if invoice.status == PurchaseInvoice.Status.FINAL:
            for line in invoice.lines.select_related('order_line'):
                if line.order_line_id:
                    line.order_line.invoiced_quantity = max(
                        line.order_line.invoiced_quantity - line.quantity, Decimal('0'))
                    line.order_line.save(update_fields=['invoiced_quantity', 'updated_at'])
        invoice.status = PurchaseInvoice.Status.CANCELLED
        invoice.cancel_reason = reason[:300]
        invoice.cancelled_at = timezone.now()
        invoice.save(update_fields=['status', 'cancel_reason', 'cancelled_at', 'updated_at'])
    return invoice


def three_way_match(order: PurchaseOrder) -> list[dict]:
    """تطبیق سه‌طرفه سفارش/رسید/فاکتور — به تفکیک هر ردیف."""
    rows = []
    for line in order.lines.all():
        rows.append({
            'line': line,
            'ordered': line.quantity,
            'received': line.received_quantity,
            'invoiced': line.invoiced_quantity,
            'match': (line.received_quantity >= line.quantity
                      and line.invoiced_quantity >= line.quantity),
        })
    return rows


# ─────────────────────────────────────────────────────────────
# فروش
# ─────────────────────────────────────────────────────────────
def create_sales_document(*, customer: BusinessParty, date, kind: str = 'invoice',
                          created_by=None, warehouse=None, due_date=None,
                          description: str = '', currency: str = 'IRR',
                          exchange_rate=1, tax_percent=0, discount_amount=0,
                          lines: list[dict] | None = None,
                          finalize: bool = False) -> SalesDocument:
    """ثبت سند فروش (پیش‌فاکتور/سفارش/فاکتور/برگشت) — چندردیفی."""
    doc = SalesDocument(
        customer=customer, kind=kind, warehouse=warehouse, date=date,
        due_date=due_date, description=description[:300], currency=currency,
        exchange_rate=exchange_rate or 1, tax_percent=tax_percent,
        discount_amount=discount_amount, created_by=created_by,
        status=SalesDocument.Status.DRAFT)
    doc.assign_number()
    doc.save()
    for line_data in lines or []:
        add_sales_line(doc, **line_data)
    if finalize:
        finalize_sales_document(doc, confirmed_by=created_by)
    return doc


def add_sales_line(document: SalesDocument, *, description: str, quantity,
                   unit_price, item=None, unit_name: str = 'عدد',
                   discount_percent=0) -> SalesDocumentLine:
    if not document.is_editable:
        raise ValueError('فقط سند پیش‌نویس قابل ویرایش است.')
    quantity = Decimal(str(quantity))
    if quantity <= 0:
        raise ValueError('تعداد ردیف باید بزرگ‌تر از صفر باشد.')
    return SalesDocumentLine.objects.create(
        document=document, item=item, description=description[:300],
        quantity=quantity, unit_name=unit_name, unit_price=Decimal(str(unit_price)),
        discount_percent=discount_percent)


def finalize_sales_document(document: SalesDocument, confirmed_by=None) -> SalesDocument:
    """قطعی سند فروش — فاکتور: حواله انبار، برگشت: رسید برگشتی."""
    from apps.inventory.models import StockMoveCategory
    from apps.inventory.services import create_document as create_stock_document
    if document.status != SalesDocument.Status.DRAFT:
        raise ValueError('فقط سند پیش‌نویس قابل قطعی است.')
    if not document.lines.exists():
        raise ValueError('سند بدون ردیف قابل قطعی نیست.')
    with transaction.atomic():
        if document.kind in (SalesDocument.Kind.INVOICE, SalesDocument.Kind.RETURN):
            if document.warehouse_id is None:
                raise ValueError('برای فاکتور/برگشت فروش، انبار لازم است.')
            if document.kind == SalesDocument.Kind.INVOICE:
                doc_type = 'issue'
                category = StockMoveCategory.SALE
            else:
                doc_type = 'receive'
                category = StockMoveCategory.RETURN_IN
            lines = []
            for line in document.lines.all():
                if line.item_id is None:
                    raise ValueError(
                        f'ردیف «{line.description}» کالای انبار ندارد؛ '
                        'فاکتور فروش کالایی باید به انبار وصل باشد.')
                lines.append({
                    'item': line.item, 'quantity': line.quantity,
                    'unit_price': line.unit_price,
                    'description': f'{document.get_kind_display()} {document.number}'})
            create_stock_document(
                doc_type=doc_type, warehouse=document.warehouse, date=document.date,
                created_by=document.created_by, category=category,
                reference=document.number,
                description=f'{document.get_kind_display()} {document.number}',
                finalize=True, lines=lines)
        document.status = SalesDocument.Status.FINAL
        document.confirmed_by = confirmed_by or document.created_by
        document.confirmed_at = timezone.now()
        document.save(update_fields=['status', 'confirmed_by', 'confirmed_at', 'updated_at'])
    return document


def cancel_sales_document(document: SalesDocument, *, reason: str = '',
                          cancelled_by=None) -> SalesDocument:
    if document.status == SalesDocument.Status.CANCELLED:
        raise ValueError('این سند قبلاً ابطال شده است.')
    from apps.inventory.models import StockDocument
    if document.status == SalesDocument.Status.FINAL and document.kind in (
            SalesDocument.Kind.INVOICE, SalesDocument.Kind.RETURN):
        stock_doc = StockDocument.objects.filter(
            reference=document.number, status='final').first()
        if stock_doc is not None:
            from apps.inventory.services import cancel_document
            cancel_document(stock_doc, reason=f'ابطال {document.number}',
                            cancelled_by=cancelled_by)
    document.status = SalesDocument.Status.CANCELLED
    document.cancel_reason = reason[:300]
    document.cancelled_at = timezone.now()
    document.save(update_fields=['status', 'cancel_reason', 'cancelled_at', 'updated_at'])
    return document


# ─────────────────────────────────────────────────────────────
# تقسیط و تسویه
# ─────────────────────────────────────────────────────────────
def create_installments(*, purchase_invoice: PurchaseInvoice | None = None,
                        sales_document: SalesDocument | None = None,
                        schedule: list[tuple]) -> list[PaymentSchedule]:
    """تقسیط فاکتور — لیست (تاریخ سررسید، مبلغ)."""
    result = []
    for due_date, amount in schedule:
        result.append(PaymentSchedule.objects.create(
            purchase_invoice=purchase_invoice, sales_document=sales_document,
            due_date=due_date, amount=Decimal(str(amount))))
    return result


def pay_installment(installment: PaymentSchedule, paid_date=None) -> PaymentSchedule:
    if installment.is_paid:
        raise ValueError('این قسط قبلاً پرداخت شده است.')
    installment.is_paid = True
    installment.paid_date = paid_date
    installment.save(update_fields=['is_paid', 'paid_date', 'updated_at'])
    return installment


def open_commitments() -> list[dict]:
    """تعهدات باز — اقساط سررسیدشده/نشده پرداخت‌نشده."""
    rows = []
    for inst in PaymentSchedule.objects.filter(is_paid=False).select_related(
            'purchase_invoice__supplier', 'sales_document__customer'):
        if inst.purchase_invoice_id:
            party = inst.purchase_invoice.supplier.name
            ref = inst.purchase_invoice.number
            url = f'/commerce/invoices/{inst.purchase_invoice_id}/'
        else:
            party = inst.sales_document.customer.name
            ref = inst.sales_document.number
            url = f'/commerce/sales/{inst.sales_document_id}/'
        rows.append({'installment': inst, 'party': party, 'ref': ref, 'url': url})
    return rows


# ─────────────────────────────────────────────────────────────
# خرید خارجی
# ─────────────────────────────────────────────────────────────
def create_import_shipment(*, supplier: BusinessParty, created_by=None,
                           order: PurchaseOrder | None = None,
                           currency: str = 'USD', exchange_rate=1, goods_value=0,
                           proforma_no: str = '', lc_no: str = '', bl_no: str = '',
                           customs_no: str = '', proforma_date=None, bl_date=None,
                           customs_date=None, description: str = '',
                           cost_freight=0, cost_insurance=0, cost_customs=0,
                           cost_clearing=0) -> ImportShipment:
    """ثبت محموله وارداتی — پیش‌فاکتور ارزی تا ترخیص."""
    shipment = ImportShipment(
        supplier=supplier, order=order, currency=currency,
        exchange_rate=exchange_rate or 1, goods_value=goods_value,
        proforma_no=proforma_no, lc_no=lc_no, bl_no=bl_no, customs_no=customs_no,
        proforma_date=proforma_date, bl_date=bl_date, customs_date=customs_date,
        description=description[:300], created_by=created_by,
        cost_freight=cost_freight, cost_insurance=cost_insurance,
        cost_customs=cost_customs, cost_clearing=cost_clearing,
        status=ImportShipment.Status.DRAFT)
    shipment.assign_number()
    shipment.save()
    return shipment


def add_import_cost(shipment: ImportShipment, *, title: str, amount,
                    stage: str = '') -> ImportCost:
    return ImportCost.objects.create(
        shipment=shipment, title=title[:120], stage=stage[:40],
        amount=Decimal(str(amount)))


def set_import_status(shipment: ImportShipment, status: str) -> ImportShipment:
    if status not in ImportShipment.Status.values:
        raise ValueError('وضعیت محموله نامعتبر است.')
    shipment.status = status
    shipment.save(update_fields=['status', 'updated_at'])
    return shipment


def landed_unit_costs(shipment: ImportShipment) -> list[dict]:
    """فی تمام‌شده هر کالای محموله — ارزش کالا + هزینه‌های واردات (تسهیم ارزشی).

    ارزش کالای محموله (ارز × نرخ) به نسبت ارزش ردیف‌ها تقسیم می‌شود؛
    جمع ردیف‌ها = بهای تمام‌شده کل محموله.
    """
    from apps.inventory.models import StockDocument
    total_goods_rial = float(shipment.goods_value or 0) * float(shipment.exchange_rate or 0)
    extra = shipment.extra_costs_total
    rows = []
    lines = []
    if shipment.order_id:
        lines = list(shipment.order.lines.all())
    else:
        for stock_doc in StockDocument.objects.filter(
                reference=shipment.number, status='final'):
            lines.extend(stock_doc.lines.all())
    base_total = 0.0
    computed = []
    for line in lines:
        value = float(line.quantity) * float(line.unit_price or 0)
        computed.append((line, value))
        base_total += value
    for line, value in computed:
        share = (value / base_total) if base_total else (1.0 / max(len(computed), 1))
        goods_share = total_goods_rial * share
        cost_share = extra * share
        landed = goods_share + cost_share
        qty = float(line.quantity) or 1
        rows.append({
            'line': line,
            'value': goods_share,
            'cost_share': cost_share,
            'landed_value': landed,
            'unit_cost': landed / qty,
        })
    return rows
