"""مدل گزارش زمان‌بندی‌شده — ارسال خودکار دوره‌ای با ایمیل."""
from django.db import models

from apps.core.models import TimeStampedModel


class ScheduledReport(TimeStampedModel):
    """گزارش دوره‌ای — توسط فرمان dispatch_scheduled_reports ارسال می‌شود."""

    class Period(models.TextChoices):
        DAILY = 'daily', 'روزانه'
        WEEKLY = 'weekly', 'هفتگی'
        MONTHLY = 'monthly', 'ماهانه'

    name = models.CharField('نام', max_length=200)
    slug = models.CharField('کد گزارش', max_length=40)
    period = models.CharField('دوره', max_length=8, choices=Period.choices)
    emails = models.TextField('گیرندگان (هر خط یک رایانامه)')
    is_active = models.BooleanField('فعال', default=True)
    last_sent_at = models.DateTimeField('آخرین ارسال', null=True, blank=True)

    class Meta:
        verbose_name = 'گزارش زمان‌بندی‌شده'
        verbose_name_plural = 'گزارش‌های زمان‌بندی‌شده'
        ordering = ['name']

    def __str__(self) -> str:
        return f'{self.name} ({self.get_period_display()})'

    @property
    def email_list(self) -> list[str]:
        return [line.strip() for line in self.emails.splitlines() if line.strip()]
