"""مدل‌های ساختار سازمانی: واحدها و کارمندان.

همه تغییرات به‌صورت خودکار در تاریخچه ممیزی (django-simple-history)
ثبت می‌شوند و حذف همیشه نرم است.
"""
from __future__ import annotations

from django.conf import settings
from django.db import models
from django_jalali.db import models as jmodels
from mptt.models import MPTTModel, TreeForeignKey
from simple_history.models import HistoricalRecords

from apps.core.models import SoftDeleteManager, SoftDeleteModel, TimeStampedModel

from mptt.managers import TreeManager


class Department(MPTTModel, TimeStampedModel, SoftDeleteModel):
    """واحد سازمانی — ساختار درختی (هر واحد می‌تواند زیرمجموعه داشته باشد).

    نکته: `objects` عمداً TreeManager نیست تا MPTT برای عملیات درختی
    از `all_objects` (فیلترنشده) استفاده کند و اندیس‌های درخت با رکوردهای
    حذف‌نرم‌شده خراب نشود.
    """

    title = models.CharField('عنوان واحد', max_length=150)
    code = models.CharField('کد واحد', max_length=20, unique=True)
    parent = TreeForeignKey(
        'self', verbose_name='واحد بالادستی', related_name='children',
        null=True, blank=True, on_delete=models.SET_NULL,
    )
    order = models.PositiveIntegerField('ترتیب نمایش', default=0)
    is_active = models.BooleanField('فعال', default=True)

    objects = SoftDeleteManager()
    all_objects = TreeManager()

    # ستون‌های داخلی درخت MPTT در ممیزی ثبت نمی‌شوند (فقط داده کسب‌وکار)
    history = HistoricalRecords(
        excluded_fields=['lft', 'rght', 'tree_id', 'level'])

    class MPTTMeta:
        order_insertion_by = ['order', 'title']

    class Meta:
        verbose_name = 'واحد سازمانی'
        verbose_name_plural = 'واحدهای سازمانی'
        ordering = ['tree_id', 'lft']

    def delete(self, using=None, keep_parents=False):
        """حذف نرم — موقعیت درخت حفظ می‌شود (بدون پاک‌سازی MPTT)."""
        SoftDeleteModel.delete(self, using=using, keep_parents=keep_parents)

    def __str__(self) -> str:
        return f'{self.title} ({self.code})'


class Employee(TimeStampedModel, SoftDeleteModel):
    """کارمند سازمان."""

    first_name = models.CharField('نام', max_length=100)
    last_name = models.CharField('نام خانوادگی', max_length=150)
    personnel_code = models.CharField('کد پرسنلی', max_length=20, unique=True)
    department = models.ForeignKey(
        Department, verbose_name='واحد سازمانی', related_name='employees',
        null=True, blank=True, on_delete=models.SET_NULL,
    )
    user = models.OneToOneField(
        settings.AUTH_USER_MODEL, verbose_name='کاربر سامانه', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='employee_profile',
        help_text='پیوند حساب ورود به رکورد کارمند — مبنای سطح دسترسی «واحد و زیرمجموعه»',
    )
    job_title = models.CharField('سمت', max_length=150, blank=True, default='')
    phone = models.CharField(
        'شماره همراه', max_length=15, blank=True, default='',
        help_text='مقصد پیامک اعلان‌ها — نمونه: 09121234567')
    hire_date = jmodels.jDateField('تاریخ استخدام', null=True, blank=True)
    is_active = models.BooleanField('فعال', default=True)

    history = HistoricalRecords()

    class Meta:
        verbose_name = 'کارمند'
        verbose_name_plural = 'کارمندان'
        ordering = ['last_name', 'first_name']

    def __str__(self) -> str:
        return f'{self.first_name} {self.last_name} ({self.personnel_code})'

    @property
    def full_name(self) -> str:
        return f'{self.first_name} {self.last_name}'
