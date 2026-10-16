"""مدل‌های انبار حرفه‌ای: اسناد چندردیفی، کارت کالا، قیمت‌گذاری و انبارگردانی.

الگو (سپیدار/راهکاران/اسپاد/فکرگستر):
- سند انبار (سربرگ + ردیف) لایه ثبت است؛ «موقت» اثر موجودی ندارد، «قطعی» اثر دارد.
- موجودی هرگز ذخیره نمی‌شود؛ از جمع رویدادهای انبار (StockMove) محاسبه می‌شود.
- هر رویداد قطعی به سند و ردیفش وصل است (ردیابی کامل).
"""
from __future__ import annotations

from django.conf import settings
from django.db import models
from django_jalali.db import models as jmodels

from apps.core.models import TimeStampedModel


class Warehouse(TimeStampedModel):
    """انبار — محل نگهداری کالا؛ با انبارداران مشخص (دسترسی به تفکیک کاربر)."""

    name = models.CharField('نام انبار', max_length=120, unique=True)
    description = models.TextField('توضیح', blank=True, default='')
    users = models.ManyToManyField(
        settings.AUTH_USER_MODEL, verbose_name='انبارداران', blank=True,
        related_name='managed_warehouses')

    class Meta:
        verbose_name = 'انبار'
        verbose_name_plural = 'انبارها'

    def __str__(self) -> str:
        return self.name


class WarehouseLocation(TimeStampedModel):
    """محل استقرار — قفسه/راهرو/طبقه داخل انبار."""

    warehouse = models.ForeignKey(Warehouse, verbose_name='انبار', on_delete=models.CASCADE,
                                  related_name='locations')
    code = models.CharField('کد محل', max_length=24)
    name = models.CharField('نام محل', max_length=120, blank=True, default='')

    class Meta:
        verbose_name = 'محل استقرار'
        verbose_name_plural = 'محل‌های استقرار'
        ordering = ['warehouse', 'code']
        unique_together = [('warehouse', 'code')]

    def __str__(self) -> str:
        return f'{self.warehouse.name} / {self.code}'


class ItemGroup(TimeStampedModel):
    """گروه کالا — ساختار درختی (گروه/زیرگروه) با مسیر برای زیردرخت."""

    name = models.CharField('نام گروه', max_length=120)
    parent = models.ForeignKey(
        'self', verbose_name='گروه والد', null=True, blank=True,
        on_delete=models.CASCADE, related_name='children')
    path = models.CharField('مسیر', max_length=300, blank=True, default='/', editable=False)

    class Meta:
        verbose_name = 'گروه کالا'
        verbose_name_plural = 'گروه‌های کالا'
        ordering = ['path', 'name']

    def __str__(self) -> str:
        return self.full_name

    def save(self, *args, **kwargs):
        super().save(*args, **kwargs)
        parent_path = self.parent.path if self.parent_id else '/'
        new_path = f'{parent_path}{self.pk}/'
        if self.path != new_path:
            self.path = new_path
            super().save(update_fields=['path', 'updated_at'])
            for child in self.children.all():
                child.save()

    @property
    def full_name(self) -> str:
        names = [self.name]
        node = self.parent
        seen = set()
        while node is not None and node.pk not in seen:
            seen.add(node.pk)
            names.append(node.name)
            node = node.parent
        return ' / '.join(reversed(names))

    @property
    def level(self) -> int:
        return self.path.strip('/').count('/')

    def get_descendants(self):
        """همه زیرگروه‌ها (نوه به بعد)."""
        return ItemGroup.objects.filter(path__startswith=self.path).exclude(pk=self.pk)

    def get_descendant_ids(self) -> list[int]:
        ids = list(self.get_descendants().values_list('pk', flat=True)) + [self.pk]
        return sorted(ids)


