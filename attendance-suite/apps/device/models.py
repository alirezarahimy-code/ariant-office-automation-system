"""دستگاه‌ها: ترمینال تردد، کارت، رکورد خام، فرمان، قالب بیومتریک، بازدیدکننده."""
from django.conf import settings
from django.db import models


class Device(models.Model):
    """ترمینال تردد (ZKTeco و سازگار)."""

    class ConnType(models.TextChoices):
        TCP = 'tcp', 'TCP'
        UDP = 'udp', 'UDP'
        PUSH = 'push', 'Push (اتصال از دستگاه)'

    class Brand(models.TextChoices):
        ZK = 'zk', 'ZKTeco / سازگار (ZK)'
        VIRDI = 'virdi', 'Virdi (از طریق یونیس)'
        OTHER = 'other', 'سایر'

    name = models.CharField('نام', max_length=120)
    serial = models.CharField('سریال', max_length=60, unique=True)
    brand = models.CharField(
        'برند', max_length=10, choices=Brand.choices, default=Brand.ZK)
    model = models.CharField('مدل', max_length=60, blank=True, default='')
    ip = models.GenericIPAddressField('آی‌پی', null=True, blank=True)
    port = models.PositiveIntegerField('پورت', default=437)
    conn_type = models.CharField(
        'نوع اتصال', max_length=6, choices=ConnType.choices, default=ConnType.TCP)
    is_active = models.BooleanField('فعال', default=True)
    last_read_at = models.DateTimeField('آخرین خواندن', null=True, blank=True)
    last_online_at = models.DateTimeField('آخرین آنلاین', null=True, blank=True)
    records_count = models.BigIntegerField('شمارنده رکورد', default=0)
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'دستگاه'
        verbose_name_plural = 'دستگاه‌ها'
        ordering = ['name']

    def __str__(self) -> str:
        return f'{self.name} ({self.serial})'


class AccessPoint(models.Model):
    """گیت/در (Gate, MapDoor)."""

    name = models.CharField('نام', max_length=120)
    device = models.ForeignKey(
        Device, verbose_name='دستگاه', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='access_points')
    description = models.CharField('شرح', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'گیت/در'
        verbose_name_plural = 'گیت‌ها/درب‌ها'

    def __str__(self) -> str:
        return self.name


class Card(models.Model):
    card_no = models.CharField('شماره کارت', max_length=40, unique=True)
    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='cards')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'کارت'
        verbose_name_plural = 'کارت‌ها'

    def __str__(self) -> str:
        return self.card_no


class BlackList(models.Model):
    card_no = models.CharField('شماره کارت', max_length=40, unique=True)
    reason = models.CharField('دلیل', max_length=200, blank=True, default='')
    created_at = models.DateTimeField('ایجاد', auto_now_add=True)

    class Meta:
        verbose_name = 'کارت مسدود'
        verbose_name_plural = 'کارت‌های مسدود'


class RawRecord(models.Model):
    """رکورد خام دستگاه (RecordsLog) — با وضعیت انتقال ضدتکرار."""

    class State(models.TextChoices):
        NEW = 'new', 'تازه'
        TRANSFERED = 'transfered', 'منتقل‌شده'
        ERROR = 'error', 'خطا'

    device = models.ForeignKey(
        Device, verbose_name='دستگاه', on_delete=models.CASCADE,
        related_name='raw_records')
    pin = models.CharField('کد پرسنلی دستگاه', max_length=24, db_index=True)
    punch_time = models.DateTimeField('زمان رویداد', db_index=True)
    verify_mode = models.PositiveSmallIntegerField('نوع تأیید', default=0)
    work_code = models.CharField('کد کار', max_length=12, blank=True, default='')
    state = models.CharField(
        'وضعیت', max_length=10, choices=State.choices, default=State.NEW,
        db_index=True)
    payload = models.TextField('بار خام', blank=True, default='')
    created_at = models.DateTimeField('ایجاد', auto_now_add=True)

    class Meta:
        verbose_name = 'رکورد خام'
        verbose_name_plural = 'رکوردهای خام'
        unique_together = [('device', 'pin', 'punch_time')]
        ordering = ['-punch_time']


class DeviceCommand(models.Model):
    """فرمان به دستگاه (PushCommand)."""

    class State(models.TextChoices):
        PENDING = 'pending', 'در صف'
        SENT = 'sent', 'ارسال‌شده'
        DONE = 'done', 'انجام‌شده'
        FAILED = 'failed', 'ناموفق'

    device = models.ForeignKey(
        Device, verbose_name='دستگاه', on_delete=models.CASCADE,
        related_name='commands')
    cmd_type = models.CharField('نوع فرمان', max_length=60)
    payload = models.TextField('بار', blank=True, default='')
    state = models.CharField(
        'وضعیت', max_length=10, choices=State.choices, default=State.PENDING)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ایجادکننده', null=True,
        blank=True, on_delete=models.SET_NULL, related_name='device_commands')
    created_at = models.DateTimeField('ایجاد', auto_now_add=True)

    class Meta:
        verbose_name = 'فرمان دستگاه'
        verbose_name_plural = 'فرمان‌های دستگاه'
        ordering = ['-created_at']


