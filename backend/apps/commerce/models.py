"""مدل‌های بازرگانی: درخواست خرید، استعلام قیمت و قرارداد.

زنجیره: درخواست خرید → تأیید مدیر → استعلام از تأمین‌کنندگان →
انتخاب/قرارداد. تاریخ‌ها شمسی، مبالغ عددی بدون ذخیره نمایش.
"""
from django.conf import settings
from django.db import models
from django_jalali.db import models as jmodels

from apps.core.models import TimeStampedModel


class PurchaseRequest(TimeStampedModel):
    """درخواست خرید — با گردش تأیید داخلی."""

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        SUBMITTED = 'submitted', 'در انتظار تأیید'
        APPROVED = 'approved', 'تأییدشده'
        REJECTED = 'rejected', 'ردشده'
        DONE = 'done', 'انجام‌شده'

    title = models.CharField('عنوان خرید', max_length=300)
    description = models.TextField('شرح نیاز', blank=True, default='')
    estimated_cost = models.DecimalField(
        'برآورد هزینه (ریال)', max_digits=18, decimal_places=0, default=0)
    needed_by = jmodels.jDateField('تاریخ نیاز', null=True, blank=True)
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.DRAFT, db_index=True)
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


class Inquiry(TimeStampedModel):
    """استعلام قیمت — پیشنهاد یک تأمین‌کننده برای یک درخواست."""

    request = models.ForeignKey(
        PurchaseRequest, verbose_name='درخواست', on_delete=models.CASCADE,
        related_name='inquiries')
    supplier_name = models.CharField('تأمین‌کننده', max_length=200)
    quote_amount = models.DecimalField(
        'مبلغ پیشنهادی (ریال)', max_digits=18, decimal_places=0)
    quote_note = models.CharField('شرح', max_length=300, blank=True, default='')
    selected = models.BooleanField('انتخاب‌شده', default=False)

    class Meta:
        verbose_name = 'استعلام'
        verbose_name_plural = 'استعلام‌ها'
        ordering = ['quote_amount']

    def __str__(self) -> str:
        return f'{self.supplier_name} — {self.quote_amount}'


class Contract(TimeStampedModel):
    """قرارداد خرید — خروجی زنجیره بازرگانی."""

    class Status(models.TextChoices):
        ACTIVE = 'active', 'جاری'
        FINISHED = 'finished', 'خاتمه‌یافته'
        CANCELLED = 'cancelled', 'لغوشده'

    title = models.CharField('عنوان قرارداد', max_length=300)
    party = models.CharField('طرف قرارداد', max_length=200)
    amount = models.DecimalField('مبلغ (ریال)', max_digits=18, decimal_places=0)
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
