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

    class Meta:
        verbose_name = 'سمت'
        verbose_name_plural = 'سمت‌ها'
        unique_together = [('title', 'department')]

    def __str__(self) -> str:
        return self.title


class EmpType(models.Model):
    name = models.CharField('نام', max_length=80)
    code = models.CharField('کد', max_length=24, unique=True)

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
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'کارمند'
        verbose_name_plural = 'کارمندان'
        ordering = ['last_name', 'first_name']

    def __str__(self) -> str:
        return f'{self.first_name} {self.last_name} ({self.badge_no})'


class EmployeeFieldDef(models.Model):
    """فیلد سفارشی پرسنل (UserAddProperty)."""

    class FieldType(models.TextChoices):
        TEXT = 'text', 'متن'
        NUMBER = 'number', 'عدد'
        DATE = 'date', 'تاریخ'
        DROPDOWN = 'dropdown', 'فهرستی'

    title = models.CharField('عنوان', max_length=120)
    field_type = models.CharField(
        'نوع', max_length=10, choices=FieldType.choices, default=FieldType.TEXT)
    options = models.TextField(
        'گزینه‌ها', blank=True, default='',
        help_text='برای نوع فهرستی — هر گزینه در یک سطر')
    is_active = models.BooleanField('فعال', default=True)

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
