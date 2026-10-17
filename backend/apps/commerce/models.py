"""مدل‌های بازرگانی حرفه‌ای: طرف تجاری، چرخه خرید (P2P)، فروش، قرارداد و خرید خارجی.

الگو (سپیدار/راهکاران/دیدگاه/فکرگستر):
درخواست خرید → استعلام بها → انتخاب تأمین‌کننده → سفارش خرید → رسید انبار
→ فاکتور خرید → تطبیق سه‌طرفه → تسویه/تقسیط. فروش: پیش‌فاکتور → فاکتور → برگشت.
خرید خارجی: پیش‌فاکتور ارزی → بارنامه → ترخیص → فی تمام‌شده.
"""
from __future__ import annotations

from django.conf import settings
from django.db import models
from django_jalali.db import models as jmodels

from apps.core.models import TimeStampedModel


class CommerceSequence(TimeStampedModel):
    """شمارنده شماره‌گذاری سالانه اسناد بازرگانی (PO-1404-0001)."""

    year = models.PositiveIntegerField('سال شمسی')
    doc_type = models.CharField('نوع سند', max_length=8)
    last_seq = models.PositiveIntegerField('آخرین شماره', default=0)

    class Meta:
        verbose_name = 'شمارنده بازرگانی'
        verbose_name_plural = 'شمارنده‌های بازرگانی'
        unique_together = [('year', 'doc_type')]

    def __str__(self) -> str:
        return f'{self.doc_type} {self.year}: {self.last_seq}'


class BusinessParty(TimeStampedModel):
    """طرف تجاری — کارت تأمین‌کننده/مشتری با مشخصات حقوقی، تماس و بانکی."""

    class Kind(models.TextChoices):
        SUPPLIER = 'supplier', 'تأمین‌کننده'
        CUSTOMER = 'customer', 'مشتری'
        BOTH = 'both', 'تأمین‌کننده و مشتری'

    code = models.CharField('کد طرف', max_length=24, unique=True)
    name = models.CharField('نام/عنوان', max_length=200)
    kind = models.CharField('نوع', max_length=10, choices=Kind.choices,
                            default=Kind.SUPPLIER)
    economic_code = models.CharField('کد اقتصادی', max_length=20, blank=True, default='')
    national_id = models.CharField('شناسه ملی/کد ملی', max_length=20, blank=True, default='')
    phone = models.CharField('تلفن', max_length=20, blank=True, default='')
    mobile = models.CharField('موبایل', max_length=20, blank=True, default='')
    email = models.CharField('ایمیل', max_length=100, blank=True, default='')
    address = models.CharField('نشانی', max_length=300, blank=True, default='')
    contact_person = models.CharField('شخص رابط', max_length=100, blank=True, default='')
    payment_terms_days = models.PositiveIntegerField('مهلت پرداخت (روز)', default=0)
    payment_terms_note = models.CharField('شرایط پرداخت', max_length=200, blank=True, default='')
    credit_limit = models.DecimalField(
        'سقف اعتبار (ریال)', max_digits=18, decimal_places=0, default=0)
    currency = models.CharField('ارز', max_length=8, default='IRR')
    bank_name = models.CharField('بانک', max_length=60, blank=True, default='')
    account_no = models.CharField('شماره حساب', max_length=34, blank=True, default='')
    sheba = models.CharField('شبا', max_length=34, blank=True, default='')
    is_active = models.BooleanField('فعال', default=True)
    notes = models.TextField('یادداشت', blank=True, default='')

    class Meta:
        verbose_name = 'طرف تجاری'
        verbose_name_plural = 'طرف‌های تجاری'
        ordering = ['name']

    def __str__(self) -> str:
        return f'{self.code} — {self.name}'

    @classmethod
    def next_code(cls) -> str:
        """کدگذاری خودکار طرف: T-0001, T-0002, ..."""
        top = cls.objects.filter(code__startswith='T-').order_by('-code').first()
        seq = 1
        if top is not None:
            try:
                seq = int(top.code.split('-', 1)[1]) + 1
            except (ValueError, IndexError):
                seq = cls.objects.count() + 1
        return f'T-{seq:04d}'


