"""سازمان و پرسنل: واحد، سمت، نوع استخدام، کارمند، فیلد سفارشی."""
from django.conf import settings
from django.db import models


class Department(models.Model):
    name = models.CharField('نام واحد', max_length=120)
    code = models.CharField('کد', max_length=24, unique=True)
    parent = models.ForeignKey(
        'self', verbose_name='واحد بالادست', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='children')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'واحد سازمانی'
        verbose_name_plural = 'واحدهای سازمانی'
        ordering = ['code']

    def __str__(self) -> str:
        return self.name

    def descendants(self):
        """خودش + همه زیرمجموعه‌ها."""
        result = [self]
        for child in self.children.filter(is_active=True):
            result.extend(child.descendants())
        return result


class Position(models.Model):
    """سمت سازمانی — چارت با سمت بالادست (TitlesChart)."""

    title = models.CharField('عنوان سمت', max_length=120)
    parent = models.ForeignKey(
        'self', verbose_name='سمت بالادست', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='children')
    department = models.ForeignKey(
        Department, verbose_name='واحد', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='positions')
    is_manager = models.BooleanField('سمت مدیریتی', default=False)
    display_name = models.CharField('نام نمایشی', max_length=120, blank=True, default='')
    address = models.TextField('نشانی', blank=True, default='')
    email_address = models.EmailField('ایمیل', blank=True, default='')
    duties = models.TextField('شرح وظایف', blank=True, default='')
    is_guard = models.BooleanField('نگهبان', default=False)
    guard_departments = models.ManyToManyField(
        Department, verbose_name='واحدهای دارای دسترسی نگهبانی', blank=True,
        related_name='guard_positions')
    deputy_titles = models.ManyToManyField(
        'self', verbose_name='سمت‌های جانشین', blank=True, symmetrical=False,
        related_name='deputy_for_titles')

    class Meta:
        verbose_name = 'سمت'
        verbose_name_plural = 'سمت‌ها'
        unique_together = [('title', 'department')]

    def __str__(self) -> str:
        return self.title


class Certificate(models.Model):
    """T5 Certificate catalog (CertificateID/CertificateName)."""

    name = models.CharField('مدرک تحصیلی', max_length=255, unique=True)

    class Meta:
        verbose_name = 'مدرک تحصیلی'
        verbose_name_plural = 'مدارک تحصیلی'
        ordering = ['name']

    def __str__(self) -> str:
        return self.name


class EmpType(models.Model):
    name = models.CharField('نام', max_length=80)
    code = models.CharField('کد', max_length=24, unique=True)
    valid_leave_types = models.ManyToManyField(
        'attendance.LeaveType', verbose_name='مرخصی‌های مجاز', blank=True,
        related_name='employment_types')

    class Meta:
        verbose_name = 'نوع استخدام'
        verbose_name_plural = 'انواع استخدام'

    def __str__(self) -> str:
        return self.name


class Employee(models.Model):
    """پروفایل کارمند — هویت واحد (BadgeNumber در مرجع)."""

    badge_no = models.CharField('شماره کارگری', max_length=24, unique=True)
    first_name = models.CharField('نام', max_length=60)
    last_name = models.CharField('نام خانوادگی', max_length=80)
    national_id = models.CharField('کد ملی', max_length=10, blank=True, default='')
    emp_type = models.ForeignKey(
        EmpType, verbose_name='نوع استخدام', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='employees')
    department = models.ForeignKey(
        Department, verbose_name='واحد', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='employees')
    position = models.ForeignKey(
        Position, verbose_name='سمت', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='employees')
    manager = models.ForeignKey(
        'self', verbose_name='مدیر مستقیم', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='subordinates')
    hire_date = models.DateField('تاریخ استخدام', null=True, blank=True)
    # --- فیلدهای کامل فایل پرسنلی (مطابق UserInfo در T5Web) ---
    personnel_no = models.CharField('شماره پرسنلی', max_length=24, blank=True, default='')
    father_name = models.CharField('نام پدر', max_length=60, blank=True, default='')
    gender = models.CharField(
        'جنسیت', max_length=1, blank=True, default='',
        choices=[('M', 'مرد'), ('F', 'زن')])
    birthday = models.DateField('تاریخ تولد', null=True, blank=True)
    certificate = models.CharField('مدرک تحصیلی', max_length=255, blank=True, default='')
    certificate_ref = models.ForeignKey(
        Certificate, verbose_name='مدرک مرجع', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='employees')
    email = models.EmailField('ایمیل', blank=True, default='')
    tel = models.CharField('تلفن', max_length=30, blank=True, default='')
    address = models.TextField('نشانی', blank=True, default='')
    janbaz_code = models.CharField('کد جانبازی', max_length=30, blank=True, default='')
    photo = models.CharField('عکس (مسیر/آدرس)', max_length=255, blank=True, default='')
    end_work_date = models.DateField('تاریخ پایان همکاری', null=True, blank=True)
    # --- کارت/دستگاه ---
    device_name = models.CharField('نام دستگاه', max_length=120, blank=True, default='')
    device_card_no = models.CharField('شماره کارت دستگاه', max_length=24, blank=True, default='')
    device_enable = models.BooleanField('فعال روی دستگاه', default=True)
    device_group = models.PositiveSmallIntegerField('گروه دستگاه', default=1)
    device_password = models.CharField('رمز دستگاه', max_length=24, blank=True, default='')
    device_privilege = models.PositiveSmallIntegerField('سطح دسترسی دستگاه', default=0)
    # --- کاربر سامانه/اعتبار دسترسی ---
    web_user = models.BooleanField('کاربر وب', default=False)
    program_user = models.BooleanField('کاربر برنامه', default=False)
    valid_from = models.DateTimeField('شروع اعتبار', null=True, blank=True)
    valid_to = models.DateTimeField('پایان اعتبار', null=True, blank=True)
    upload_devices = models.ManyToManyField(
        'device.Device', verbose_name='دستگاه‌های ارسال کاربر', blank=True,
        related_name='employees_for_upload')
    valid_devices = models.ManyToManyField(
        'device.Device', verbose_name='دستگاه‌های مجاز کاربر', blank=True,
        related_name='employees_with_valid_access')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'کارمند'
        verbose_name_plural = 'کارمندان'
        ordering = ['last_name', 'first_name']

    def __str__(self) -> str:
        return f'{self.first_name} {self.last_name} ({self.badge_no})'


