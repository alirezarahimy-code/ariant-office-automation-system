"""مدل‌های پایه مشترک: زمان‌بندی، حذف نرم و ممیزی تغییرات."""
from __future__ import annotations

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
