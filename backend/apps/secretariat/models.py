"""مدل‌های دبیرخانه: دفتر، توالی شماره، نامه، ارجاع، رونوشت، پیوست و رویداد.

الگوهای مأخوذ از سامانه تیکتینگ (مرجع: docs/14):
- توالی شماره‌گذاری همسان ``ticket_number_seq`` (جدول NumberSequence)
- زنجیره رویدادها همسان ``ticket_events`` (جدول LetterEvent)
- سطوح داده own/unit/all همسان کدهای مجوز ``ticket.view_*``
"""
from __future__ import annotations

from django.conf import settings
from django.core.exceptions import ValidationError
from django.db import models
from django_jalali.db import models as jmodels
from simple_history.models import HistoricalRecords

from apps.core.models import SoftDeleteModel, TimeStampedModel
from apps.core.persian import to_persian_digits
from apps.organization.models import Department


class Office(TimeStampedModel):
    """دبیرخانه — واحد ثبت و شماره‌گذاری مکاتبات (چند دبیرخانه با سریال مستقل)."""

    name = models.CharField('نام دبیرخانه', max_length=150)
    code = models.CharField('کد دبیرخانه', max_length=20, unique=True)
    department = models.ForeignKey(
        Department, verbose_name='واحد سازمانی', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='secretariat_offices')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'دبیرخانه'
        verbose_name_plural = 'دبیرخانه‌ها'
        ordering = ['name']

    def __str__(self) -> str:
        return self.name


class NumberSequence(models.Model):
    """توالی شماره‌گذاری به تفکیک دبیرخانه/نوع/سال شمسی — همزان‌امن با select_for_update."""

    office = models.ForeignKey(
        Office, verbose_name='دبیرخانه', on_delete=models.CASCADE, related_name='sequences')
    letter_type = models.CharField('نوع نامه', max_length=10)
    jalali_year = models.PositiveSmallIntegerField('سال شمسی')
    last_serial = models.PositiveIntegerField('آخرین شماره', default=0)

    class Meta:
        verbose_name = 'توالی شماره‌گذاری'
        verbose_name_plural = 'توالی‌های شماره‌گذاری'
        constraints = [
            models.UniqueConstraint(
                fields=['office', 'letter_type', 'jalali_year'],
                name='uq_sequence_office_type_year'),
        ]

    def __str__(self) -> str:
        return f'{self.office} / {self.letter_type} / {self.jalali_year} → {self.last_serial}'