class ManagerProfile(models.Model):
    """T5 manager metadata, kept separate from login and app-wide roles.

    Access types remain integer keys from the reference; this profile stores
    the selected Allow/Deny/ReadOnly labels but does not grant Django roles.
    """

    class AccessLevel(models.TextChoices):
        ALLOW = 'allow', 'Allow'
        DENY = 'deny', 'Deny'
        READ_ONLY = 'read_only', 'ReadOnly'

    employee = models.OneToOneField(
        Employee, verbose_name='پرسنل مدیر', on_delete=models.CASCADE,
        related_name='t5_manager_profile')
    name = models.CharField('نام مدیر در T5', max_length=160, blank=True, default='')
    is_main_manager = models.BooleanField('مدیر اصلی', default=False)
    allowed_users = models.ManyToManyField(
        Employee, verbose_name='پرسنل مجاز', blank=True,
        related_name='t5_managers_allowed')
    allowed_devices = models.ManyToManyField(
        'device.Device', verbose_name='دستگاه‌های مجاز', blank=True,
        related_name='t5_manager_profiles')

    class Meta:
        verbose_name = 'پروفایل مدیر T5'
        verbose_name_plural = 'پروفایل‌های مدیر T5'

    def __str__(self):
        return self.name or str(self.employee)


class ManagerAccessRule(models.Model):
    """Raw T5 AccessType integer with its displayed access-level label."""

    manager = models.ForeignKey(
        ManagerProfile, on_delete=models.CASCADE, related_name='access_rules')
    access_type = models.PositiveIntegerField('کلید AccessType مرجع')
    access_level = models.CharField(
        'سطح دسترسی', max_length=12, choices=ManagerProfile.AccessLevel.choices)

    class Meta:
        verbose_name = 'دسترسی نوعی مدیر T5'
        verbose_name_plural = 'دسترسی‌های نوعی مدیر T5'
        unique_together = [('manager', 'access_type')]
        ordering = ['access_type']


class EmployeeFieldDef(models.Model):
    """فیلد سفارشی پرسنل (UserAddProperty)."""

    class FieldType(models.TextChoices):
        TEXT = 'text', 'متن'
        NUMBER = 'number', 'عدد'
        DATE = 'date', 'تاریخ'
        DROPDOWN = 'dropdown', 'فهرستی'
        BOOLEAN = 'boolean', 'بله/خیر'

    title = models.CharField('عنوان', max_length=120)
    field_type = models.CharField(
        'نوع', max_length=10, choices=FieldType.choices, default=FieldType.TEXT)
    options = models.TextField(
        'گزینه‌ها', blank=True, default='',
        help_text='برای نوع فهرستی — هر گزینه در یک سطر یا جداشده با |')
    is_active = models.BooleanField('فعال', default=True)
    is_search_filter = models.BooleanField('فیلتر جستجوی پیشرفته', default=False)

    class Meta:
        verbose_name = 'فیلد سفارشی'
        verbose_name_plural = 'فیلدهای سفارشی'

    def __str__(self) -> str:
        return self.title


class EmployeeFieldVal(models.Model):
    employee = models.ForeignKey(
        Employee, verbose_name='کارمند', on_delete=models.CASCADE,
        related_name='field_values')
    field_def = models.ForeignKey(
        EmployeeFieldDef, verbose_name='فیلد', on_delete=models.CASCADE,
        related_name='values')
    value = models.TextField('مقدار', blank=True, default='')

    class Meta:
        verbose_name = 'مقدار فیلد سفارشی'
        verbose_name_plural = 'مقادیر فیلدهای سفارشی'
        unique_together = [('employee', 'field_def')]