class Item(TimeStampedModel):
    """کارت کالا — شناسه کالا با مشخصات، واحدها، بارکد و سیاست موجودی."""

    class CostingMethod(models.TextChoices):
        AVERAGE = 'average', 'میانگین موزون'
        FIFO = 'fifo', 'قیمت‌گذاری FIFO'
        LIFO = 'lifo', 'قیمت‌گذاری LIFO'

    code = models.CharField('کد کالا', max_length=32, unique=True)
    name = models.CharField('نام کالا', max_length=200)
    unit = models.CharField('واحد پایه', max_length=32, default='عدد')
    category = models.CharField('دسته', max_length=100, blank=True, default='')
    group = models.ForeignKey(
        ItemGroup, verbose_name='گروه کالا', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='items')
    description = models.TextField('توضیحات', blank=True, default='')
    image = models.FileField('تصویر کالا', upload_to='items/', blank=True, default='')
    barcode = models.CharField('بارکد', max_length=64, blank=True, default='', db_index=True)
    is_service = models.BooleanField('کالای خدماتی', default=False)
    is_active = models.BooleanField('فعال', default=True)
    min_stock = models.DecimalField(
        'حداقل موجودی', max_digits=14, decimal_places=2, default=0)
    max_stock = models.DecimalField(
        'حداکثر موجودی', max_digits=14, decimal_places=2, default=0)
    reorder_point = models.DecimalField(
        'نقطه سفارش', max_digits=14, decimal_places=2, default=0)
    track_serial = models.BooleanField('پیگیری سریال', default=False)
    has_expiry = models.BooleanField('دارای انقضا/بچ', default=False)
    costing_method = models.CharField(
        'روش قیمت‌گذاری', max_length=10, choices=CostingMethod.choices,
        default=CostingMethod.AVERAGE)
    default_location = models.ForeignKey(
        WarehouseLocation, verbose_name='محل استقرار پیش‌فرض', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='default_items')
    last_purchase_price = models.DecimalField(
        'آخرین قیمت خرید (ریال)', max_digits=18, decimal_places=0, null=True, blank=True)

    class Meta:
        verbose_name = 'کالا'
        verbose_name_plural = 'کالاها'
        ordering = ['code']

    def __str__(self) -> str:
        return f'{self.code} — {self.name}'

    @classmethod
    def next_code(cls) -> str:
        """کدگذاری خودکار: K-0001, K-0002, ..."""
        top = cls.objects.filter(code__startswith='K-').order_by('-code').first()
        seq = 1
        if top is not None:
            try:
                seq = int(top.code.split('-', 1)[1]) + 1
            except (ValueError, IndexError):
                seq = cls.objects.count() + 1
        return f'K-{seq:04d}'


class ItemUnit(TimeStampedModel):
    """واحد فرعی کالا با ضریب تبدیل به واحد پایه (مثلاً کارتن = ۱۲ عدد)."""

    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.CASCADE,
                             related_name='units')
    name = models.CharField('نام واحد', max_length=32)
    ratio = models.DecimalField('ضریب تبدیل به واحد پایه', max_digits=14, decimal_places=4,
                                default=1)
    is_default = models.BooleanField('واحد پیش‌فرض اسناد', default=False)

    class Meta:
        verbose_name = 'واحد کالا'
        verbose_name_plural = 'واحدهای کالا'
        unique_together = [('item', 'name')]

    def __str__(self) -> str:
        return f'{self.name} ({self.ratio}× {self.item.unit})'


class ItemSpec(TimeStampedModel):
    """مشخصه فنی کالا (تا ده مشخصه مانند سپیدار): عنوان/مقدار."""

    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.CASCADE,
                             related_name='specs')
    title = models.CharField('عنوان', max_length=100)
    value = models.CharField('مقدار', max_length=200)

    class Meta:
        verbose_name = 'مشخصه کالا'
        verbose_name_plural = 'مشخصات کالا'
        ordering = ['id']

    def __str__(self) -> str:
        return f'{self.title}: {self.value}'


class SerialNumber(TimeStampedModel):
    """سریال کالا — ورود با رسید، خروج با حواله (عامل تفکیک‌کننده)."""

    class Status(models.TextChoices):
        IN_STOCK = 'in_stock', 'در انبار'
        OUT = 'out', 'خارج از انبار'

    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.CASCADE,
                             related_name='serials')
    code = models.CharField('شماره سریال', max_length=64)
    warehouse = models.ForeignKey(Warehouse, verbose_name='انبار', null=True, blank=True,
                                  on_delete=models.SET_NULL, related_name='serials')
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.IN_STOCK)

    class Meta:
        verbose_name = 'سریال'
        verbose_name_plural = 'سریال‌ها'
        unique_together = [('item', 'code')]
        ordering = ['item', 'code']

    def __str__(self) -> str:
        return f'{self.item.code} #{self.code}'


class StockMoveCategory(models.TextChoices):
    """دسته سند انبار — مشترک بین رویدادها و اسناد (فرم‌های سپیدار/راهکاران)."""

    PURCHASE = 'purchase', 'خرید'
    SALE = 'sale', 'فروش'
    CONSUMPTION = 'consumption', 'مصرف داخلی/مستقیم'
    RETURN_IN = 'return_in', 'برگشت از فروش/مصرف'
    RETURN_OUT = 'return_out', 'برگشت به تأمین‌کننده'
    TRANSFER = 'transfer', 'انتقال بین انبارها'
    ADJUSTMENT = 'adjustment', 'تعدیل انبارگردانی'
    CONSIGNMENT_IN = 'consignment_in', 'امانی ورود'
    CONSIGNMENT_OUT = 'consignment_out', 'امانی خروج'
    OTHER = 'other', 'سایر'


