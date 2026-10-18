"""مدل‌های حضور و غیاب حرفه‌ای — مرجع رفتاری: سامانه MyTime3 (IO).

سازگار: WorkSchedule و AttendanceRecord (قرارداد قبلی) حفظ شده‌اند.
لایه تازه: سیاست‌ها، رویداد تردد (Punch)، درخواست یکپارچه (مرخصی/ماموریت/
اضافه‌کاری/اصلاح رکورد)، نوع مرخصی/مانده، جانشین، ثابت‌های اضافه‌کاری،
پاداش/تنبیه و محدوده جغرافیایی (geo-fence).
"""
from __future__ import annotations

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


class AttendancePolicy(TimeStampedModel):
    """سیاست‌های حضور و غیاب — آینه‌ی تنظیمات سامانه مرجع (تک‌ردیفی)."""

    hours_per_day = models.DecimalField(
        'ساعت کاری روزانه', max_digits=4, decimal_places=2, default=8)
    show_days = models.PositiveSmallIntegerField('بازه نمایش (روز)', default=38)
    last_day_for_request = models.PositiveSmallIntegerField(
        'آخرین روز ماه برای ثبت درخواست', default=16)
    max_request_age_days = models.PositiveSmallIntegerField(
        'حداکثر قدمت درخواست (روز، ۰=بدون محدودیت)', default=0)
    record_fix_daily_limit = models.PositiveSmallIntegerField(
        'سهمیه اصلاح رکورد روزانه', default=1)
    record_fix_monthly_limit = models.PositiveSmallIntegerField(
        'سهمیه اصلاح رکورد ماهانه', default=3)
    valid_late_early = models.BooleanField(
        'اعتبارسنجی تأخیر/تعجیل به‌عنوان درخواست', default=False)
    allow_hourly_free = models.BooleanField(
        'مرخصی ساعتی بدون کسر از مانده', default=False)
    overtime_holiday_rate = models.DecimalField(
        'ضریب اضافه‌کاری تعطیلات', max_digits=5, decimal_places=2, default=1.4)
    overtime_night_rate = models.DecimalField(
        'ضریب اضافه‌کاری شب', max_digits=5, decimal_places=2, default=1.2)

    class Meta:
        verbose_name = 'سیاست حضور و غیاب'
        verbose_name_plural = 'سیاست‌های حضور و غیاب'

    def __str__(self) -> str:
        return 'سیاست سازمان'

    @classmethod
    def get(cls) -> 'AttendancePolicy':
        policy, _ = cls.objects.get_or_create(pk=1)
        return policy


class LeaveType(TimeStampedModel):
    """نوع مرخصی — با محدودیت سالانه و قابلیت پنهان‌سازی."""

    name = models.CharField('نام', max_length=100)
    code = models.CharField('کد', max_length=24, unique=True)
    limit_per_year = models.DecimalField(
        'سهمیه سالانه (روز، ۰=نامحدود)', max_digits=6, decimal_places=2, default=0)
    is_active = models.BooleanField('فعال', default=True)
    needs_balance = models.BooleanField('کسر از مانده', default=True)
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'نوع مرخصی'
        verbose_name_plural = 'انواع مرخصی'
        ordering = ['name']

    def __str__(self) -> str:
        return self.name


class LeaveBalance(TimeStampedModel):
    """مانده مرخصی — به تفکیک کاربر/سال/نوع."""

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', on_delete=models.CASCADE,
        related_name='leave_balances')
    leave_type = models.ForeignKey(
        LeaveType, verbose_name='نوع مرخصی', on_delete=models.CASCADE,
        related_name='balances')
    jyear = models.PositiveIntegerField('سال شمسی')
    granted = models.DecimalField('اعتبار (روز)', max_digits=6, decimal_places=2, default=0)
    used = models.DecimalField('مصرف‌شده (روز)', max_digits=6, decimal_places=2, default=0)

    class Meta:
        verbose_name = 'مانده مرخصی'
        verbose_name_plural = 'مانده مرخصی‌ها'
        unique_together = [('user', 'leave_type', 'jyear')]
        ordering = ['-jyear']

    def __str__(self) -> str:
        return f'{self.user} — {self.leave_type} {self.jyear}'

    @property
    def remaining(self):
        return self.granted - self.used