class PurchaseRequest(TimeStampedModel):
    """درخواست خرید — با گردش تأیید داخلی و رویه خرید (جزئی/استعلامی/قراردادی)."""

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        SUBMITTED = 'submitted', 'در انتظار تأیید'
        APPROVED = 'approved', 'تأییدشده'
        REJECTED = 'rejected', 'ردشده'
        DONE = 'done', 'انجام‌شده'

    class Priority(models.TextChoices):
        NORMAL = 'normal', 'عادی'
        URGENT = 'urgent', 'فوری'
        IMMEDIATE = 'immediate', 'آنی'

    class PurchaseKind(models.TextChoices):
        MINOR = 'minor', 'خرید جزئی'
        INQUIRY = 'inquiry', 'خرید استعلامی'
        CONTRACT = 'contract', 'خرید قراردادی'

    title = models.CharField('عنوان خرید', max_length=300)
    description = models.TextField('شرح نیاز', blank=True, default='')
    estimated_cost = models.DecimalField(
        'برآورد هزینه (ریال)', max_digits=18, decimal_places=0, default=0)
    needed_by = jmodels.jDateField('تاریخ نیاز', null=True, blank=True)
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.DRAFT, db_index=True)
    priority = models.CharField('فوریت', max_length=10, choices=Priority.choices,
                                default=Priority.NORMAL)
    purchase_kind = models.CharField('رویه خرید', max_length=10,
                                     choices=PurchaseKind.choices,
                                     default=PurchaseKind.INQUIRY)
    department = models.CharField('واحد درخواست‌کننده', max_length=100, blank=True, default='')
    requested_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='درخواست‌کننده',
        on_delete=models.PROTECT, related_name='purchase_requests')
    decided_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تصمیم‌گیرنده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='decided_purchases')
    decision_note = models.CharField('یادداشت تصمیم', max_length=300,
                                     blank=True, default='')

    class Meta:
        verbose_name = 'درخواست خرید'
        verbose_name_plural = 'درخواست‌های خرید'
        ordering = ['-created_at']
        permissions = [
            ('manage_commerce', 'مدیریت بازرگانی'),
        ]

    def __str__(self) -> str:
        return self.title


class PurchaseRequestLine(TimeStampedModel):
    """ردیف درخواست خرید — کالا/خدمت، تعداد و برآورد قیمت."""

    request = models.ForeignKey(PurchaseRequest, verbose_name='درخواست',
                                on_delete=models.CASCADE, related_name='lines')
    item = models.ForeignKey(
        'inventory.Item', verbose_name='کالا', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='purchase_request_lines')
    description = models.CharField('شرح کالا/خدمت', max_length=300)
    quantity = models.DecimalField('تعداد', max_digits=14, decimal_places=2, default=1)
    unit = models.CharField('واحد', max_length=32, blank=True, default='عدد')
    estimated_price = models.DecimalField(
        'برآورد قیمت واحد (ریال)', max_digits=18, decimal_places=0, null=True, blank=True)

    class Meta:
        verbose_name = 'ردیف درخواست خرید'
        verbose_name_plural = 'ردیف‌های درخواست خرید'
        ordering = ['id']

    def __str__(self) -> str:
        return f'{self.description} × {self.quantity}'