class StockDocSequence(TimeStampedModel):
    """شمارنده شماره‌گذاری سالانه اسناد (رس-1404-0001)."""

    year = models.PositiveIntegerField('سال شمسی')
    doc_type = models.CharField('نوع سند', max_length=8)
    last_seq = models.PositiveIntegerField('آخرین شماره', default=0)

    class Meta:
        verbose_name = 'شمارنده اسناد'
        verbose_name_plural = 'شمارنده‌های اسناد'
        unique_together = [('year', 'doc_type')]

    def __str__(self) -> str:
        return f'{self.doc_type} {self.year}: {self.last_seq}'


class StockDocument(TimeStampedModel):
    """سربرگ سند انبار — رسید/حواله/انتقال/تعدیل چندردیفی (موقت یا قطعی)."""

    class DocType(models.TextChoices):
        RECEIVE = 'receive', 'رسید'
        ISSUE = 'issue', 'حواله'
        TRANSFER = 'transfer', 'انتقال'
        ADJUST = 'adjust', 'تعدیل'

    class Status(models.TextChoices):
        TEMPORARY = 'temporary', 'موقت'
        FINAL = 'final', 'قطعی'
        CANCELLED = 'cancelled', 'ابطال‌شده'

    number = models.CharField('شماره سند', max_length=32, unique=True)
    doc_type = models.CharField('نوع سند', max_length=8, choices=DocType.choices)
    category = models.CharField(
        'دسته سند', max_length=16, choices=StockMoveCategory.choices,
        default=StockMoveCategory.OTHER, db_index=True)
    warehouse = models.ForeignKey(Warehouse, verbose_name='انبار', on_delete=models.PROTECT,
                                  related_name='documents')
    to_warehouse = models.ForeignKey(
        Warehouse, verbose_name='انبار مقصد', null=True, blank=True,
        on_delete=models.PROTECT, related_name='incoming_documents')
    date = jmodels.jDateField('تاریخ سند', db_index=True)
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.TEMPORARY, db_index=True)
    reverses = models.ForeignKey(
        'self', verbose_name='سند مبدأ (برگشتی)', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='returns')
    reference = models.CharField('شماره مدرک', max_length=60, blank=True, default='')
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    year = models.PositiveIntegerField('سال شماره', default=0)
    seq = models.PositiveIntegerField('شماره سریال', default=0)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='stock_documents')
    confirmed_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تأییدکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='confirmed_documents')
    confirmed_at = models.DateTimeField('زمان قطعی', null=True, blank=True)
    cancelled_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ابطال‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='cancelled_documents')
    cancelled_at = models.DateTimeField('زمان ابطال', null=True, blank=True)
    cancel_reason = models.CharField('علت ابطال', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'سند انبار'
        verbose_name_plural = 'اسناد انبار'
        ordering = ['-date', '-id']
        permissions = [
            ('manage_inventory', 'مدیریت انبار'),
            ('view_inventory_value', 'مشاهده ارزش ریالی انبار'),
        ]

    def __str__(self) -> str:
        return f'{self.get_doc_type_display()} {self.number}'

    @property
    def is_editable(self) -> bool:
        return self.status == self.Status.TEMPORARY

    @property
    def total_value(self) -> int:
        total = 0
        for line in self.lines.all():
            if line.unit_price is not None:
                total += int(line.base_quantity * line.unit_price)
        return total

    def assign_number(self) -> None:
        """شماره‌گذاری خودکار سالانه به ازای هر نوع سند."""
        from .services import next_document_number
        year = self.date.year if self.date else 1404
        number, seq = next_document_number(year, self.doc_type)
        self.number = number
        self.year = year
        self.seq = seq


class StockDocumentLine(TimeStampedModel):
    """ردیف سند انبار — کالا، تعداد (در واحد انتخابی)، قیمت و ردیابی."""

    document = models.ForeignKey(StockDocument, verbose_name='سند', on_delete=models.CASCADE,
                                 related_name='lines')
    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.PROTECT,
                             related_name='document_lines')
    quantity = models.DecimalField('تعداد', max_digits=14, decimal_places=2)
    unit_name = models.CharField('واحد', max_length=32, blank=True, default='')
    unit_ratio = models.DecimalField('ضریب تبدیل', max_digits=14, decimal_places=4, default=1)
    unit_price = models.DecimalField(
        'قیمت واحد (ریال)', max_digits=18, decimal_places=0, null=True, blank=True)
    expiry_date = jmodels.jDateField('تاریخ انقضا', null=True, blank=True)
    batch_no = models.CharField('شماره بچ', max_length=40, blank=True, default='')
    location = models.ForeignKey(
        WarehouseLocation, verbose_name='محل استقرار', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='document_lines')
    description = models.CharField('شرح ردیف', max_length=300, blank=True, default='')
    serials_raw = models.TextField(
        'سریال‌ها (هر ردیف یک سریال)', blank=True, default='')
    sequence = models.PositiveIntegerField('ترتیب', default=0)

    class Meta:
        verbose_name = 'ردیف سند'
        verbose_name_plural = 'ردیف‌های اسناد'
        ordering = ['sequence', 'id']

    def __str__(self) -> str:
        return f'{self.item.code} × {self.quantity}'

    @property
    def base_quantity(self):
        """تعداد معادل واحد پایه (تعداد × ضریب تبدیل)."""
        return self.quantity * self.unit_ratio

    @property
    def serial_codes(self) -> list[str]:
        return [s.strip() for s in self.serials_raw.replace(',', '\n').splitlines()
                if s.strip()]