class BioTemplate(models.Model):
    """فهرست قالب‌های بیومتریک (مدیریت — نگهداری خود قالب بر عهده دستگاه)."""

    class BioType(models.TextChoices):
        FP = 'fp', 'اثر انگشت'
        PALM = 'palm', 'کف دست'
        FACE_VL = 'face_vl', 'چهره مرئی'
        FACE_IR = 'face_ir', 'چهره مادون‌قرمز'

    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='bio_templates')
    bio_type = models.CharField('نوع', max_length=8, choices=BioType.choices)
    version = models.CharField('نسخه قالب', max_length=8, blank=True, default='')
    device = models.ForeignKey(
        Device, verbose_name='دستگاه ثبت', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='bio_templates')
    size = models.PositiveIntegerField('اندازه', default=0)

    class Meta:
        verbose_name = 'قالب بیومتریک'
        verbose_name_plural = 'قالب‌های بیومتریک'
        unique_together = [('employee', 'bio_type', 'device')]


class DeviceUserSync(models.Model):
    """همگام‌سازی کاربر با دستگاه (UserDeviceUpload/Valid)."""

    class Direction(models.TextChoices):
        UP = 'up', 'ارسال به دستگاه'
        DOWN = 'down', 'دریافت از دستگاه'

    class State(models.TextChoices):
        PENDING = 'pending', 'در انتظار'
        DONE = 'done', 'انجام‌شده'
        FAILED = 'failed', 'ناموفق'

    device = models.ForeignKey(
        Device, verbose_name='دستگاه', on_delete=models.CASCADE,
        related_name='user_syncs')
    employee = models.ForeignKey(
        'org.Employee', verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='device_syncs')
    direction = models.CharField(
        'جهت', max_length=6, choices=Direction.choices, default=Direction.UP)
    state = models.CharField(
        'وضعیت', max_length=10, choices=State.choices, default=State.PENDING)
    updated_at = models.DateTimeField('به‌روزرسانی', auto_now=True)

    class Meta:
        verbose_name = 'همگام‌سازی کاربر دستگاه'
        verbose_name_plural = 'همگام‌سازی‌های کاربران دستگاه'
        unique_together = [('device', 'employee', 'direction')]


class VisitReason(models.Model):
    name = models.CharField('علت مراجعه', max_length=120)

    class Meta:
        verbose_name = 'علت مراجعه'
        verbose_name_plural = 'علل مراجعه'

    def __str__(self) -> str:
        return self.name


class Visitor(models.Model):
    name = models.CharField('نام بازدیدکننده', max_length=160)
    card_no = models.CharField('شماره کارت', max_length=40, blank=True, default='')
    reason = models.ForeignKey(
        VisitReason, verbose_name='علت', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='visitors')
    host = models.ForeignKey(
        'org.Employee', verbose_name='میزبان', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='visitors')
    enter_time = models.DateTimeField('ورود')
    leave_time = models.DateTimeField('خروج', null=True, blank=True)
    description = models.CharField('شرح', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'بازدیدکننده'
        verbose_name_plural = 'بازدیدکنندگان'
        ordering = ['-enter_time']


class AccessGroup(models.Model):
    """گروه دسترسی فیزیکی (AccessGroup)."""

    name = models.CharField('نام گروه', max_length=120)
    employees = models.ManyToManyField(
        'org.Employee', verbose_name='اعضا', blank=True, related_name='access_groups')
    access_points = models.ManyToManyField(
        AccessPoint, verbose_name='گیت‌ها', blank=True, related_name='access_groups')

    class Meta:
        verbose_name = 'گروه دسترسی'
        verbose_name_plural = 'گروه‌های دسترسی'

    def __str__(self) -> str:
        return self.name


class UnisBridge(models.Model):
    """پل نرم‌افزار یونیس (UNIS Manager — Union Community / Virdi).

    دستگاه‌های Virdi به نرم‌افزار یونیس وصل‌اند و لاگ تردد در بانک یونیس
    (MS SQL / MySQL / Oracle / Access) یا خروجی فایل آن می‌نشیند؛ این مدل
    همگام‌سازی آن لاگ‌ها را به خط لوله واحد سامانه تعریف می‌کند.
    """

    class Mode(models.TextChoices):
        DB = 'db', 'بانک اطلاعاتی یونیس'
        DIR = 'dir', 'پوشه خروجی فایل (CSV/USB)'

    name = models.CharField('نام', max_length=120)
    device = models.ForeignKey(
        Device, verbose_name='دستگاه مقصد', on_delete=models.CASCADE,
        related_name='unis_bridges',
        help_text='دستگاهی که رکوردها به نام آن ثبت می‌شوند (می‌تواند مجازی باشد).')
    mode = models.CharField(
        'حالت دریافت', max_length=4, choices=Mode.choices, default=Mode.DB)
    table_name = models.CharField(
        'جدول لاگ در یونیس', max_length=120, blank=True, default='',
        help_text='مثلاً T_LOG — فقط نام معتبر شناسه‌ای SQL')
    col_pin = models.CharField('ستون کد پرسنلی', max_length=64, blank=True, default='')
    col_time = models.CharField('ستون زمان', max_length=64, blank=True, default='')
    col_direction = models.CharField(
        'ستون جهت (اختیاری)', max_length=64, blank=True, default='')
    dir_path = models.CharField(
        'مسیر پوشه خروجی', max_length=255, blank=True, default='',
        help_text='پوشه‌ای که یونیس/دستگاه در آن فایل CSV می‌گذارد.')
    batch_limit = models.PositiveIntegerField('سقف هر نوبت خواندن', default=5000)
    is_active = models.BooleanField('فعال', default=True)
    last_sync_at = models.DateTimeField('آخرین همگام‌سازی', null=True, blank=True)
    last_watermark = models.CharField(
        'نشانگر آخرین رکورد', max_length=40, blank=True, default='')
    last_result = models.CharField('آخرین نتیجه', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'پل یونیس'
        verbose_name_plural = 'پل‌های یونیس'
        ordering = ['name']

    def __str__(self) -> str:
        return f'{self.name} ({self.get_mode_display()})'
