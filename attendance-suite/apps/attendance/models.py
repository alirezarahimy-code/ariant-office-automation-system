"""حضور و غیاب: شیفت، سیاست، تردد، درخواست، مرخصی، پاداش، موقعیت."""
from django.conf import settings
from django.db import models


class Holiday(models.Model):
    class Kind(models.TextChoices):
        NATIONAL = 'national', 'رسمی'
        RELIGIOUS = 'religious', 'مذهبی'
        ORG = 'org', 'سازمانی'

    date = models.DateField('تاریخ', unique=True)
    title = models.CharField('عنوان', max_length=160)
    kind = models.CharField(
        'نوع', max_length=10, choices=Kind.choices, default=Kind.NATIONAL)

    class Meta:
        verbose_name = 'تعطیلی'
        verbose_name_plural = 'تعطیلات'
        ordering = ['date']

    def __str__(self) -> str:
        return f'{self.title} ({self.date})'


class Shift(models.Model):
    """شیفت کاری (Shift/Schedule/TimeTable)."""

    name = models.CharField('نام شیفت', max_length=120)
    start_time = models.TimeField('شروع')
    end_time = models.TimeField('پایان')
    work_days = models.JSONField(
        'روزهای کاری', default=list,
        help_text='0=شنبه … 6=جمعه')
    grace_minutes = models.PositiveSmallIntegerField('مهلت (دقیقه)', default=15)
    overnight = models.BooleanField('شیفت شبانه (عبور از نیمه‌شب)', default=False)
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'شیفت'
        verbose_name_plural = 'شیفت‌ها'

    def __str__(self) -> str:
        return self.name


class RamadanRule(models.Model):
    """قاعده ماه رمضان (Ramezan): کاهش ساعات کاری در بازه."""

    shift = models.ForeignKey(
        Shift, verbose_name='شیفت', on_delete=models.CASCADE,
        related_name='ramadan_rules')
    from_date = models.DateField('از تاریخ')
    to_date = models.DateField('تا تاریخ')
    reduced_minutes = models.PositiveSmallIntegerField('کاهش (دقیقه)', default=60)

    class Meta:
        verbose_name = 'قاعده رمضان'
        verbose_name_plural = 'قواعد رمضان'


class ShiftAssignment(models.Model):
    """انتساب شیفت به کارمند (AssignShift)."""

    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='shift_assignments')
    shift = models.ForeignKey(
        Shift, verbose_name='شیفت', on_delete=models.CASCADE,
        related_name='assignments')
    from_date = models.DateField('از تاریخ')
    to_date = models.DateField('تا تاریخ', null=True, blank=True)

    class Meta:
        verbose_name = 'انتساب شیفت'
        verbose_name_plural = 'انتساب‌های شیفت'
        ordering = ['-from_date']


class ScheduleRule(models.Model):
    """موتور قانون شیفت (Rule/AssignRule) — شرط/اقدام JSON."""

    name = models.CharField('نام قانون', max_length=120)
    priority = models.PositiveSmallIntegerField('اولویت', default=100)
    conditions_json = models.JSONField('شرط‌ها', default=dict, blank=True)
    actions_json = models.JSONField('اقدام‌ها', default=dict, blank=True)
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'قانون شیفت'
        verbose_name_plural = 'قوانین شیفت'
        ordering = ['priority']


class AttendancePolicy(models.Model):
    """سیاست‌های سازمانی — تک‌رکوردی (Parameters)."""

    hours_per_day = models.DecimalField(
        'ساعت کاری روزانه', max_digits=4, decimal_places=2, default=8)
    show_days = models.PositiveSmallIntegerField('بازه نمایش (روز)', default=38)
    last_day_for_request = models.PositiveSmallIntegerField(
        'آخرین روز ماه برای درخواست', default=16)
    max_request_age_days = models.PositiveSmallIntegerField(
        'حداکثر قدمت درخواست (روز)', default=0)
    record_fix_daily_limit = models.PositiveSmallIntegerField(
        'سهمیه اصلاح رکورد روزانه', default=1)
    record_fix_monthly_limit = models.PositiveSmallIntegerField(
        'سهمیه اصلاح رکورد ماهانه', default=3)
    valid_late_early = models.BooleanField('اعتبارسنجی تأخیر/تعجیل', default=False)
    allow_hourly_free = models.BooleanField(
        'مرخصی ساعتی بدون کسر مانده', default=False)
    overtime_holiday_rate = models.DecimalField(
        'ضریب تعطیل', max_digits=4, decimal_places=2, default=1.4)
    overtime_night_rate = models.DecimalField(
        'ضریب شب', max_digits=4, decimal_places=2, default=1.2)

    class Meta:
        verbose_name = 'سیاست حضور و غیاب'
        verbose_name_plural = 'سیاست‌های حضور و غیاب'

    @classmethod
    def get(cls) -> 'AttendancePolicy':
        obj, _ = cls.objects.get_or_create(pk=1)
        return obj

    def __str__(self) -> str:
        return 'سیاست سازمانی'