class StockMove(TimeStampedModel):
    """رویداد انبار (کاردکس): هر سند قطعی، رویدادهایش اینجا ثبت می‌شود."""

    class Kind(models.TextChoices):
        RECEIVE = 'receive', 'رسید'
        ISSUE = 'issue', 'حواله'
        ADJUST = 'adjust', 'تعدیل'

    Category = StockMoveCategory

    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.PROTECT,
                             related_name='moves')
    warehouse = models.ForeignKey(Warehouse, verbose_name='انبار', on_delete=models.PROTECT,
                                  related_name='moves')
    kind = models.CharField('نوع', max_length=8, choices=Kind.choices)
    category = models.CharField(
        'دسته سند', max_length=16, choices=StockMoveCategory.choices,
        default=StockMoveCategory.OTHER, db_index=True)
    quantity = models.DecimalField('تعداد', max_digits=14, decimal_places=2)
    unit_price = models.DecimalField(
        'قیمت واحد (ریال)', max_digits=18, decimal_places=0,
        null=True, blank=True)
    date = jmodels.jDateField('تاریخ', db_index=True)
    reference = models.CharField('شماره مدرک', max_length=60, blank=True, default='')
    note = models.CharField('شرح', max_length=300, blank=True, default='')
    document = models.ForeignKey(
        StockDocument, verbose_name='سند مبدأ', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='moves')
    line = models.ForeignKey(
        StockDocumentLine, verbose_name='ردیف مبدأ', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='moves')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='stock_moves')

    class Meta:
        verbose_name = 'رویداد انبار'
        verbose_name_plural = 'رویدادهای انبار'
        ordering = ['date', 'id']

    def __str__(self) -> str:
        return f'{self.get_kind_display()} {self.item.code} × {self.quantity}'

    @property
    def signed_quantity(self):
        """اثر رویداد بر موجودی: رسید +، حواله −، تعدیل امضا دار."""
        if self.kind == self.Kind.ISSUE:
            return -abs(self.quantity)
        return self.quantity


class StockLayer(TimeStampedModel):
    """لایه خرید FIFO — مصرف قدیمی‌ترین لایه هنگام حواله در روش FIFO."""

    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.CASCADE,
                             related_name='layers')
    warehouse = models.ForeignKey(Warehouse, verbose_name='انبار', on_delete=models.CASCADE,
                                  related_name='layers')
    quantity_remaining = models.DecimalField('تعداد باقی‌مانده', max_digits=14, decimal_places=2)
    unit_cost = models.DecimalField('بهای واحد (ریال)', max_digits=18, decimal_places=0)
    received_date = jmodels.jDateField('تاریخ ورود', db_index=True)
    source_line = models.ForeignKey(
        StockDocumentLine, verbose_name='ردیف مبدأ', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='layers')

    class Meta:
        verbose_name = 'لایه FIFO'
        verbose_name_plural = 'لایه‌های FIFO'
        ordering = ['received_date', 'id']

    def __str__(self) -> str:
        return f'{self.item.code} @ {self.unit_cost} × {self.quantity_remaining}'