class Letter(TimeStampedModel, SoftDeleteModel):
    """نامه: وارده، صادره یا داخلی — هسته سامانه دبیرخانه."""

    class LetterType(models.TextChoices):
        INCOMING = 'incoming', 'وارده'
        OUTGOING = 'outgoing', 'صادره'
        INTERNAL = 'internal', 'داخلی'

    class Priority(models.TextChoices):
        NORMAL = 'normal', 'عادی'
        URGENT = 'urgent', 'فوری'
        IMMEDIATE = 'immediate', 'آنی'

    class Confidentiality(models.TextChoices):
        NORMAL = 'normal', 'عادی'
        INTERNAL = 'internal', 'داخلی'
        CONFIDENTIAL = 'confidential', 'محرمانه'
        SECRET = 'secret', 'سری'

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        REGISTERED = 'registered', 'ثبت‌شده'
        IN_REVIEW = 'in_review', 'بررسی مدیر'
        REFERRED = 'referred', 'ارجاع‌شده'
        IN_PROGRESS = 'in_progress', 'در جریان'
        ANSWERED = 'answered', 'پاسخ‌شده'
        CLOSED = 'closed', 'بسته‌شده'
        ARCHIVED = 'archived', 'بایگانی'

    #: پیشوند نمایش شماره به تفکیک نوع (۱۴۰۵/و/۰۰۰۱)
    TYPE_PREFIX = {
        LetterType.INCOMING: 'و',
        LetterType.OUTGOING: 'ص',
        LetterType.INTERNAL: 'د',
    }

    office = models.ForeignKey(
        Office, verbose_name='دبیرخانه', on_delete=models.PROTECT, related_name='letters')
    letter_type = models.CharField('نوع نامه', max_length=10, choices=LetterType.choices)
    jalali_year = models.PositiveSmallIntegerField('سال شمسی', default=0)
    serial = models.PositiveIntegerField('سریال', default=0)

    subject = models.CharField('موضوع', max_length=300)
    summary = models.TextField('خلاصه', blank=True, default='')
    body = models.TextField('متن نامه', blank=True, default='')

    sender_name = models.CharField('فرستنده (شخص)', max_length=150, blank=True, default='')
    sender_org = models.CharField('سازمان فرستنده', max_length=200, blank=True, default='')
    letter_date = jmodels.jDateField('تاریخ نامه', null=True, blank=True)
    received_at = models.DateTimeField('زمان وصول', null=True, blank=True)

    priority = models.CharField(
        'درجه فوریت', max_length=10, choices=Priority.choices, default=Priority.NORMAL)
    confidentiality = models.CharField(
        'سطح محرمانگی', max_length=12, choices=Confidentiality.choices,
        default=Confidentiality.NORMAL)
    status = models.CharField(
        'وضعیت', max_length=12, choices=Status.choices,
        default=Status.REGISTERED, db_index=True)
    deadline = jmodels.jDateField('مهلت پاسخ', null=True, blank=True)

    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='created_letters')

    history = HistoricalRecords()

    class Meta:
        verbose_name = 'نامه'
        verbose_name_plural = 'نامه‌ها'
        ordering = ['-jalali_year', '-serial']
        constraints = [
            models.UniqueConstraint(
                fields=['office', 'letter_type', 'jalali_year', 'serial'],
                condition=models.Q(serial__gt=0),
                name='uq_letter_number'),
        ]
        permissions = [
            ('view_own_letter', 'مشاهده نامه‌های خود'),
            ('view_unit_letter', 'مشاهده نامه‌های واحد و زیرمجموعه'),
            ('view_all_letter', 'مشاهده همه نامه‌ها'),
            ('view_confidential_letter', 'مشاهده نامه‌های محرمانه و سری'),
            ('refer_letter', 'ارجاع نامه'),
            ('answer_letter', 'ثبت پاسخ نامه'),
            ('close_letter', 'بستن و بایگانی نامه'),
            ('manage_secretariat', 'مدیریت دبیرخانه و شماره‌گذاری'),
        ]

    @property
    def number_display(self) -> str:
        """شماره نامه با ارقام فارسی: ۱۴۰۵/و/۰۴۸۲"""
        prefix = self.TYPE_PREFIX.get(self.letter_type, 'د')
        return f'{to_persian_digits(self.jalali_year)}/{prefix}/{to_persian_digits(f"{self.serial:04d}")}'

    def __str__(self) -> str:
        return f'{self.number_display} — {self.subject[:60]}'


