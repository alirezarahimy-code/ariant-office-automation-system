"""مدل‌های انبار: کالا، انبار، سند رسید/حواله/تعدیل و انبارگردانی.

موجودی هرگز ذخیره نمی‌شود؛ از جمع اسناد محاسبه می‌شود (اصل حسابداری:
موجودی = خروجی منطق مطمئن اسناد است، نه داده موازی).
"""
from django.conf import settings
from django.db import models
from django_jalali.db import models as jmodels

from apps.core.models import TimeStampedModel


class Warehouse(TimeStampedModel):
    """انبار — محل نگهداری کالا."""

    name = models.CharField('نام انبار', max_length=120, unique=True)
    description = models.TextField('توضیح', blank=True, default='')

    class Meta:
        verbose_name = 'انبار'
        verbose_name_plural = 'انبارها'

    def __str__(self) -> str:
        return self.name


class Item(TimeStampedModel):
    """کالا — شناسه یکتای مورد استفاده در رسید/حواله و انبارگردانی."""

    code = models.CharField('کد کالا', max_length=32, unique=True)
    name = models.CharField('نام کالا', max_length=200)
    unit = models.CharField('واحد', max_length=32, default='عدد')
    category = models.CharField('دسته', max_length=100, blank=True, default='')
    min_stock = models.DecimalField(
        'حداقل موجودی', max_digits=14, decimal_places=2, default=0)

    class Meta:
        verbose_name = 'کالا'
        verbose_name_plural = 'کالاها'
        ordering = ['code']

    def __str__(self) -> str:
        return f'{self.code} — {self.name}'


class StockMove(TimeStampedModel):
    """سند انبار: رسید (+)، حواله (−)، تعدیل انبارگردانی (امضا دار)."""

    class Kind(models.TextChoices):
        RECEIVE = 'receive', 'رسید'
        ISSUE = 'issue', 'حواله'
        ADJUST = 'adjust', 'تعدیل'

    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.PROTECT,
                             related_name='moves')
    warehouse = models.ForeignKey(Warehouse, verbose_name='انبار', on_delete=models.PROTECT,
                                  related_name='moves')
    kind = models.CharField('نوع', max_length=8, choices=Kind.choices)
    quantity = models.DecimalField('تعداد', max_digits=14, decimal_places=2)
    date = jmodels.jDateField('تاریخ', db_index=True)
    reference = models.CharField('شماره مدرک', max_length=60, blank=True, default='')
    note = models.CharField('شرح', max_length=300, blank=True, default='')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='stock_moves')

    class Meta:
        verbose_name = 'سند انبار'
        verbose_name_plural = 'اسناد انبار'
        ordering = ['date', 'id']
        permissions = [
            ('manage_inventory', 'مدیریت انبار'),
        ]

    def __str__(self) -> str:
        return f'{self.get_kind_display()} {self.item.code} × {self.quantity}'

    @property
    def signed_quantity(self):
        """اثر سند بر موجودی: رسید +، حواله −، تعدیل امضا دار."""
        if self.kind == self.Kind.ISSUE:
            return -abs(self.quantity)
        return self.quantity


class StockCount(TimeStampedModel):
    """انبارگردانی — شمارش فیزیکی یک انبار در یک تاریخ."""

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        APPLIED = 'applied', 'اعمال‌شده'

    warehouse = models.ForeignKey(Warehouse, verbose_name='انبار', on_delete=models.PROTECT,
                                  related_name='counts')
    date = jmodels.jDateField('تاریخ شمارش')
    status = models.CharField('وضعیت', max_length=8, choices=Status.choices,
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
    """ردیف انبارگردانی — تعداد شمارش‌شده هر کالا."""

    count = models.ForeignKey(StockCount, verbose_name='انبارگردانی',
                              on_delete=models.CASCADE, related_name='lines')
    item = models.ForeignKey(Item, verbose_name='کالا', on_delete=models.PROTECT,
                             related_name='count_lines')
    counted_quantity = models.DecimalField('تعداد شمارش‌شده', max_digits=14, decimal_places=2)

    class Meta:
        verbose_name = 'ردیف انبارگردانی'
        verbose_name_plural = 'ردیف‌های انبارگردانی'
        unique_together = [('count', 'item')]

    def __str__(self) -> str:
        return f'{self.item.code}: {self.counted_quantity}'