class Punch(models.Model):
    """رویداد تردد (Record/InsertRecord)."""

    class Direction(models.TextChoices):
        IN = 'in', 'ورود'
        OUT = 'out', 'خروج'

    class Source(models.TextChoices):
        DEVICE = 'device', 'دستگاه'
        WEB = 'web', 'وب'
        IMPORT = 'import', 'ورود اطلاعات'
        API = 'api', 'API'

    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='punches')
    date = models.DateField('تاریخ', db_index=True)
    time = models.TimeField('ساعت')
    direction = models.CharField(
        'جهت', max_length=4, choices=Direction.choices)
    source = models.CharField(
        'منبع', max_length=8, choices=Source.choices, default=Source.WEB)
    device = models.ForeignKey(
        'device.Device', verbose_name='دستگاه', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='punches')
    lat = models.DecimalField(
        'عرض جغرافیایی', max_digits=9, decimal_places=6, null=True, blank=True)
    lng = models.DecimalField(
        'طول جغرافیایی', max_digits=9, decimal_places=6, null=True, blank=True)
    area = models.ForeignKey(
        'GeoArea', verbose_name='محدوده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='punches')
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='created_punches')
    created_at = models.DateTimeField('ایجاد', auto_now_add=True)

    class Meta:
        verbose_name = 'رویداد تردد'
        verbose_name_plural = 'رویدادهای تردد'
        ordering = ['-date', '-time']
        indexes = [models.Index(fields=['employee', 'date'])]


class DailyRecord(models.Model):
    """خلاصه روزانه کارکرد (محاسبه‌شده)."""

    class State(models.TextChoices):
        ONTIME = 'ontime', 'به‌موقع'
        LATE = 'late', 'با تأخیر'
        INCOMPLETE = 'incomplete', 'ناقص'
        ABSENT = 'absent', 'غیبت'
        HOLIDAY = 'holiday', 'تعطیل'

    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='daily_records')
    date = models.DateField('تاریخ', db_index=True)
    check_in = models.TimeField('ورود', null=True, blank=True)
    check_out = models.TimeField('خروج', null=True, blank=True)
    late_minutes = models.PositiveIntegerField('تأخیر', default=0)
    early_minutes = models.PositiveIntegerField('تعجیل', default=0)
    overtime_minutes = models.PositiveIntegerField('اضافه‌کاری', default=0)
    worked_minutes = models.PositiveIntegerField('کارکرد', default=0)
    state = models.CharField(
        'وضعیت', max_length=10, choices=State.choices, default=State.INCOMPLETE)

    class Meta:
        verbose_name = 'کارکرد روزانه'
        verbose_name_plural = 'کارکردهای روزانه'
        unique_together = [('employee', 'date')]
        ordering = ['-date']


class ExitType(models.Model):
    """نوع خروج اداری (ExitType مرجع)."""

    name = models.CharField('نام', max_length=120)
    code = models.CharField('کد', max_length=24, unique=True)
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'نوع خروج'
        verbose_name_plural = 'انواع خروج'

    def __str__(self) -> str:
        return self.name


class LeaveType(models.Model):
    name = models.CharField('نام', max_length=100)
    code = models.CharField('کد', max_length=24, unique=True)
    limit_per_year = models.DecimalField(
        'سهمیه سالانه (روز)', max_digits=6, decimal_places=2, default=0)
    needs_balance = models.BooleanField('کسر از مانده', default=True)
    is_active = models.BooleanField('فعال', default=True)
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'نوع مرخصی'
        verbose_name_plural = 'انواع مرخصی'

    def __str__(self) -> str:
        return self.name


class LeaveBalance(models.Model):
    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='leave_balances')
    leave_type = models.ForeignKey(
        LeaveType, verbose_name='نوع مرخصی', on_delete=models.CASCADE,
        related_name='balances')
    jyear = models.PositiveSmallIntegerField('سال جلالی')
    granted = models.DecimalField('اعتبار', max_digits=6, decimal_places=2, default=0)
    used = models.DecimalField('مصرف', max_digits=6, decimal_places=2, default=0)

    class Meta:
        verbose_name = 'مانده مرخصی'
        verbose_name_plural = 'مانده‌های مرخصی'
        unique_together = [('employee', 'leave_type', 'jyear')]

    @property
    def remaining(self) -> float:
        return float(self.granted) - float(self.used)


