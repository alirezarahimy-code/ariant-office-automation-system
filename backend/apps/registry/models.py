"""ثبت بستر — فهرست یکتای زیرسامانه‌ها (docs/08) و وضعیت استقرار هرکدام.

بستر «پایان‌باز» است (بند ۸): زیرسامانه‌های برنامه‌ریزی‌شده از ابتدا در
همین فهرست ثبت می‌شوند تا نقشه راه سامانه شفاف بماند.
"""
from django.db import models

from apps.core.models import TimeStampedModel


class Subsystem(TimeStampedModel):
    """یک ردیف از manifest بستر — بومی، در گذار یا برنامه‌ریزی‌شده."""

    class Status(models.TextChoices):
        NATIVE = 'native', 'بومی (روی بستر)'
        MIGRATING = 'migrating', 'در گذار (پل)'
        PLANNED = 'planned', 'برنامه‌ریزی‌شده'

    key = models.CharField('کلید', max_length=40, unique=True)
    title = models.CharField('عنوان', max_length=120)
    status = models.CharField(
        'وضعیت', max_length=10, choices=Status.choices, default=Status.PLANNED)
    step = models.PositiveSmallIntegerField(
        'گام بلوغ (بند ۸)', default=0,
        help_text='۰=ثبت در نقشه، ۱=طراحی، ۲=پل/آماده‌سازی، ۳=استقرار روی بستر')
    app_label = models.CharField('برنامه جنگو', max_length=40, blank=True, default='')
    menu_url = models.CharField('نشانی منو', max_length=200, blank=True, default='')
    description = models.TextField('شرح', blank=True, default='')
    order = models.PositiveSmallIntegerField('ترتیب نمایش', default=100)

    class Meta:
        verbose_name = 'زیرسامانه'
        verbose_name_plural = 'زیرسامانه‌های بستر'
        ordering = ['order', 'key']
        permissions = [
            ('manage_platform', 'مدیریت ثبت بستر'),
        ]

    def __str__(self) -> str:
        return f'{self.title} ({self.get_status_display()})'
