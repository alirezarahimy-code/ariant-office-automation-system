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
    # --- جزئیات شیفت (مطابق Schedule در T5Web) ---
    symbol = models.CharField('نماد', max_length=4, blank=True, default='')
    color = models.CharField('رنگ', max_length=7, blank=True, default='#1e88e5')
    in_from = models.TimeField('ورود: از', null=True, blank=True)
    in_to = models.TimeField('ورود: تا', null=True, blank=True)
    out_from = models.TimeField('خروج: از', null=True, blank=True)
    out_to = models.TimeField('خروج: تا', null=True, blank=True)
    launch_minutes = models.PositiveSmallIntegerField('ناهار (دقیقه)', default=0)
    rest_intervals = models.JSONField(
        'بازه‌های استراحت', default=list, blank=True,
        help_text='[{"from":"10:00","to":"10:15"}]')
    margin_before = models.PositiveSmallIntegerField('Interval قبل (دقیقه)', default=0)
    margin_after = models.PositiveSmallIntegerField('Interval بعد (دقیقه)', default=0)
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

    def __str__(self) -> str:
        return self.name


class AttendanceCalendar(models.Model):
    """تقویم‌های قابل اتصال به جدول زمانی (T5 Calendar)."""

    name = models.CharField('نام تقویم', max_length=120)

    class Meta:
        verbose_name = 'تقویم'
        verbose_name_plural = 'تقویم‌ها'
        ordering = ['name']

    def __str__(self) -> str:
        return self.name


class CalendarHoliday(models.Model):
    calendar = models.ForeignKey(
        AttendanceCalendar, verbose_name='تقویم', on_delete=models.CASCADE,
        related_name='holidays')
    date = models.DateField('تاریخ')
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'تعطیلی تقویم'
        verbose_name_plural = 'تعطیلات تقویم'
        ordering = ['date']
        constraints = [models.UniqueConstraint(
            fields=['calendar', 'date'], name='unique_calendar_holiday')]


class ScheduleDefinition(models.Model):
    """جدول زمانی T5 (Schedule): ساعات، پنجره‌های مجاز و استراحت."""

    name = models.CharField('نام جدول زمانی', max_length=120)
    symbol = models.CharField('نماد', max_length=8)
    from_time = models.TimeField('شروع کار')
    to_time = models.TimeField('پایان کار')
    ramadan_from_time = models.TimeField('شروع رمضان', null=True, blank=True)
    ramadan_to_time = models.TimeField('پایان رمضان', null=True, blank=True)
    start_from = models.TimeField('ورود از', null=True, blank=True)
    end_from = models.TimeField('ورود تا', null=True, blank=True)
    start_to = models.TimeField('خروج از', null=True, blank=True)
    end_to = models.TimeField('خروج تا', null=True, blank=True)
    late_minutes = models.PositiveSmallIntegerField('تأخیر مجاز (دقیقه)', default=15)
    early_minutes = models.PositiveSmallIntegerField('تعجیل مجاز (دقیقه)', default=15)
    work_days = models.DecimalField('روزهای کاری', max_digits=5, decimal_places=2, default=1)
    work_minutes = models.DecimalField('دقایق کاری', max_digits=7, decimal_places=2, default=480)
    suspend_minutes = models.PositiveSmallIntegerField('فاصله پس از شیفت (دقیقه)', default=0)
    interval_before_minutes = models.PositiveSmallIntegerField('فاصله قبل (دقیقه)', default=0)
    before_i = models.BooleanField('اعمال فاصله قبل', default=True)
    after_i = models.BooleanField('اعمال فاصله بعد', default=True)
    color = models.CharField('رنگ', max_length=7, default='#00ff00')
    rest_time = models.BooleanField('دارای زمان استراحت', default=False)
    rest_times = models.JSONField('بازه‌های استراحت', default=list, blank=True)
    rule = models.ForeignKey(
        ScheduleRule, verbose_name='قانون', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='schedules')
    calendar = models.ForeignKey(
        AttendanceCalendar, verbose_name='تقویم', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='schedules')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'جدول زمانی'
        verbose_name_plural = 'جداول زمانی'
        ordering = ['name', 'id']

    @property
    def start_time(self):
        return self.from_time

    @property
    def end_time(self):
        return self.to_time

    @property
    def grace_minutes(self):
        return self.late_minutes

    @property
    def work_day_indexes(self):
        # A date-specific T5 schedule applies to the date selected for it.
        return []

    def __str__(self) -> str:
        return f'{self.name} ({self.symbol})'


