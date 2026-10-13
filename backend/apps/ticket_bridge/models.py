"""پل سامانه تیکتینگ PHP — نگاشت کاربر و آینه فقط‌خواندنی تیکت‌ها.

مطابق docs/04 و قرارداد SSO در docs/14 بند ۷:
- داده‌ها فقط‌خواندنی از سامانه مبدأ واکشی می‌شوند (بدون نوشتن روی آن)
- ورود یکپارچه با توکن کوتاه‌مدت HMAC-SHA256
"""
from django.conf import settings
from django.db import models

from apps.core.models import TimeStampedModel

STATUS_FA = {
    'new': 'جدید', 'manager_review': 'بررسی مدیر', 'assigned': 'ارجاع‌شده',
    'in_progress': 'در جریان', 'waiting_user': 'در انتظار کاربر', 'pending': 'معلق',
    'resolved': 'حل‌شده', 'closed': 'بسته', 'reopened': 'بازگشایی',
}


class IntegrationUserMap(TimeStampedModel):
    """نگاشت کاربر پلتفرم به نام کاربری سامانه بیرونی."""

    platform_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر پلتفرم',
        on_delete=models.CASCADE, related_name='integration_maps')
    system = models.CharField('سامانه', max_length=40, default='php-ticketing')
    external_username = models.CharField('نام کاربری بیرونی', max_length=100)
    external_user_id = models.PositiveIntegerField('شناسه کاربر بیرونی', null=True, blank=True)

    class Meta:
        verbose_name = 'نگاشت کاربر'
        verbose_name_plural = 'نگاشت کاربران'
        constraints = [
            models.UniqueConstraint(
                fields=['system', 'external_username'], name='uq_integration_map')
        ]

    def __str__(self) -> str:
        return f'{self.platform_user_id} ↔ {self.system}:{self.external_username}'


class ExternalTicket(TimeStampedModel):
    """آینه فقط‌خواندنی تیکت — برای نمایش در کارتابل («تیکت‌های من»)."""

    system = models.CharField('سامانه', max_length=40, default='php-ticketing')
    external_id = models.CharField('شناسه تیکت', max_length=40)
    subject = models.CharField('موضوع', max_length=300)
    status = models.CharField('وضعیت', max_length=40, blank=True, default='')
    priority = models.CharField('اولویت', max_length=20, blank=True, default='')
    service_group = models.CharField('حوزه خدمت', max_length=20, blank=True, default='')
    requester_username = models.CharField('درخواست‌دهنده', max_length=100, db_index=True)
    created_label = models.CharField('تاریخ ثبت (شمسی)', max_length=20, blank=True, default='')
    payload = models.JSONField('داده خام', default=dict, blank=True)
    fetched_at = models.DateTimeField('زمان واکشی', auto_now=True)

    class Meta:
        verbose_name = 'تیکت بیرونی'
        verbose_name_plural = 'تیکت‌های بیرونی'
        ordering = ['-created_label']
        constraints = [
            models.UniqueConstraint(
                fields=['system', 'external_id'], name='uq_external_ticket')
        ]

    @property
    def status_fa(self) -> str:
        return STATUS_FA.get(self.status, self.status or '—')

    def __str__(self) -> str:
        return f'{self.external_id}: {self.subject}'