class Request(models.Model):
    """درخواست یکپارچه (مرخصی/ماموریت/اضافه‌کاری/اصلاح رکورد/خروج)."""

    class Kind(models.TextChoices):
        LEAVE_HOURLY = 'leave_hourly', 'مرخصی ساعتی'
        LEAVE_DAILY = 'leave_daily', 'مرخصی روزانه'
        MISSION_HOURLY = 'mission_hourly', 'ماموریت ساعتی'
        MISSION_DAILY = 'mission_daily', 'ماموریت روزانه'
        OVERTIME = 'overtime', 'اضافه‌کاری'
        RECORD_FIX = 'record_fix', 'اصلاح رکورد تردد'
        EXIT = 'exit', 'خروج اداری'

    class Status(models.TextChoices):
        PENDING = 'pending', 'در انتظار تأیید'
        APPROVED = 'approved', 'تأییدشده'
        REJECTED = 'rejected', 'ردشده'
        CANCELLED = 'cancelled', 'لغوشده'

    LEAVE_KINDS = (Kind.LEAVE_HOURLY, Kind.LEAVE_DAILY)

    kind = models.CharField('نوع', max_length=14, choices=Kind.choices)
    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='requests')
    leave_type = models.ForeignKey(
        LeaveType, verbose_name='نوع مرخصی', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='requests')
    exit_type = models.ForeignKey(
        ExitType, verbose_name='نوع خروج', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='requests')
    for_date = models.DateField('تاریخ', null=True, blank=True)
    from_date = models.DateField('از تاریخ', null=True, blank=True)
    to_date = models.DateField('تا تاریخ', null=True, blank=True)
    from_time = models.TimeField('از ساعت', null=True, blank=True)
    to_time = models.TimeField('تا ساعت', null=True, blank=True)
    hours = models.DecimalField('ساعت', max_digits=5, decimal_places=2, default=0)
    days = models.DecimalField('روز', max_digits=5, decimal_places=2, default=0)
    description = models.CharField('شرح', max_length=300, blank=True, default='')
    status = models.CharField(
        'وضعیت', max_length=10, choices=Status.choices, default=Status.PENDING,
        db_index=True)
    decided_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تصمیم‌گیرنده', null=True,
        blank=True, on_delete=models.SET_NULL, related_name='decided_requests')
    decided_at = models.DateTimeField('زمان تصمیم', null=True, blank=True)
    decision_note = models.CharField('یادداشت تصمیم', max_length=300, blank=True,
                                     default='')
    created_at = models.DateTimeField('ایجاد', auto_now_add=True)
    updated_at = models.DateTimeField('به‌روزرسانی', auto_now=True)

    class Meta:
        verbose_name = 'درخواست'
        verbose_name_plural = 'درخواست‌ها'
        ordering = ['-created_at']

    def __str__(self) -> str:
        return f'{self.get_kind_display()} — {self.employee}'

    @property
    def is_leave(self) -> bool:
        return self.kind in self.LEAVE_KINDS


class Deputy(models.Model):
    employee = models.ForeignKey(
        'org.Employee', verbose_name='جانشین', on_delete=models.CASCADE,
        related_name='deputy_roles')
    for_employee = models.ForeignKey(
        'org.Employee', verbose_name='به‌جای', on_delete=models.CASCADE,
        related_name='deputies')
    from_date = models.DateField('از تاریخ')
    to_date = models.DateField('تا تاریخ')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'جانشین'
        verbose_name_plural = 'جانشین‌ها'
        unique_together = [('employee', 'for_employee', 'from_date')]


class RewardPenalty(models.Model):
    class Kind(models.TextChoices):
        REWARD = 'reward', 'پاداش'
        PENALTY = 'penalty', 'تنبیه'

    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='reward_penalties')
    kind = models.CharField('نوع', max_length=8, choices=Kind.choices)
    date = models.DateField('تاریخ', db_index=True)
    title = models.CharField('عنوان', max_length=200)
    amount = models.DecimalField('مبلغ/امتیاز', max_digits=12, decimal_places=0,
                                 default=0)
    description = models.CharField('شرح', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'پاداش/تنبیه'
        verbose_name_plural = 'پاداش‌ها/تنبیه‌ها'
        ordering = ['-date']


class OvertimeConstant(models.Model):
    key = models.CharField('کلید', max_length=40, unique=True)
    title = models.CharField('عنوان', max_length=120)
    value = models.DecimalField('مقدار', max_digits=8, decimal_places=2, default=0)
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'ثابت اضافه‌کاری'
        verbose_name_plural = 'ثابت‌های اضافه‌کاری'

    def __str__(self) -> str:
        return self.title


class GeoArea(models.Model):
    name = models.CharField('نام محدوده', max_length=120)
    lat = models.DecimalField('عرض', max_digits=9, decimal_places=6)
    lng = models.DecimalField('طول', max_digits=9, decimal_places=6)
    radius_m = models.PositiveIntegerField('شعاع (متر)', default=100)
    is_active = models.BooleanField('فعال', default=True)
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'محدوده مجاز'
        verbose_name_plural = 'محدوده‌های مجاز'

    def __str__(self) -> str:
        return self.name