class TemporarySchedule(models.Model):
    """انتساب موقت جدول زمانی به کارمند در یک روز مشخص (TempSchedules)."""

    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='temporary_schedules')
    date = models.DateField('تاریخ')
    schedule = models.ForeignKey(
        ScheduleDefinition, verbose_name='جدول زمانی', null=True, blank=True,
        on_delete=models.CASCADE, related_name='temporary_assignments')

    class Meta:
        verbose_name = 'جدول زمانی موقت'
        verbose_name_plural = 'جداول زمانی موقت'
        ordering = ['-date', 'employee_id']
        constraints = [models.UniqueConstraint(
            fields=['employee', 'date'], name='unique_employee_temp_schedule_day')]


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
    check_type = models.PositiveIntegerField('نوع/کلید رکورد', default=0)
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


class ValidLeave(models.Model):
    """مجوز مرخصی معتبر (T5 ValidLeave / Permit)."""

    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='valid_leave_permits')
    from_date = models.DateField('از تاریخ')
    to_date = models.DateField('تا تاریخ')
    valid_minutes = models.PositiveIntegerField('مدت معتبر (دقیقه)')
    description = models.CharField('شرح', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'مجوز مرخصی معتبر'
        verbose_name_plural = 'مجوزهای مرخصی معتبر'
        ordering = ['-from_date', 'employee_id']


class LeaveDescription(models.Model):
    """شرح‌های مرجع برای مرخصی/ماموریت (T5 LeaveDescription)."""

    class ForLeaveType(models.IntegerChoices):
        DUTY = 0, 'ماموریت'
        LEAVE = 1, 'مرخصی'

    description = models.CharField('شرح', max_length=200)
    for_leave_type = models.PositiveSmallIntegerField(
        'مربوط به', choices=ForLeaveType.choices)

    class Meta:
        verbose_name = 'شرح مرخصی/ماموریت'
        verbose_name_plural = 'شرح‌های مرخصی/ماموریت'
        ordering = ['for_leave_type', 'description']

    def __str__(self) -> str:
        return self.description


class LeaveType(models.Model):
    class LeaveKind(models.TextChoices):
        DUTY = 'duty', 'ماموریت'
        LEAVE = 'leave', 'مرخصی'

    class GetInType(models.TextChoices):
        MONTH_FIRST = 'month_first', 'ابتدای ماه'
        MONTH_LAST = 'month_last', 'انتهای ماه'
        YEAR_FIRST = 'year_first', 'ابتدای سال'
        YEAR_LAST = 'year_last', 'انتهای سال'
        CUSTOM = 'custom', 'سفارشی'

    name = models.CharField('نام', max_length=100)
    symbol = models.CharField('نماد', max_length=24, blank=True, default='')
    leave_kind = models.CharField(
        'نوع مرخصی/ماموریت', max_length=8,
        choices=LeaveKind.choices, default=LeaveKind.LEAVE)
    is_hourly = models.BooleanField('ساعتی', default=True)
    color = models.CharField('رنگ', max_length=7, default='#000000')
    state_key = models.PositiveIntegerField('کلید وضعیت رکورد', default=4)
    max_hours_in_a_day_seconds = models.PositiveIntegerField(
        'حداکثر ساعتی روزانه (ثانیه)', default=6 * 3600)
    max_hours_in_a_month_seconds = models.PositiveIntegerField(
        'حداکثر ساعتی ماهانه (ثانیه)', default=12 * 3600)
    max_daily_in_year = models.DecimalField(
        'حداکثر روزانه در سال', max_digits=7, decimal_places=2, default=30)
    max_daily_in_month = models.DecimalField(
        'حداکثر روزانه در ماه', max_digits=7, decimal_places=2, default=2.5)
    each_x_hours_a_day_seconds = models.PositiveIntegerField(
        'هر چند ساعت یک روز', default=8 * 3600)
    get_in_type = models.CharField(
        'روش استحقاق', max_length=12,
        choices=GetInType.choices, default=GetInType.MONTH_FIRST)
    parameter = models.ForeignKey(
        'CalcParameter', verbose_name='پارامتر', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='leave_types')
    for_next_year = models.DecimalField(
        'انتقال به سال بعد', max_digits=7, decimal_places=2, default=9)
    for_next_year_sum = models.BooleanField('جمع انتقال سال بعد', default=False)
    max_minus = models.DecimalField(
        'حداکثر منفی', max_digits=7, decimal_places=2, default=0)
    payment = models.BooleanField('بدون پرداخت', default=False)
    decrease_from_leave = models.ForeignKey(
        'self', verbose_name='کسر از نوع مرخصی', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='decrease_target_types')
    get_in_holidays = models.BooleanField('محاسبه در تعطیلات', default=False)
    auto_in_holidays = models.BooleanField('محاسبه خودکار در تعطیلات', default=False)
    only_one = models.BooleanField('فقط یکبار', default=False)
    valid_free_ot = models.BooleanField('اعتبار اضافه‌کار آزاد', default=False)
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


class PreCard(models.Model):
    """T5Web PishCard configuration (CardBadgeNumber / device / state / interval)."""

    card_badge_number = models.PositiveBigIntegerField(
        'شماره کارت پیش‌کارت', unique=True)
    valid_secs = models.PositiveIntegerField('مدت اعتبار (ثانیه)')
    leave_type = models.ForeignKey(
        LeaveType, verbose_name='وضعیت کارت', on_delete=models.PROTECT,
        related_name='pre_cards')
    device = models.ForeignKey(
        'device.Device', verbose_name='دستگاه', on_delete=models.PROTECT,
        related_name='pre_cards')
    only_first = models.BooleanField('فقط اولین رکورد', default=False)

    class Meta:
        verbose_name = 'پیش‌کارت'
        verbose_name_plural = 'پیش‌کارت‌ها'
        ordering = ['card_badge_number']

    def __str__(self) -> str:
        return str(self.card_badge_number)


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


class WorkflowRule(models.Model):
    """قاعده گردش‌کار تأیید هر نوع درخواست (CustomWF)."""

    kind = models.CharField('نوع درخواست', max_length=14, unique=True,
                            choices=Request.Kind.choices)
    needs_approval = models.BooleanField('نیازمند تأیید', default=True)
    use_deputy = models.BooleanField('اجازه جانشین', default=True)
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'قاعده گردش‌کار'
        verbose_name_plural = 'قواعد گردش‌کار'

    def __str__(self) -> str:
        return self.get_kind_display()


class CalcParameter(models.Model):
    """پارامتر محاسباتی با فرمول (Parameters در T5Web) — خروجی ماهانه/فیش."""

    name = models.CharField('نام پارامتر', max_length=120)
    symbol = models.CharField('نماد یکتا', max_length=24, unique=True)
    formula = models.TextField(
        'فرمول', blank=True, default='',
        help_text='ترکیب نمادها/فیلدها؛ مثلاً [WorkedHours]-[MissionHours]')
    is_sum = models.BooleanField('جمع‌شونده', default=False)
    sum_function = models.CharField(
        'تابع تجمیع', max_length=8, blank=True, default='SUM',
        choices=[('SUM', 'SUM'), ('AVG', 'AVG'), ('MAX', 'MAX'), ('MIN', 'MIN')])
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'پارامتر محاسباتی'
        verbose_name_plural = 'پارامترهای محاسباتی'
        ordering = ['name']

    def __str__(self) -> str:
        return f'{self.name} ({self.symbol})'