class PriceList(TimeStampedModel):
    """لیست قیمت — چندقیمتی برای کالاها (پیش‌فرض/فروش/خاص مشتری)."""

    name = models.CharField('نام لیست', max_length=120, unique=True)
    is_default = models.BooleanField('پیش‌فرض', default=False)
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'لیست قیمت'
        verbose_name_plural = 'لیست‌های قیمت'
        ordering = ['-is_default', 'name']

    def __str__(self) -> str:
        return self.name


class PriceListItem(TimeStampedModel):
    """قیمت یک کالا در یک لیست قیمت."""

    price_list = models.ForeignKey(PriceList, verbose_name='لیست قیمت', on_delete=models.CASCADE,
                                   related_name='entries')
    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.CASCADE,
                             related_name='price_entries')
    price = models.DecimalField('قیمت (ریال)', max_digits=18, decimal_places=0)

    class Meta:
        verbose_name = 'قیمت کالا'
        verbose_name_plural = 'قیمت کالاها'
        unique_together = [('price_list', 'item')]

    def __str__(self) -> str:
        return f'{self.item.code} @ {self.price}'


class StockCount(TimeStampedModel):
    """انبارگردانی — شمارش فیزیکی انبار (تک‌مرحله یا چندمرحله)."""

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        COUNTING = 'counting', 'در حال شمارش'
        APPLIED = 'applied', 'اعمال‌شده'

    warehouse = models.ForeignKey(Warehouse, verbose_name='انبار', on_delete=models.PROTECT,
                                  related_name='counts')
    date = jmodels.jDateField('تاریخ شمارش')
    title = models.CharField('عنوان', max_length=200, blank=True, default='')
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.DRAFT)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='stock_counts')

    class Meta:
        verbose_name = 'انبارگردانی'
        verbose_name_plural = 'انبارگردانی‌ها'
        ordering = ['-date']

    def __str__(self) -> str:
        return f'انبارگردانی {self.warehouse} — {self.date}'


class StockCountLine(TimeStampedModel):
    """ردیف انبارگردانی — شمارش مرحله اول/دوم و تعداد نهایی."""

    count = models.ForeignKey(StockCount, verbose_name='انبارگردانی',
                              on_delete=models.CASCADE, related_name='lines')
    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.PROTECT,
                             related_name='count_lines')
    counted_quantity = models.DecimalField('تعداد شمارش‌شده', max_digits=14, decimal_places=2)
    stage1_quantity = models.DecimalField(
        'شمارش مرحله اول', max_digits=14, decimal_places=2, null=True, blank=True)
    stage2_quantity = models.DecimalField(
        'شمارش مرحله دوم', max_digits=14, decimal_places=2, null=True, blank=True)
    expected_quantity = models.DecimalField(
        'موجودی دفتری هنگام شمارش', max_digits=14, decimal_places=2, null=True, blank=True)
    note = models.CharField('توضیح', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'ردیف انبارگردانی'
        verbose_name_plural = 'ردیف‌های انبارگردانی'
        unique_together = [('count', 'item')]

    def __str__(self) -> str:
        return f'{self.item.code}: {self.counted_quantity}'


class CountTag(TimeStampedModel):
    """تگ انبارگردانی — برچسب چاپی شمارش با کد یکتا (تگ خورده/نخورده)."""

    count = models.ForeignKey(StockCount, verbose_name='انبارگردانی', on_delete=models.CASCADE,
                              related_name='tags')
    code = models.CharField('کد تگ', max_length=32)
    item = models.ForeignKey(Item, verbose_name='کالا', null=True, blank=True,
                             on_delete=models.SET_NULL, related_name='count_tags')
    is_used = models.BooleanField('مصرف‌شده', default=False)

    class Meta:
        verbose_name = 'تگ انبارگردانی'
        verbose_name_plural = 'تگ‌های انبارگردانی'
        unique_together = [('count', 'code')]
        ordering = ['count', 'code']

    def __str__(self) -> str:
        return self.code


class InventoryPeriod(TimeStampedModel):
    """دوره مالی انبار — بستن سال/دوره و جلوگیری از ثبت سند در دوره بسته."""

    year = models.PositiveIntegerField('سال شمسی', unique=True)
    title = models.CharField('عنوان', max_length=100, blank=True, default='')
    is_open = models.BooleanField('باز', default=True)

    class Meta:
        verbose_name = 'دوره مالی انبار'
        verbose_name_plural = 'دوره‌های مالی انبار'
        ordering = ['-year']

    def __str__(self) -> str:
        state = 'باز' if self.is_open else 'بسته'
        return f'{self.year} ({state})'