class AttendanceRequest(TimeStampedModel):
    """درخواست یکپارچه — مرخصی/ماموریت/اضافه‌کاری/اصلاح رکورد با گردش تأیید."""

    class Kind(models.TextChoices):
        LEAVE_HOURLY = 'leave_hourly', 'مرخصی ساعتی'
        LEAVE_DAILY = 'leave_daily', 'مرخصی روزانه'
        MISSION_HOURLY = 'mission_hourly', 'ماموریت ساعتی'
        MISSION_DAILY = 'mission_daily', 'ماموریت روزانه'
        OVERTIME = 'overtime', 'اضافه‌کاری'
        RECORD_FIX = 'record_fix', 'اصلاح رکورد تردد'

    LEAVE_KINDS = (Kind.LEAVE_HOURLY, Kind.LEAVE_DAILY)

    class Status(models.TextChoices):
        PENDING = 'pending', 'در انتظار تأیید'
        APPROVED = 'approved', 'تأییدشده'
        REJECTED = 'rejected', 'ردشده'
        CANCELLED = 'cancelled', 'لغوشده'

    kind = models.CharField('نوع درخواست', max_length=14, choices=Kind.choices)
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='درخواست‌کننده', on_delete=models.CASCADE,
        related_name='attendance_requests')
    leave_type = models.ForeignKey(
        LeaveType, verbose_name='نوع مرخصی', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='requests')
    for_date = jmodels.jDateField('تاریخ', null=True, blank=True)
    from_date = jmodels.jDateField('از تاریخ', null=True, blank=True)
    to_date = jmodels.jDateField('تا تاریخ', null=True, blank=True)
    from_time = models.TimeField('از ساعت', null=True, blank=True)
    to_time = models.TimeField('تا ساعت', null=True, blank=True)
    hours = models.DecimalField('ساعت', max_digits=5, decimal_places=2, default=0)
    days = models.DecimalField('روز', max_digits=5, decimal_places=2, default=0)
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    status = models.CharField('وضعیت', max_length=10, choices=Status.choices,
                              default=Status.PENDING, db_index=True)
    decided_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تأییدکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='decided_attendance_requests')
    decided_at = models.DateTimeField('زمان تصمیم', null=True, blank=True)
    decision_note = models.CharField('یادداشت تصمیم', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'درخواست حضور و غیاب'
        verbose_name_plural = 'درخواست‌های حضور و غیاب'
        ordering = ['-created_at']

    @property
    def is_leave(self) -> bool:
        return self.kind in self.LEAVE_KINDS

    def __str__(self) -> str:
        return f'{self.get_kind_display()} — {self.user}'

    @property
    def is_leave(self) -> bool:
        return self.kind in (self.Kind.LEAVE_HOURLY, self.Kind.LEAVE_DAILY)

    @property
    def is_mission(self) -> bool:
        return self.kind in (self.Kind.MISSION_HOURLY, self.Kind.MISSION_DAILY)


class Deputy(TimeStampedModel):
    """جانشین تأیید — کاربر جانشین در بازه زمانی."""

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='جانشین', on_delete=models.CASCADE,
        related_name='deputy_roles')
    for_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='به‌جای', on_delete=models.CASCADE,
        related_name='deputies')
    from_date = jmodels.jDateField('از تاریخ')
    to_date = jmodels.jDateField('تا تاریخ')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'جانشین'
        verbose_name_plural = 'جانشین‌ها'
        ordering = ['-from_date']

    def __str__(self) -> str:
        return f'{self.user} ← {self.for_user}'


class OvertimeConstant(TimeStampedModel):
    """ثابت‌های اضافه‌کاری (ConstOT) — پارامترهای قابل تنظیم محاسبه."""

    key = models.CharField('کلید', max_length=40, unique=True)
    title = models.CharField('عنوان', max_length=120)
    value = models.DecimalField('مقدار', max_digits=10, decimal_places=2, default=0)
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'ثابت اضافه‌کاری'
        verbose_name_plural = 'ثابت‌های اضافه‌کاری'
        ordering = ['key']

    def __str__(self) -> str:
        return f'{self.title}: {self.value}'


class RewardPenalty(TimeStampedModel):
    """پاداش/تنبیه — ثبت موردی برای کاربران."""

    class Kind(models.TextChoices):
        REWARD = 'reward', 'پاداش'
        PENALTY = 'penalty', 'تنبیه'

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', on_delete=models.CASCADE,
        related_name='reward_penalties')
    kind = models.CharField('نوع', max_length=8, choices=Kind.choices)
    date = jmodels.jDateField('تاریخ', db_index=True)
    title = models.CharField('عنوان', max_length=200)
    amount = models.DecimalField('مبلغ/امتیاز', max_digits=12, decimal_places=0, default=0)
    description = models.CharField('شرح', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'پاداش/تنبیه'
        verbose_name_plural = 'پاداش‌ها/تنبیه‌ها'
        ordering = ['-date']

    def __str__(self) -> str:
        return f'{self.get_kind_display()} — {self.user}'


class GeoArea(TimeStampedModel):
    """محدوده مجاز جغرافیایی (geo-fence) — مرکز + شعاع متر."""

    name = models.CharField('نام محدوده', max_length=120)
    lat = models.DecimalField('عرض جغرافیایی', max_digits=10, decimal_places=7)
    lng = models.DecimalField('طول جغرافیایی', max_digits=10, decimal_places=7)
    radius_m = models.PositiveIntegerField('شعاع (متر)', default=100)
    is_active = models.BooleanField('فعال', default=True)
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'محدوده مجاز'
        verbose_name_plural = 'محدوده‌های مجاز'
        ordering = ['name']

    def __str__(self) -> str:
        return self.name


class AttendancePunch(TimeStampedModel):
    """رویداد تردد — ثبت تکی ورود/خروج (با موقعیت اختیاری)."""

    class Direction(models.TextChoices):
        IN = 'in', 'ورود'
        OUT = 'out', 'خروج'

    class Source(models.TextChoices):
        MANUAL = 'manual', 'دستی'
        DEVICE = 'device', 'دستگاه'
        APP = 'app', 'اپلیکیشن'

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', on_delete=models.CASCADE,
        related_name='attendance_punches')
    date = jmodels.jDateField('تاریخ', db_index=True)
    time = models.TimeField('ساعت')
    direction = models.CharField('نوع', max_length=4, choices=Direction.choices,
                                 default=Direction.IN)
    source = models.CharField('منبع', max_length=8, choices=Source.choices,
                              default=Source.MANUAL)
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    lat = models.DecimalField('عرض جغرافیایی', max_digits=10, decimal_places=7,
                              null=True, blank=True)
    lng = models.DecimalField('طول جغرافیایی', max_digits=10, decimal_places=7,
                              null=True, blank=True)
    area = models.ForeignKey(GeoArea, verbose_name='محدوده تشخیص‌داده‌شده', null=True,
                             blank=True, on_delete=models.SET_NULL,
                             related_name='punches')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='created_punches')

    class Meta:
        verbose_name = 'رویداد تردد'
        verbose_name_plural = 'رویدادهای تردد'
        ordering = ['-date', '-time']

    def __str__(self) -> str:
        return f'{self.user} — {self.date} {self.time} ({self.get_direction_display()})'