class Inquiry(TimeStampedModel):
    """استعلام بها — پیشنهاد یک تأمین‌کننده برای یک درخواست."""

    request = models.ForeignKey(
        PurchaseRequest, verbose_name='درخواست', on_delete=models.CASCADE,
        related_name='inquiries')
    supplier = models.ForeignKey(
        BusinessParty, verbose_name='تأمین‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='inquiries')
    supplier_name = models.CharField('تأمین‌کننده', max_length=200)
    quote_amount = models.DecimalField(
        'مبلغ پیشنهادی (ریال)', max_digits=18, decimal_places=0)
    quote_note = models.CharField('شرح', max_length=300, blank=True, default='')
    valid_until = jmodels.jDateField('اعتبار پیشنهاد', null=True, blank=True)
    delivery_days = models.PositiveIntegerField('مدت تحویل (روز)', default=0)
    selected = models.BooleanField('انتخاب‌شده', default=False)

    class Meta:
        verbose_name = 'استعلام'
        verbose_name_plural = 'استعلام‌ها'
        ordering = ['quote_amount']

    def __str__(self) -> str:
        return f'{self.supplier_name} — {self.quote_amount}'


class InquiryLine(TimeStampedModel):
    """ردیف استعلام — قیمت پیشنهادی هر کالا/خدمت."""

    inquiry = models.ForeignKey(Inquiry, verbose_name='استعلام', on_delete=models.CASCADE,
                                related_name='lines')
    item = models.ForeignKey(
        'inventory.Item', verbose_name='کالا', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='inquiry_lines')
    description = models.CharField('شرح', max_length=300)
    quantity = models.DecimalField('تعداد', max_digits=14, decimal_places=2, default=1)
    unit_price = models.DecimalField(
        'قیمت واحد پیشنهادی (ریال)', max_digits=18, decimal_places=0, default=0)

    class Meta:
        verbose_name = 'ردیف استعلام'
        verbose_name_plural = 'ردیف‌های استعلام'
        ordering = ['id']

    def __str__(self) -> str:
        return f'{self.description} @ {self.unit_price}'


class PurchaseOrder(TimeStampedModel):
    """سفارش خرید — سند چندردیفی با شماره خودکار و پیگیری تحویل/فاکتور."""

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        CONFIRMED = 'confirmed', 'تأییدشده'
        PARTIAL = 'partial', 'تحویل جزئی'
        RECEIVED = 'received', 'تحویل‌شده'
        CANCELLED = 'cancelled', 'لغوشده'

    number = models.CharField('شماره سفارش', max_length=32, unique=True)
    supplier = models.ForeignKey(BusinessParty, verbose_name='تأمین‌کننده',
                                 on_delete=models.PROTECT, related_name='purchase_orders')
    request = models.ForeignKey(PurchaseRequest, verbose_name='درخواست مبدأ', null=True,
                                blank=True, on_delete=models.SET_NULL,
                                related_name='purchase_orders')
    inquiry = models.ForeignKey(Inquiry, verbose_name='استعلام مبدأ', null=True, blank=True,
                                on_delete=models.SET_NULL, related_name='purchase_orders')
    contract = models.ForeignKey('Contract', verbose_name='قرارداد', null=True, blank=True,
                                 on_delete=models.SET_NULL, related_name='purchase_orders')
    date = jmodels.jDateField('تاریخ سفارش', db_index=True)
    delivery_date = jmodels.jDateField('تاریخ تحویل', null=True, blank=True)
    warehouse = models.ForeignKey(
        'inventory.Warehouse', verbose_name='انبار تحویل', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='purchase_orders')
    status = models.CharField('وضعیت', max_length=12, choices=Status.choices,
                              default=Status.DRAFT, db_index=True)
    currency = models.CharField('ارز', max_length=8, default='IRR')
    exchange_rate = models.DecimalField(
        'نرخ ارز', max_digits=18, decimal_places=2, default=1)
    discount_amount = models.DecimalField(
        'تخفیف کل (ریال)', max_digits=18, decimal_places=0, default=0)
    tax_percent = models.DecimalField(
        'مالیات/ارزش افزوده (٪)', max_digits=6, decimal_places=2, default=0)
    extra_costs = models.DecimalField(
        'اضافات (ریال)', max_digits=18, decimal_places=0, default=0)
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    year = models.PositiveIntegerField('سال شماره', default=0)
    seq = models.PositiveIntegerField('شماره سریال', default=0)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='purchase_orders')
    confirmed_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تأییدکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='confirmed_purchase_orders')
    confirmed_at = models.DateTimeField('زمان تأیید', null=True, blank=True)
    cancelled_at = models.DateTimeField('زمان لغو', null=True, blank=True)
    cancel_reason = models.CharField('علت لغو', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'سفارش خرید'
        verbose_name_plural = 'سفارش‌های خرید'
        ordering = ['-date', '-id']

    def __str__(self) -> str:
        return f'سفارش {self.number} — {self.supplier.name}'

    @property
    def subtotal(self) -> int:
        return sum(int(l.quantity * l.unit_price * (100 - (l.discount_percent or 0)) / 100)
                   for l in self.lines.all())

    @property
    def total(self) -> int:
        base = self.subtotal - int(self.discount_amount or 0) + int(self.extra_costs or 0)
        tax = base * float(self.tax_percent or 0) / 100
        return int(base + tax)

    @property
    def is_editable(self) -> bool:
        return self.status in (self.Status.DRAFT, self.Status.CONFIRMED)

    def assign_number(self) -> None:
        from .services import next_commerce_number
        year = self.date.year if self.date else 1404
        number, seq = next_commerce_number(year, 'PO')
        self.number = number
        self.year = year
        self.seq = seq


class PurchaseOrderLine(TimeStampedModel):
    """ردیف سفارش خرید — با پیگیری تحویل‌شده/فاکتورشده."""

    order = models.ForeignKey(PurchaseOrder, verbose_name='سفارش', on_delete=models.CASCADE,
                              related_name='lines')
    item = models.ForeignKey(
        'inventory.Item', verbose_name='کالا', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='purchase_order_lines')
    description = models.CharField('شرح', max_length=300)
    quantity = models.DecimalField('تعداد', max_digits=14, decimal_places=2)
    unit_name = models.CharField('واحد', max_length=32, blank=True, default='عدد')
    unit_price = models.DecimalField('قیمت واحد (ریال)', max_digits=18, decimal_places=0)
    discount_percent = models.DecimalField('تخفیف (٪)', max_digits=6, decimal_places=2,
                                           default=0)
    received_quantity = models.DecimalField(
        'تحویل‌شده', max_digits=14, decimal_places=2, default=0)
    invoiced_quantity = models.DecimalField(
        'فاکتورشده', max_digits=14, decimal_places=2, default=0)

    class Meta:
        verbose_name = 'ردیف سفارش خرید'
        verbose_name_plural = 'ردیف‌های سفارش خرید'
        ordering = ['id']

    def __str__(self) -> str:
        return f'{self.description} × {self.quantity}'

    @property
    def line_total(self) -> int:
        return int(self.quantity * self.unit_price
                   * (100 - (self.discount_percent or 0)) / 100)

    @property
    def remaining_quantity(self):
        """مانده قابل تحویل/فاکتور."""
        return self.quantity - self.received_quantity


class GoodsReceipt(TimeStampedModel):
    """رسید انبار از سفارش — پیوند سفارش خرید با سند رسید انبار."""

    order = models.ForeignKey(PurchaseOrder, verbose_name='سفارش', on_delete=models.CASCADE,
                              related_name='receipts')
    document = models.ForeignKey(
        'inventory.StockDocument', verbose_name='سند رسید انبار', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='goods_receipts')
    date = jmodels.jDateField('تاریخ رسید')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='goods_receipts')
    note = models.CharField('شرح', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'رسید انبار از سفارش'
        verbose_name_plural = 'رسیدهای انبار از سفارش'
        ordering = ['-date']

    def __str__(self) -> str:
        return f'رسید {self.order.number} — {self.date}'


class PurchaseInvoice(TimeStampedModel):
    """فاکتور خرید — کالا/خدمت/دارایی؛ با تخفیف، اضافات، مالیات و تقسیط."""

    class Kind(models.TextChoices):
        GOODS = 'goods', 'فاکتور خرید کالا'
        SERVICE = 'service', 'فاکتور خرید خدمات'
        ASSET = 'asset', 'فاکتور خرید دارایی'

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        FINAL = 'final', 'قطعی'
        CANCELLED = 'cancelled', 'ابطال‌شده'

    number = models.CharField('شماره سند', max_length=32, unique=True)
    invoice_no = models.CharField('شماره فاکتور تأمین‌کننده', max_length=40, blank=True, default='')
    supplier = models.ForeignKey(BusinessParty, verbose_name='تأمین‌کننده',
                                 on_delete=models.PROTECT, related_name='purchase_invoices')
    order = models.ForeignKey(PurchaseOrder, verbose_name='سفارش خرید', null=True, blank=True,
                              on_delete=models.SET_NULL, related_name='invoices')
    kind = models.CharField('نوع فاکتور', max_length=10, choices=Kind.choices,
                            default=Kind.GOODS)
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.DRAFT, db_index=True)
    date = jmodels.jDateField('تاریخ فاکتور', db_index=True)
    currency = models.CharField('ارز', max_length=8, default='IRR')
    exchange_rate = models.DecimalField(
        'نرخ ارز', max_digits=18, decimal_places=2, default=1)
    discount_amount = models.DecimalField(
        'تخفیف کل (ریال)', max_digits=18, decimal_places=0, default=0)
    tax_percent = models.DecimalField(
        'مالیات/ارزش افزوده (٪)', max_digits=6, decimal_places=2, default=0)
    extra_costs = models.DecimalField(
        'اضافات (ریال)', max_digits=18, decimal_places=0, default=0)
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    year = models.PositiveIntegerField('سال شماره', default=0)
    seq = models.PositiveIntegerField('شماره سریال', default=0)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='purchase_invoices')
    confirmed_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تأییدکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='confirmed_purchase_invoices')
    confirmed_at = models.DateTimeField('زمان قطعی', null=True, blank=True)
    cancelled_at = models.DateTimeField('زمان ابطال', null=True, blank=True)
    cancel_reason = models.CharField('علت ابطال', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'فاکتور خرید'
        verbose_name_plural = 'فاکتورهای خرید'
        ordering = ['-date', '-id']

    def __str__(self) -> str:
        return f'فاکتور خرید {self.number} — {self.supplier.name}'

    @property
    def subtotal(self) -> int:
        return sum(int(l.quantity * l.unit_price * (100 - (l.discount_percent or 0)) / 100)
                   for l in self.lines.all())

    @property
    def total(self) -> int:
        base = self.subtotal - int(self.discount_amount or 0) + int(self.extra_costs or 0)
        tax = base * float(self.tax_percent or 0) / 100
        return int(base + tax)

    @property
    def is_editable(self) -> bool:
        return self.status == self.Status.DRAFT

    def assign_number(self) -> None:
        from .services import next_commerce_number
        year = self.date.year if self.date else 1404
        number, seq = next_commerce_number(year, 'PI')
        self.number = number
        self.year = year
        self.seq = seq


class PurchaseInvoiceLine(TimeStampedModel):
    """ردیف فاکتور خرید — با پیوند به ردیف سفارش برای تطبیق سه‌طرفه."""

    invoice = models.ForeignKey(PurchaseInvoice, verbose_name='فاکتور', on_delete=models.CASCADE,
                                related_name='lines')
    order_line = models.ForeignKey(PurchaseOrderLine, verbose_name='ردیف سفارش', null=True,
                                   blank=True, on_delete=models.SET_NULL,
                                   related_name='invoice_lines')
    item = models.ForeignKey(
        'inventory.Item', verbose_name='کالا', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='purchase_invoice_lines')
    description = models.CharField('شرح', max_length=300)
    quantity = models.DecimalField('تعداد', max_digits=14, decimal_places=2)
    unit_name = models.CharField('واحد', max_length=32, blank=True, default='عدد')
    unit_price = models.DecimalField('قیمت واحد (ریال)', max_digits=18, decimal_places=0)
    discount_percent = models.DecimalField('تخفیف (٪)', max_digits=6, decimal_places=2,
                                           default=0)

    class Meta:
        verbose_name = 'ردیف فاکتور خرید'
        verbose_name_plural = 'ردیف‌های فاکتور خرید'
        ordering = ['id']

    def __str__(self) -> str:
        return f'{self.description} × {self.quantity}'


class PaymentSchedule(TimeStampedModel):
    """تقسیط/تعهد پرداخت — اقساط فاکتور خرید یا فروش."""

    purchase_invoice = models.ForeignKey(
        PurchaseInvoice, verbose_name='فاکتور خرید', null=True, blank=True,
        on_delete=models.CASCADE, related_name='installments')
    sales_document = models.ForeignKey(
        'SalesDocument', verbose_name='سند فروش', null=True, blank=True,
        on_delete=models.CASCADE, related_name='installments')
    due_date = jmodels.jDateField('سررسید')
    amount = models.DecimalField('مبلغ (ریال)', max_digits=18, decimal_places=0)
    is_paid = models.BooleanField('پرداخت‌شده', default=False)
    paid_date = jmodels.jDateField('تاریخ پرداخت', null=True, blank=True)
    note = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'قسط'
        verbose_name_plural = 'اقساط'
        ordering = ['due_date']

    def __str__(self) -> str:
        return f'{self.due_date}: {self.amount}'


class SalesDocument(TimeStampedModel):
    """سند فروش — پیش‌فاکتور/سفارش/فاکتور فروش/برگشت از فروش."""

    class Kind(models.TextChoices):
        PROFORMA = 'proforma', 'پیش‌فاکتور فروش'
        ORDER = 'order', 'سفارش فروش'
        INVOICE = 'invoice', 'فاکتور فروش'
        RETURN = 'return', 'برگشت از فروش'

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        FINAL = 'final', 'قطعی'
        CANCELLED = 'cancelled', 'ابطال‌شده'

    number = models.CharField('شماره سند', max_length=32, unique=True)
    kind = models.CharField('نوع سند', max_length=10, choices=Kind.choices,
                            default=Kind.INVOICE)
    customer = models.ForeignKey(BusinessParty, verbose_name='مشتری',
                                 on_delete=models.PROTECT, related_name='sales_documents')
    warehouse = models.ForeignKey(
        'inventory.Warehouse', verbose_name='انبار', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='sales_documents')
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.DRAFT, db_index=True)
    date = jmodels.jDateField('تاریخ سند', db_index=True)
    due_date = jmodels.jDateField('سررسید', null=True, blank=True)
    currency = models.CharField('ارز', max_length=8, default='IRR')
    exchange_rate = models.DecimalField(
        'نرخ ارز', max_digits=18, decimal_places=2, default=1)
    discount_amount = models.DecimalField(
        'تخفیف کل (ریال)', max_digits=18, decimal_places=0, default=0)
    tax_percent = models.DecimalField(
        'مالیات/ارزش افزوده (٪)', max_digits=6, decimal_places=2, default=0)
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    year = models.PositiveIntegerField('سال شماره', default=0)
    seq = models.PositiveIntegerField('شماره سریال', default=0)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='sales_documents')
    confirmed_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تأییدکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='confirmed_sales_documents')
    confirmed_at = models.DateTimeField('زمان قطعی', null=True, blank=True)
    cancelled_at = models.DateTimeField('زمان ابطال', null=True, blank=True)
    cancel_reason = models.CharField('علت ابطال', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'سند فروش'
        verbose_name_plural = 'اسناد فروش'
        ordering = ['-date', '-id']

    def __str__(self) -> str:
        return f'{self.get_kind_display()} {self.number} — {self.customer.name}'

    @property
    def subtotal(self) -> int:
        return sum(int(l.quantity * l.unit_price * (100 - (l.discount_percent or 0)) / 100)
                   for l in self.lines.all())

    @property
    def total(self) -> int:
        base = self.subtotal - int(self.discount_amount or 0)
        tax = base * float(self.tax_percent or 0) / 100
        return int(base + tax)

    @property
    def is_editable(self) -> bool:
        return self.status == self.Status.DRAFT

    def assign_number(self) -> None:
        from .services import next_commerce_number
        year = self.date.year if self.date else 1404
        prefix = {'proforma': 'PF', 'order': 'SO', 'invoice': 'SI', 'return': 'SR'}.get(
            self.kind, 'SI')
        number, seq = next_commerce_number(year, prefix)
        self.number = number
        self.year = year
        self.seq = seq


class SalesDocumentLine(TimeStampedModel):
    """ردیف سند فروش."""

    document = models.ForeignKey(SalesDocument, verbose_name='سند', on_delete=models.CASCADE,
                                 related_name='lines')
    item = models.ForeignKey(
        'inventory.Item', verbose_name='کالا', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='sales_lines')
    description = models.CharField('شرح', max_length=300)
    quantity = models.DecimalField('تعداد', max_digits=14, decimal_places=2)
    unit_name = models.CharField('واحد', max_length=32, blank=True, default='عدد')
    unit_price = models.DecimalField('قیمت واحد (ریال)', max_digits=18, decimal_places=0)
    discount_percent = models.DecimalField('تخفیف (٪)', max_digits=6, decimal_places=2,
                                           default=0)

    class Meta:
        verbose_name = 'ردیف سند فروش'
        verbose_name_plural = 'ردیف‌های اسناد فروش'
        ordering = ['id']

    def __str__(self) -> str:
        return f'{self.description} × {self.quantity}'


class Contract(TimeStampedModel):
    """قرارداد — خرید/فروش/خدمت با شماره خودکار، ضمانت و طرف تجاری."""

    class Status(models.TextChoices):
        ACTIVE = 'active', 'جاری'
        FINISHED = 'finished', 'خاتمه‌یافته'
        CANCELLED = 'cancelled', 'لغوشده'

    class Kind(models.TextChoices):
        PURCHASE = 'purchase', 'خرید'
        SALE = 'sale', 'فروش'
        SERVICE = 'service', 'خدمات'
        OTHER = 'other', 'سایر'

    number = models.CharField('شماره قرارداد', max_length=32, unique=True, blank=True,
                              default='')
    title = models.CharField('عنوان قرارداد', max_length=300)
    kind = models.CharField('نوع قرارداد', max_length=10, choices=Kind.choices,
                            default=Kind.PURCHASE)
    party = models.CharField('طرف قرارداد', max_length=200)
    party_ref = models.ForeignKey(
        BusinessParty, verbose_name='طرف تجاری', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='contracts')
    amount = models.DecimalField('مبلغ (ریال)', max_digits=18, decimal_places=0)
    guarantee_amount = models.DecimalField(
        'مبلغ ضمانت (ریال)', max_digits=18, decimal_places=0, default=0)
    start_date = jmodels.jDateField('تاریخ شروع')
    end_date = jmodels.jDateField('تاریخ پایان', null=True, blank=True)
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.ACTIVE)
    purchase_request = models.ForeignKey(
        PurchaseRequest, verbose_name='درخواست مبدأ', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='contracts')
    description = models.TextField('شرح', blank=True, default='')

    class Meta:
        verbose_name = 'قرارداد'
        verbose_name_plural = 'قراردادها'
        ordering = ['-start_date']

    def __str__(self) -> str:
        return self.title

    def save(self, *args, **kwargs):
        if not self.number:
            from .services import next_commerce_number
            year = self.start_date.year if self.start_date else 1404
            number, _ = next_commerce_number(year, 'GC')
            self.number = number
        super().save(*args, **kwargs)


