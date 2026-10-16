"""مدل‌های پایه مشترک: زمان‌بندی، حذف نرم و ممیزی تغییرات."""
from __future__ import annotations

from django.conf import settings
from django.core.exceptions import ValidationError
from django.db import models
from django_jalali.db import models as jmodels


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


class Notification(TimeStampedModel):
    """اعلان درون‌سامانه‌ای (فاز ۱؛ ایمیل در فاز ۲ به همین مدل وصل می‌شود)."""

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='گیرنده',
        on_delete=models.CASCADE, related_name='notifications')
    title = models.CharField('عنوان', max_length=200)
    body = models.TextField('متن', blank=True, default='')
    url = models.CharField('پیوند', max_length=300, blank=True, default='')
    is_read = models.BooleanField('خوانده‌شده', default=False)

    class Meta:
        verbose_name = 'اعلان'
        verbose_name_plural = 'اعلان‌ها'
        ordering = ['-created_at']
        indexes = [models.Index(fields=['user', 'is_read'], name='idx_notif_user_read')]

    def __str__(self) -> str:
        return f'{self.user_id}: {self.title}'


class Delegation(TimeStampedModel):
    """جانشینی/تفویض: کاربر ب در بازه زمانی، کارتابل کاربر الف را می‌بیند."""

    from_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تفویض‌کننده',
        on_delete=models.CASCADE, related_name='delegations_given')
    to_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='جانشین',
        on_delete=models.CASCADE, related_name='delegations_received')
    start_date = models.DateField('از تاریخ')
    end_date = models.DateField('تا تاریخ')
    is_active = models.BooleanField('فعال', default=True)
    note = models.CharField('یادداشت', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'جانشینی'
        verbose_name_plural = 'جانشینی‌ها'
        ordering = ['-start_date']

    def clean(self):
        if self.from_user_id and self.from_user_id == self.to_user_id:
            raise ValidationError('تفویض‌کننده و جانشین نمی‌توانند یک نفر باشند.')
        if self.start_date and self.end_date and self.end_date < self.start_date:
            raise ValidationError('تاریخ پایان پیش از شروع است.')

    @classmethod
    def active_delegators(cls, user, on=None):
        """کاربرانی که در تاریخ داده جانشین‌شان «user» است."""
        from datetime import date as _date
        on = on or _date.today()
        return [
            d.from_user_id for d in cls.objects.filter(
                to_user=user, is_active=True, start_date__lte=on, end_date__gte=on)
        ]


class Holiday(TimeStampedModel):
    """تعطیل رسمی — تاریخ شمسی؛ پایه محاسبه روزهای کاری و مهلت‌ها."""

    date = jmodels.jDateField('تاریخ', unique=True)
    title = models.CharField('عنوان', max_length=200)

    class Meta:
        verbose_name = 'تعطیل رسمی'
        verbose_name_plural = 'تعطیلات رسمی'
        ordering = ['-date']

    def __str__(self) -> str:
        return f'{self.date}: {self.title}'


class OrgSettings(TimeStampedModel):
    """تنظیمات سامانه (تک‌رکوردی): نام سازمان، شعار، سال مالی."""

    org_name = models.CharField('نام سازمان', max_length=200)
    motto = models.CharField('شعار/زیرنویس', max_length=200, blank=True, default='')
    fiscal_year = models.CharField('سال مالی', max_length=20, blank=True, default='')

    class Meta:
        verbose_name = 'تنظیمات سامانه'
        verbose_name_plural = 'تنظیمات سامانه'

    def __str__(self) -> str:
        return self.org_name

    @classmethod
    def load(cls) -> 'OrgSettings':
        """نمونه تک‌رکوردی — همیشه یک ردیف برمی‌گرداند."""
        obj, _ = cls.objects.get_or_create(
            pk=1, defaults={'org_name': 'سازمان آرینت'})
        return obj


class UserProfile(TimeStampedModel):
    """پروفایل کاربر: شماره تماس و امضای الکترونیک (برای نامه‌ها)."""

    user = models.OneToOneField(
        settings.AUTH_USER_MODEL, verbose_name='کاربر',
        on_delete=models.CASCADE, related_name='profile')
    phone = models.CharField('شماره تماس', max_length=20, blank=True, default='')
    signature = models.FileField(
        'امضای الکترونیک', upload_to='signatures/', blank=True, default='')

    class Meta:
        verbose_name = 'پروفایل کاربر'
        verbose_name_plural = 'پروفایل‌های کاربران'

    def __str__(self) -> str:
        return f'پروفایل {self.user}'

    @classmethod
    def for_user(cls, user) -> 'UserProfile':
        obj, _ = cls.objects.get_or_create(user=user)
        return obj


#: فهرست قابلیت‌های قابل روشن/خاموش — «اجزا همیشه هستند؛ سازمان می‌تواند غیرفعالشان کند»
FEATURE_REGISTRY: dict[str, tuple[str, str]] = {
    # code: (عنوان, گروه)
    'secretariat.casefiles': ('پرونده‌های مکاتباتی (کلاسور)', 'دبیرخانه'),
    'secretariat.register_book': ('دفتر اندیکاتور', 'دبیرخانه'),
    'secretariat.index_book': ('دفتر اندکس (طبقه‌بندی موضوعی)', 'دبیرخانه'),
    'secretariat.topics': ('موضوعات و کلیدواژه‌ها', 'دبیرخانه'),
    'secretariat.register_check': ('کنترل پیش از ثبت (چک‌لیست مسئول دفتر)', 'دبیرخانه'),
    'secretariat.signatures': ('تأیید/امضای چندنفره', 'دبیرخانه'),
    'secretariat.revisions_lock': ('نسخه‌ها و قفل نامه پس از امضا', 'دبیرخانه'),
    'secretariat.copy_action': ('رونوشت «جهت اقدام»', 'دبیرخانه'),
    'secretariat.sla': ('مهلت اقدام و یادآوری (SLA)', 'دبیرخانه'),
    'secretariat.read_receipt': ('رسید «دریافت شد» و گزارش رؤیت', 'دبیرخانه'),
    'secretariat.number_reserve': ('رزرو شماره / شماره آزاد', 'دبیرخانه'),
    'secretariat.correspondents': ('دفترچه طرف‌های مکاتبه', 'دبیرخانه'),
    'secretariat.frequent_phrases': ('موضوعات و شرح‌های پرتکرار', 'دبیرخانه'),
    'secretariat.memo_letters': ('نامه‌های یادداشتی (اداری)', 'دبیرخانه'),
    'secretariat.paraph': ('پاراف (دیده‌شدن روی متن)', 'دبیرخانه'),
    'secretariat.answer_pairing': ('ثبت مقابل (زنجیره وارده ↔ صادره)', 'دبیرخانه'),
    'secretariat.global_search': ('جست‌وجوی سراسری (Ctrl+K)', 'عمومی'),
}


class FeatureToggle(TimeStampedModel):
    """کلید روشن/خاموش قابلیت‌ها — اجزا حذف نمی‌شوند؛ فقط غیرفعال می‌شوند."""

    code = models.SlugField('کد قابلیت', max_length=60, unique=True)
    title = models.CharField('عنوان', max_length=150)
    group = models.CharField('گروه', max_length=40, default='دبیرخانه')
    description = models.TextField('توضیح', blank=True, default='')
    is_enabled = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'کلید قابلیت'
        verbose_name_plural = 'کلیدهای قابلیت'
        ordering = ['group', 'code']

    def __str__(self) -> str:
        return f'{self.title} ({"فعال" if self.is_enabled else "غیرفعال"})'
