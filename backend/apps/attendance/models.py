"""مدل‌های کنترل تردد: ساعات کاری و ثبت‌های ورود/خروج.

تاریخ‌ها شمسی (jDateField) و ساعت‌ها TimeField هستند؛ منطق محاسبه
(تأخیر، اضافه‌کاری، کارکرد) در services نگهداری می‌شود تا همه
ورودی‌ها (دستی، دستگاه، فایل) از یک مسیر واحد محاسبه ببینند.
"""
from django.conf import settings
from django.db import models
from django_jalali.db import models as jmodels

from apps.core.models import TimeStampedModel


class WorkSchedule(TimeStampedModel):
    """برنامه کاری — ساعت شروع/پایان، روزهای کاری هفته و مهلت تأخیر."""

    name = models.CharField('نام برنامه', max_length=100, unique=True)
    start_time = models.TimeField('ساعت شروع')
    end_time = models.TimeField('ساعت پایان')
    # ایندکس روز هفته شمسی: ۰=شنبه … ۵=پنج‌شنبه، ۶=جمعه
    work_days = models.JSONField('روزهای کاری', default=list)
    grace_minutes = models.PositiveSmallIntegerField(
        'مهلت تأخیر مجاز (دقیقه)', default=15)

    class Meta:
        verbose_name = 'برنامه کاری'
        verbose_name_plural = 'برنامه‌های کاری'

    def __str__(self) -> str:
        return self.name

    def is_workday(self, jdate) -> bool:
        return jdate.weekday() in (self.work_days or [])


class AttendanceRecord(TimeStampedModel):
    """ثبت تردد یک روز برای یک کاربر (حداکثر یک رکورد در روز)."""

    class Source(models.TextChoices):
        MANUAL = 'manual', 'دستی'
        DEVICE = 'device', 'دستگاه'
        IMPORT = 'import', 'درون‌ریزی'

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', on_delete=models.CASCADE,
        related_name='attendance_records')
    date = jmodels.jDateField('تاریخ', db_index=True)
    check_in = models.TimeField('ورود', null=True, blank=True)
    check_out = models.TimeField('خروج', null=True, blank=True)
    source = models.CharField(
        'منبع', max_length=8, choices=Source.choices, default=Source.MANUAL)
    note = models.CharField('یادداشت', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'ثبت تردد'
        verbose_name_plural = 'ثبت‌های تردد'
        ordering = ['-date', 'user']
        unique_together = [('user', 'date')]
        permissions = [
            ('manage_attendance', 'مدیریت تردد دیگران'),
        ]

    def __str__(self) -> str:
        return f'{self.user} — {self.date}'