class ImportShipment(TimeStampedModel):
    """محموله/سفارش خرید خارجی — پیش‌فاکتور ارزی، بارنامه، ترخیص و هزینه‌های واردات."""

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        SHIPPED = 'shipped', 'ارسال‌شده'
        CLEARED = 'cleared', 'ترخیص‌شده'
        RECEIVED = 'received', 'دریافت‌شده'
        CANCELLED = 'cancelled', 'لغوشده'

    number = models.CharField('شماره محموله', max_length=32, unique=True)
    order = models.ForeignKey(PurchaseOrder, verbose_name='سفارش خرید', null=True, blank=True,
                              on_delete=models.SET_NULL, related_name='import_shipments')
    supplier = models.ForeignKey(BusinessParty, verbose_name='تأمین‌کننده خارجی',
                                 on_delete=models.PROTECT, related_name='import_shipments')
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.DRAFT, db_index=True)
    proforma_no = models.CharField('شماره پیش‌فاکتور', max_length=40, blank=True, default='')
    lc_no = models.CharField('شماره اعتبار/حواله ارزی', max_length=40, blank=True, default='')
    bl_no = models.CharField('شماره بارنامه', max_length=40, blank=True, default='')
    customs_no = models.CharField('شماره اظهارنامه/ترخیص', max_length=40, blank=True, default='')
    currency = models.CharField('ارز', max_length=8, default='USD')
    exchange_rate = models.DecimalField(
        'نرخ تسعیر (ریال)', max_digits=18, decimal_places=2, default=1)
    goods_value = models.DecimalField(
        'ارزش کالا (ارز)', max_digits=18, decimal_places=2, default=0)
    cost_freight = models.DecimalField('هزینه حمل (ریال)', max_digits=18, decimal_places=0,
                                       default=0)
    cost_insurance = models.DecimalField('هزینه بیمه (ریال)', max_digits=18, decimal_places=0,
                                         default=0)
    cost_customs = models.DecimalField('هزینه گمرک (ریال)', max_digits=18, decimal_places=0,
                                       default=0)
    cost_clearing = models.DecimalField('حق‌العمل ترخیص (ریال)', max_digits=18,
                                        decimal_places=0, default=0)
    proforma_date = jmodels.jDateField('تاریخ پیش‌فاکتور', null=True, blank=True)
    bl_date = jmodels.jDateField('تاریخ بارنامه', null=True, blank=True)
    customs_date = jmodels.jDateField('تاریخ ترخیص', null=True, blank=True)
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    year = models.PositiveIntegerField('سال شماره', default=0)
    seq = models.PositiveIntegerField('شماره سریال', default=0)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='import_shipments')

    class Meta:
        verbose_name = 'محموله وارداتی'
        verbose_name_plural = 'محموله‌های وارداتی'
        ordering = ['-created_at']

    def __str__(self) -> str:
        return f'محموله {self.number} — {self.supplier.name}'

    @property
    def extra_costs_total(self) -> int:
        extra = sum(int(c.amount) for c in self.costs.all())
        return (int(self.cost_freight or 0) + int(self.cost_insurance or 0)
                + int(self.cost_customs or 0) + int(self.cost_clearing or 0) + extra)

    @property
    def total_landed_rial(self) -> int:
        return int(float(self.goods_value or 0) * float(self.exchange_rate or 0)) \
            + self.extra_costs_total

    def assign_number(self) -> None:
        from .services import next_commerce_number
        year = self.proforma_date.year if self.proforma_date else (
            self.bl_date.year if self.bl_date else 1404)
        number, seq = next_commerce_number(year, 'IM')
        self.number = number
        self.year = year
        self.seq = seq


class ImportCost(TimeStampedModel):
    """هزینه تکمیلی واردات — به تفکیک هر مرحله (علاوه بر حمل/بیمه/گمرک/ترخیص)."""

    shipment = models.ForeignKey(ImportShipment, verbose_name='محموله', on_delete=models.CASCADE,
                                 related_name='costs')
    title = models.CharField('عنوان هزینه', max_length=120)
    stage = models.CharField('مرحله', max_length=40, blank=True, default='')
    amount = models.DecimalField('مبلغ (ریال)', max_digits=18, decimal_places=0)

    class Meta:
        verbose_name = 'هزینه واردات'
        verbose_name_plural = 'هزینه‌های واردات'
        ordering = ['id']

    def __str__(self) -> str:
        return f'{self.title}: {self.amount}'
