"""هسته: کاربر، نقش، مجوز، دامنه داده، گزارش دسترسی."""
from django.conf import settings
from django.contrib.auth.models import AbstractUser
from django.db import models


class User(AbstractUser):
    """حساب کاربری — ۱:۱ با کارمند (اختیاری)."""

    employee = models.OneToOneField(
        'org.Employee', verbose_name='کارمند', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='user')

    class Meta:
        verbose_name = 'کاربر'
        verbose_name_plural = 'کاربران'

    def __str__(self) -> str:
        return self.get_full_name() or self.username


class Permission(models.Model):
    """مجوز = ماژول + عمل (view, add, change, delete, approve, report, export, manage)."""

    MODULES = [
        ('core', 'هسته و تنظیمات'), ('org', 'سازمان و پرسنل'),
        ('device', 'دستگاه‌ها'), ('attendance', 'حضور و غیاب'),
        ('requests', 'درخواست‌ها'), ('reports', 'گزارش‌ها'),
    ]
    ACTIONS = [
        ('view', 'مشاهده'), ('add', 'افزودن'), ('change', 'ویرایش'),
        ('delete', 'حذف'), ('approve', 'تأیید'), ('report', 'گزارش'),
        ('export', 'خروجی'), ('manage', 'مدیریت کامل'),
    ]
    module = models.CharField('ماژول', max_length=20, choices=MODULES)
    action = models.CharField('عمل', max_length=10, choices=ACTIONS)

    class Meta:
        verbose_name = 'مجوز'
        verbose_name_plural = 'مجوزها'
        unique_together = [('module', 'action')]

    def __str__(self) -> str:
        return f'{self.get_module_display()} / {self.get_action_display()}'


class Role(models.Model):
    name = models.CharField('نام نقش', max_length=80)
    code = models.CharField('کد', max_length=40, unique=True)
    is_system = models.BooleanField('سیستمی', default=False)
    permissions = models.ManyToManyField(
        Permission, verbose_name='مجوزها', blank=True, related_name='roles')

    class Meta:
        verbose_name = 'نقش'
        verbose_name_plural = 'نقش‌ها'

    def __str__(self) -> str:
        return self.name


class RoleDataScope(models.Model):
    """دامنه داده هر نقش در هر ماژول (خودم/واحد/زیرمجموعه/همه)."""

    class Scope(models.TextChoices):
        SELF = 'self', 'فقط خودش'
        DEPT = 'dept', 'واحد خودش'
        DEPT_TREE = 'dept_tree', 'واحد و زیرمجموعه‌ها'
        ALL = 'all', 'همه'

    role = models.ForeignKey(
        Role, verbose_name='نقش', on_delete=models.CASCADE,
        related_name='data_scopes')
    module = models.CharField('ماژول', max_length=20, choices=Permission.MODULES)
    scope = models.CharField(
        'دامنه', max_length=10, choices=Scope.choices, default=Scope.SELF)

    class Meta:
        verbose_name = 'دامنه داده'
        verbose_name_plural = 'دامنه‌های داده'
        unique_together = [('role', 'module')]


class UserRole(models.Model):
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر',
        on_delete=models.CASCADE, related_name='user_roles')
    role = models.ForeignKey(
        Role, verbose_name='نقش', on_delete=models.CASCADE,
        related_name='user_roles')

    class Meta:
        verbose_name = 'نقش کاربر'
        verbose_name_plural = 'نقش‌های کاربران'
        unique_together = [('user', 'role')]


class ProgramAccess(models.Model):
    """T5-owned grants for the three distinct applications.

    These rows define program entry, while Role/Permission define actions
    inside a program. TWF and Link5 will consume this same identity contract.
    """

    class Program(models.TextChoices):
        T5WEB = 't5web', 'T5Web'
        TWF = 'twf', 'TWF'
        LINK5 = 'link5', 'Link5'

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر',
        on_delete=models.CASCADE, related_name='program_access')
    program = models.CharField('برنامه', max_length=12, choices=Program.choices)
    is_allowed = models.BooleanField('دسترسی مجاز', default=False)
    granted_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تعیین‌کننده',
        null=True, blank=True, on_delete=models.SET_NULL,
        related_name='granted_program_access')
    updated_at = models.DateTimeField('آخرین تغییر', auto_now=True)

    class Meta:
        verbose_name = 'دسترسی به برنامه'
        verbose_name_plural = 'دسترسی‌های برنامه'
        constraints = [
            models.UniqueConstraint(
                fields=['user', 'program'], name='unique_user_program_access')
        ]
        ordering = ['program']

    def __str__(self) -> str:
        return f'{self.user} — {self.get_program_display()}'


class AuditLog(models.Model):
    """گزارش دسترسی/عملیات — همه رویدادهای حساس."""

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='audit_logs')
    action = models.CharField('عمل', max_length=40)
    model = models.CharField('مدل', max_length=60, blank=True, default='')
    object_id = models.CharField('شناسه', max_length=40, blank=True, default='')
    detail = models.TextField('جزئیات', blank=True, default='')
    ip = models.GenericIPAddressField('آی‌پی', null=True, blank=True)
    at = models.DateTimeField('زمان', auto_now_add=True, db_index=True)

    class Meta:
        verbose_name = 'گزارش دسترسی'
        verbose_name_plural = 'گزارش‌های دسترسی'
        ordering = ['-at']

    def __str__(self) -> str:
        return f'{self.action} @ {self.at:%Y-%m-%d %H:%M}'


class AppSetting(models.Model):
    key = models.CharField('کلید', max_length=60, unique=True)
    value = models.TextField('مقدار', blank=True, default='')
    title = models.CharField('عنوان', max_length=120, blank=True, default='')

    class Meta:
        verbose_name = 'تنظیم'
        verbose_name_plural = 'تنظیمات'

    def __str__(self) -> str:
        return self.title or self.key


class NotificationTemplate(models.Model):
    kind = models.CharField('نوع', max_length=40, unique=True)
    title = models.CharField('عنوان', max_length=120)
    body = models.TextField('متن')

    class Meta:
        verbose_name = 'قالب اعلان'
        verbose_name_plural = 'قالب‌های اعلان'

    def __str__(self) -> str:
        return self.title
