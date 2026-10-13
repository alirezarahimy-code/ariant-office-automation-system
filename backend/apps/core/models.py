"""مدل‌های پایه مشترک: زمان‌بندی، حذف نرم و ممیزی تغییرات."""
from __future__ import annotations

from django.conf import settings
from django.core.exceptions import ValidationError
from django.db import models


class SoftDeleteManager(models.Manager):
    """مدیر پیش‌فرض: رکوردهای حذف‌شده نرم را نشان نمی‌دهد."""

    def get_queryset(self):
        return super().get_queryset().filter(is_deleted=False)


class SoftDeleteModel(models.Model):
    """حذف نرم — داده هرگز از پایگاه‌داده پاک نمی‌شود."""

    is_deleted = models.BooleanField('حذف‌شده', default=False, db_index=True)

    objects = SoftDeleteManager()
    all_objects = models.Manager()

    class Meta:
        abstract = True

    def delete(self, using=None, keep_parents=False):
        """حذف نرم: فقط پرچم حذف ست می‌شود."""
        self.is_deleted = True
        self.save(update_fields=['is_deleted'])


class TimeStampedModel(models.Model):
    """زمان ساخت و آخرین تغییر — به‌صورت UTC (نمایش شمسی در لایه نمایش)."""

    created_at = models.DateTimeField('تاریخ ثبت', auto_now_add=True, editable=False)
    updated_at = models.DateTimeField('آخرین تغییر', auto_now=True, editable=False)

    class Meta:
        abstract = True


class Notification(TimeStampedModel):
    """اعلان درون‌سامانه‌ای (فاز ۱؛ ایمیل در فاز ۲ به همین مدل وصل می‌شود)."""

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='گیرنده',
        on_delete=models.CASCADE, related_name='notifications')
    title = models.CharField('عنوان', max_length=200)
    body = models.TextField('متن', blank=True, default='')
    url = models.CharField('پیوند', max_length=300, blank=True, default='')
    is_read = models.BooleanField('خوانده‌شده', default=False)

    class Meta:
        verbose_name = 'اعلان'
        verbose_name_plural = 'اعلان‌ها'
        ordering = ['-created_at']
        indexes = [models.Index(fields=['user', 'is_read'], name='idx_notif_user_read')]

    def __str__(self) -> str:
        return f'{self.user_id}: {self.title}'


class Delegation(TimeStampedModel):
    """جانشینی/تفویض: کاربر ب در بازه زمانی، کارتابل کاربر الف را می‌بیند."""

    from_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تفویض‌کننده',
        on_delete=models.CASCADE, related_name='delegations_given')
    to_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='جانشین',
        on_delete=models.CASCADE, related_name='delegations_received')
    start_date = models.DateField('از تاریخ')
    end_date = models.DateField('تا تاریخ')
    is_active = models.BooleanField('فعال', default=True)
    note = models.CharField('یادداشت', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'جانشینی'
        verbose_name_plural = 'جانشینی‌ها'
        ordering = ['-start_date']

    def clean(self):
        if self.from_user_id and self.from_user_id == self.to_user_id:
            raise ValidationError('تفویض‌کننده و جانشین نمی‌توانند یک نفر باشند.')
        if self.start_date and self.end_date and self.end_date < self.start_date:
            raise ValidationError('تاریخ پایان پیش از شروع است.')

    @classmethod
    def active_delegators(cls, user, on=None):
        """کاربرانی که در تاریخ داده جانشین‌شان «user» است."""
        from datetime import date as _date
        on = on or _date.today()
        return [
            d.from_user_id for d in cls.objects.filter(
                to_user=user, is_active=True, start_date__lte=on, end_date__gte=on)
        ]