class Referral(TimeStampedModel):
    """ارجاع نامه — زنجیره‌ای (والد/فرزند) همسان گردش ارجاع تیکت."""

    class Status(models.TextChoices):
        PENDING = 'pending', 'در انتظار اقدام'
        ACTED = 'acted', 'اقدام‌شده'
        RETURNED = 'returned', 'بازگشت‌داده‌شده'
        CLOSED = 'closed', 'بسته‌شده'

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='referrals')
    parent = models.ForeignKey(
        'self', verbose_name='ارجاع والد', null=True, blank=True,
        on_delete=models.CASCADE, related_name='children')
    from_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ارجاع‌دهنده', null=True,
        on_delete=models.SET_NULL, related_name='sent_referrals')
    to_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='گیرنده ارجاع', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='received_referrals')
    to_department = models.ForeignKey(
        Department, verbose_name='واحد گیرنده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='received_referrals')
    note = models.TextField('دستور/یادداشت', blank=True, default='')
    deadline = jmodels.jDateField('مهلت اقدام', null=True, blank=True)
    status = models.CharField(
        'وضعیت', max_length=10, choices=Status.choices, default=Status.PENDING, db_index=True)
    acted_at = models.DateTimeField('زمان اقدام', null=True, blank=True)

    class Meta:
        verbose_name = 'ارجاع'
        verbose_name_plural = 'ارجاع‌ها'
        ordering = ['created_at']

    def clean(self):
        if self.to_user_id is None and self.to_department_id is None:
            raise ValidationError('ارجاع باید گیرنده (شخص یا واحد) داشته باشد.')

    def __str__(self) -> str:
        target = self.to_user or self.to_department
        return f'ارجاع {self.letter_id} → {target}'


class Copy(TimeStampedModel):
    """رونوشت نامه به شخص یا واحد."""

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='copies')
    to_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='گیرنده رونوشت', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='received_copies')
    to_department = models.ForeignKey(
        Department, verbose_name='واحد گیرنده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='received_copies')
    note = models.TextField('یادداشت', blank=True, default='')
    viewed_at = models.DateTimeField('زمان مشاهده', null=True, blank=True)

    class Meta:
        verbose_name = 'رونوشت'
        verbose_name_plural = 'رونوشت‌ها'
        ordering = ['created_at']

    def clean(self):
        if self.to_user_id is None and self.to_department_id is None:
            raise ValidationError('رونوشت باید گیرنده (شخص یا واحد) داشته باشد.')

    def __str__(self) -> str:
        target = self.to_user or self.to_department
        return f'رونوشت {self.letter_id} → {target}'


class Attachment(TimeStampedModel):
    """پیوست نامه با سیاست فرمت/حجم (تنظیمات SECRETARIAT_*)."""

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='attachments')
    file = models.FileField('فایل', upload_to='secretariat/letters/%Y/%m/')
    original_name = models.CharField('نام اصلی فایل', max_length=255, blank=True, default='')
    size_bytes = models.PositiveBigIntegerField('حجم (بایت)', default=0)
    mime_type = models.CharField('نوع MIME', max_length=120, blank=True, default='')
    uploaded_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='بارگذار', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')

    class Meta:
        verbose_name = 'پیوست'
        verbose_name_plural = 'پیوست‌ها'
        ordering = ['created_at']

    def clean(self):
        if not self.file:
            raise ValidationError({'file': 'فایل پیوست الزامی است.'})
        if self.file and not self.size_bytes:
            self.size_bytes = getattr(self.file, 'size', 0) or 0
        if not self.original_name:
            self.original_name = self.file.name.rsplit('/', 1)[-1]
        extension = self.original_name.rsplit('.', 1)[-1].lower() if '.' in self.original_name else ''
        allowed = [e.lower() for e in settings.SECRETARIAT_ATTACHMENT_ALLOWED_EXTENSIONS]
        if extension not in allowed:
            raise ValidationError({'file': f'فرمت «{extension or "نامعلوم"}» مجاز نیست.'})
        max_bytes = settings.SECRETARIAT_ATTACHMENT_MAX_MB * 1024 * 1024
        if self.size_bytes and self.size_bytes > max_bytes:
            raise ValidationError({'file': f'حجم فایل بیش از {settings.SECRETARIAT_ATTACHMENT_MAX_MB} مگابایت است.'})

    def __str__(self) -> str:
        return self.original_name or f'پیوست {self.pk}'


class LetterEvent(TimeStampedModel):
    """رویدادنامه نامه — همسان ticket_events؛ هر اقدام یک ردیف."""

    class Action(models.TextChoices):
        REGISTERED = 'registered', 'ثبت'
        REFERRED = 'referred', 'ارجاع'
        ANSWERED = 'answered', 'پاسخ'
        COPIED = 'copied', 'رونوشت'
        ATTACHED = 'attached', 'پیوست'
        STATUS_CHANGED = 'status_changed', 'تغییر وضعیت'
        PRINTED = 'printed', 'چاپ'
        CLOSED = 'closed', 'بستن'
        ARCHIVED = 'archived', 'بایگانی'

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='events')
    actor = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کنشگر', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    action = models.CharField('اقدام', max_length=20, choices=Action.choices)
    from_status = models.CharField('از وضعیت', max_length=12, blank=True, default='')
    to_status = models.CharField('به وضعیت', max_length=12, blank=True, default='')
    note = models.TextField('یادداشت', blank=True, default='')

    class Meta:
        verbose_name = 'رویداد نامه'
        verbose_name_plural = 'رویدادهای نامه'
        ordering = ['-created_at']
        indexes = [models.Index(fields=['letter', 'action'], name='idx_letter_action')]

    def __str__(self) -> str:
        return f'{self.letter_id}: {self.action}'
