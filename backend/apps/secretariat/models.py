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
        MEMO = 'memo', 'یادداشتی'

    class Priority(models.TextChoices):
        NORMAL = 'normal', 'عادی'
        URGENT = 'urgent', 'فوری'
        IMMEDIATE = 'immediate', 'آنی'

    class Confidentiality(models.TextChoices):
        NORMAL = 'normal', 'عادی'
        INTERNAL = 'internal', 'داخلی'
        CONFIDENTIAL = 'confidential', 'محرمانه'
        VERY_CONFIDENTIAL = 'very_confidential', 'خیلی محرمانه'
        SECRET = 'secret', 'سری'

    class DeliveryMethod(models.TextChoices):
        SYSTEM = 'system', 'کارتابل سیستم'
        MANUAL = 'manual', 'دستی'
        COURIER = 'courier', 'پیک'
        POST = 'post', 'پستی'
        FAX = 'fax', 'فاکس'
        EMAIL = 'email', 'رایانامه'

    class Status(models.TextChoices):
        DRAFT = 'draft', 'پیش‌نویس'
        REGISTERED = 'registered', 'ثبت‌شده'
        IN_REVIEW = 'in_review', 'بررسی مدیر'
        REFERRED = 'referred', 'ارجاع‌شده'
        IN_PROGRESS = 'in_progress', 'در جریان'
        ANSWERED = 'answered', 'پاسخ‌شده'
        SIGNED = 'signed', 'امضاشده'
        SENT = 'sent', 'ارسال‌شده'
        CLOSED = 'closed', 'بسته‌شده'
        ARCHIVED = 'archived', 'بایگانی'

    #: پیشوند نمایش شماره به تفکیک نوع (۱۴۰۵/و/۰۰۰۱)
    TYPE_PREFIX = {
        LetterType.INCOMING: 'و',
        LetterType.OUTGOING: 'ص',
        LetterType.INTERNAL: 'د',
        LetterType.MEMO: 'ی',
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
    to_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='گیرنده (شخص)', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='received_letters')
    to_department = models.ForeignKey(
        'organization.Department', verbose_name='گیرنده (واحد)', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='received_letters')
    letter_date = jmodels.jDateField('تاریخ نامه', null=True, blank=True)
    received_at = models.DateTimeField('زمان وصول', null=True, blank=True)

    priority = models.CharField(
        'درجه فوریت', max_length=10, choices=Priority.choices, default=Priority.NORMAL)
    confidentiality = models.CharField(
        'سطح محرمانگی', max_length=20, choices=Confidentiality.choices,
        default=Confidentiality.NORMAL)
    delivery_method = models.CharField(
        'شیوه ارسال', max_length=12, choices=DeliveryMethod.choices,
        default=DeliveryMethod.SYSTEM)
    status = models.CharField(
        'وضعیت', max_length=12, choices=Status.choices,
        default=Status.REGISTERED, db_index=True)
    deadline = jmodels.jDateField('مهلت پاسخ', null=True, blank=True)

    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='created_letters')

    # ── لایه حرفه‌ای (مطابق سامانه‌های مرجع) ──
    case_file = models.ForeignKey(
        'CaseFile', verbose_name='پرونده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='letters')
    correspondent = models.ForeignKey(
        'Correspondent', verbose_name='طرف مکاتبه', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='letters')
    topics = models.ManyToManyField(
        'Topic', verbose_name='موضوعات', blank=True, related_name='letters')
    keywords = models.ManyToManyField(
        'Keyword', verbose_name='کلیدواژه‌ها', blank=True, related_name='letters')
    locked_at = models.DateTimeField('زمان قفل', null=True, blank=True)
    locked_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='قفل‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='locked_letters')
    current_revision = models.PositiveIntegerField('نسخه جاری', default=1)
    copy_kind = models.CharField(
        'نوع نسخه', max_length=10,
        choices=[('original', 'اصل'), ('duplicate', 'المثنی'), ('copy', 'کپی')],
        default='original')
    physical_count = models.PositiveSmallIntegerField('تعداد نسخ فیزیکی', default=1)
    retention_rule = models.ForeignKey(
        'RetentionRule', verbose_name='رده نگهداری', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='letters')

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
            ('approve_letter', 'تأیید و امضای نامه صادره'),
            ('close_letter', 'بستن و بایگانی نامه'),
            ('manage_secretariat', 'مدیریت دبیرخانه و شماره‌گذاری'),
        ]

    @property
    def number_display(self) -> str:
        """شماره نامه با ارقام فارسی — الگوی قابل‌تنظیم یا پیش‌فرض: ۱۴۰۵/و/۰۴۸۲"""
        fmt = get_number_format(self.office_id, self.letter_type)
        if fmt is not None:
            return fmt.format(self)
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
        CANCELLED = 'cancelled', 'بازپس‌گرفته‌شده'

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
    # ── لایه حرفه‌ای ──
    target_kind = models.CharField(
        'نوع مقصد', max_length=10,
        choices=[('user', 'شخص'), ('position', 'جایگاه'), ('department', 'واحد')],
        default='user')
    target_position = models.ForeignKey(
        'organization.Position', verbose_name='جایگاه مقصد', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='referrals')
    return_note = models.TextField('دلیل بازگشت جهت اصلاح', blank=True, default='')
    returned_at = models.DateTimeField('زمان بازگشت', null=True, blank=True)
    sla_due_at = models.DateTimeField('مهلت SLA', null=True, blank=True, db_index=True)
    warned_at = models.DateTimeField('زمان یادآوری', null=True, blank=True)
    overdue_at = models.DateTimeField('زمان عبور از مهلت', null=True, blank=True)

    class Meta:
        verbose_name = 'ارجاع'
        verbose_name_plural = 'ارجاع‌ها'
        ordering = ['created_at']

    def clean(self):
        if (self.to_user_id is None and self.to_department_id is None
                and self.target_position_id is None):
            raise ValidationError('ارجاع باید گیرنده (شخص، جایگاه یا واحد) داشته باشد.')

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
    # ── رونوشت اقدامی/اطلاعی (مطابق دبیرخانه استاندارد) ──
    kind = models.CharField(
        'نوع رونوشت', max_length=10,
        choices=[('info', 'جهت اطلاع'), ('action', 'جهت اقدام')], default='info')
    action_note = models.TextField('شرح اقدام', blank=True, default='')
    done_at = models.DateTimeField('زمان انجام اقدام', null=True, blank=True)
    done_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='انجام‌دهنده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='done_copies')

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
        NOTE = 'note', 'یادداشت'
        CHECKED = 'checked', 'کنترل ثبت'
        APPROVED = 'approved', 'تأیید/امضا'
        REJECTED = 'rejected', 'رد'
        EDITED = 'edited', 'ویرایش'
        LOCKED = 'locked', 'قفل'
        RETURNED = 'returned', 'بازگشت جهت اصلاح'

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


class LetterApproval(TimeStampedModel):
    """گام تأیید/امضای نامه صادره — گردش ساده پیش‌نویس→بازبینی→امضا→ارسال."""

    class Decision(models.TextChoices):
        PENDING = 'pending', 'در انتظار'
        APPROVED = 'approved', 'تأیید/امضا شد'
        REJECTED = 'rejected', 'رد شد'

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='approvals')
    step = models.PositiveSmallIntegerField('گام', default=1)
    approver = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تأییدکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='letter_approvals')
    approver_label = models.CharField('سمت امضا', max_length=150, blank=True, default='')
    decision = models.CharField(
        'نتیجه', max_length=10, choices=Decision.choices, default=Decision.PENDING)
    note = models.TextField('یادداشت', blank=True, default='')
    decided_at = models.DateTimeField('زمان تصمیم', null=True, blank=True)
    signature_image = models.FileField(
        'تصویر امضا', upload_to='secretariat/signatures/', null=True, blank=True)

    class Meta:
        verbose_name = 'تأیید نامه'
        verbose_name_plural = 'تأییدهای نامه'
        ordering = ['step', 'created_at']

    def __str__(self) -> str:
        return f'{self.letter_id} — گام {self.step}: {self.decision}'


class LetterRelation(TimeStampedModel):
    """نامه مرتبط — پیرو، عطف یا بازگشت (الگوی دبیرخانه‌های حرفه‌ای)."""

    class Kind(models.TextChoices):
        FOLLOW = 'follow', 'پیرو'
        REF = 'ref', 'عطف'
        RETURN = 'return', 'بازگشت'
        ANSWER = 'answer', 'پاسخ'

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE,
        related_name='outgoing_relations')
    related = models.ForeignKey(
        Letter, verbose_name='نامه مرتبط', on_delete=models.CASCADE,
        related_name='incoming_relations')
    kind = models.CharField('نوع رابطه', max_length=8, choices=Kind.choices)

    class Meta:
        verbose_name = 'نامه مرتبط'
        verbose_name_plural = 'نامه‌های مرتبط'
        unique_together = [('letter', 'related', 'kind')]

    def __str__(self) -> str:
        return f'{self.get_kind_display()} → {self.related.number_display}'


class LetterNote(TimeStampedModel):
    """هامش/یادداشت روی نامه — دیدگاه‌ها و پیگیری‌های غیررسمی."""

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='notes')
    author = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='نویسنده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    text = models.TextField('متن یادداشت')

    class Meta:
        verbose_name = 'یادداشت نامه'
        verbose_name_plural = 'یادداشت‌های نامه'
        ordering = ['created_at']

    def __str__(self) -> str:
        return f'یادداشت روی {self.letter_id}'


class LetterTemplate(TimeStampedModel):
    """الگوی نامه — متن‌های آماده پرکاربرد برای ثبت سریع."""

    title = models.CharField('عنوان الگو', max_length=200)
    letter_type = models.CharField(
        'نوع نامه', max_length=10, choices=Letter.LetterType.choices,
        default=Letter.LetterType.INTERNAL)
    subject_pattern = models.CharField(
        'الگوی موضوع', max_length=300, blank=True, default='')
    body = models.TextField('متن الگو')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ایجادکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')

    class Meta:
        verbose_name = 'الگوی نامه'
        verbose_name_plural = 'الگوهای نامه'
        ordering = ['title']

    def __str__(self) -> str:
        return self.title


# ════════════════════════════════════════════════════════════════
# لایه حرفه‌ای دبیرخانه — مطابق رفتار سامانه‌های مرجع (دیدگاه/راهکاران/کارما)
# ════════════════════════════════════════════════════════════════

class Correspondent(TimeStampedModel):
    """طرف مکاتبه: شخص حقیقی یا حقوقی — با سابقه خودکار مکاتبات."""

    class Kind(models.TextChoices):
        PERSON = 'person', 'شخص حقیقی'
        ORG = 'org', 'شخص حقوقی / سازمان'

    kind = models.CharField('نوع', max_length=10, choices=Kind.choices, default=Kind.ORG)
    title = models.CharField('نام/عنوان', max_length=200)
    national_id = models.CharField('شناسه ملی/کد ملی', max_length=20, blank=True, default='')
    phone = models.CharField('تلفن', max_length=30, blank=True, default='')
    address = models.TextField('نشانی', blank=True, default='')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'طرف مکاتبه'
        verbose_name_plural = 'طرف‌های مکاتبه'
        ordering = ['title']

    def __str__(self) -> str:
        return self.title


class Topic(TimeStampedModel):
    """درخت موضوعات نامه — برای دفتر اندکس و طبقه‌بندی."""

    parent = models.ForeignKey(
        'self', verbose_name='موضوع والد', null=True, blank=True,
        on_delete=models.CASCADE, related_name='children')
    title = models.CharField('عنوان موضوع', max_length=150)
    code = models.CharField('کد', max_length=20, blank=True, default='')

    class Meta:
        verbose_name = 'موضوع'
        verbose_name_plural = 'موضوعات'
        ordering = ['code', 'title']

    def __str__(self) -> str:
        return self.title


class Keyword(TimeStampedModel):
    """کلیدواژه/برچسب نامه."""

    title = models.CharField('عنوان', max_length=100, unique=True)

    class Meta:
        verbose_name = 'کلیدواژه'
        verbose_name_plural = 'کلیدواژه‌ها'
        ordering = ['title']

    def __str__(self) -> str:
        return self.title


class CaseFile(TimeStampedModel):
    """پرونده (کلاسور) مکاتباتی — واحد کار واقعی دبیرخانه: چند نامه در یک ماجرا."""

    class Status(models.TextChoices):
        OPEN = 'open', 'باز'
        CLOSED = 'closed', 'بسته'
        ARCHIVED = 'archived', 'بایگانی‌شده'

    number = models.CharField('شماره پرونده', max_length=40, unique=True)
    title = models.CharField('عنوان', max_length=300)
    description = models.TextField('شرح', blank=True, default='')
    topic = models.ForeignKey(
        Topic, verbose_name='موضوع', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='case_files')
    owner_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='مسئول پرونده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='owned_case_files')
    owner_position = models.ForeignKey(
        'organization.Position', verbose_name='جایگاه مسئول', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='case_files')
    status = models.CharField(
        'وضعیت', max_length=10, choices=Status.choices, default=Status.OPEN, db_index=True)
    confidentiality = models.CharField(
        'سطح محرمانگی', max_length=20, choices=Letter.Confidentiality.choices,
        default=Letter.Confidentiality.NORMAL)
    opened_at = jmodels.jDateField('تاریخ تشکیل', null=True, blank=True)
    closed_at = jmodels.jDateField('تاریخ بستن', null=True, blank=True)
    retention_rule = models.ForeignKey(
        'RetentionRule', verbose_name='رده نگهداری', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='case_files')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ایجادکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='created_case_files')

    class Meta:
        verbose_name = 'پرونده'
        verbose_name_plural = 'پرونده‌ها'
        ordering = ['-created_at']

    def __str__(self) -> str:
        return f'{self.number} — {self.title[:50]}'

    @property
    def letter_count(self) -> int:
        return self.letters.count()


class CaseFileEvent(TimeStampedModel):
    """رویداد پرونده (باز/بستن/بازگشایی/افزودن نامه)."""

    ACTION_OPEN = 'open'
    ACTION_CLOSE = 'close'
    ACTION_REOPEN = 'reopen'
    ACTION_ADD_LETTER = 'add_letter'
    ACTION_CHOICES = [
        (ACTION_OPEN, 'تشکیل'), (ACTION_CLOSE, 'بستن'),
        (ACTION_REOPEN, 'بازگشایی'), (ACTION_ADD_LETTER, 'افزودن نامه'),
    ]
    case_file = models.ForeignKey(
        CaseFile, verbose_name='پرونده', on_delete=models.CASCADE, related_name='events')
    actor = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', null=True, blank=True,
        on_delete=models.SET_NULL)
    action = models.CharField('اقدام', max_length=20, choices=ACTION_CHOICES)
    note = models.TextField('یادداشت', blank=True, default='')

    class Meta:
        verbose_name = 'رویداد پرونده'
        verbose_name_plural = 'رویدادهای پرونده'
        ordering = ['created_at']


class RegisterEntry(TimeStampedModel):
    """ردیف دفتر اندیکاتور — snapshot تغییرناپذیر ثبت رسمی (ستون‌های دفتر ۱۲ ستونه)."""

    office = models.ForeignKey(
        Office, verbose_name='دبیرخانه', on_delete=models.CASCADE, related_name='register_entries')
    jalali_year = models.PositiveSmallIntegerField('سال شمسی')
    row_number = models.PositiveIntegerField('شماره ردیف')
    letter = models.OneToOneField(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='register_entry')
    summary = models.CharField('شرح نامه', max_length=300, blank=True, default='')
    attachment_count = models.PositiveSmallIntegerField('تعداد ضمائم', default=0)
    incoming_ref = models.CharField(
        'شماره/تاریخ نامه رسیده', max_length=120, blank=True, default='')
    referred_to = models.CharField('ارجاع به', max_length=300, blank=True, default='')
    expected_return_date = jmodels.jDateField('تاریخ تقریبی برگشت', null=True, blank=True)
    issue_date = jmodels.jDateField('تاریخ صدور', null=True, blank=True)
    copy_to = models.CharField('رونوشت به', max_length=300, blank=True, default='')
    prev_row = models.ForeignKey(
        'self', verbose_name='ردیف قبل', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='next_rows')
    file_number = models.CharField('شماره پرونده', max_length=40, blank=True, default='')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL)

    class Meta:
        verbose_name = 'ردیف دفتر اندیکاتور'
        verbose_name_plural = 'دفتر اندیکاتور'
        ordering = ['office', 'jalali_year', 'row_number']
        constraints = [
            models.UniqueConstraint(
                fields=['office', 'jalali_year', 'row_number'],
                name='uq_register_row'),
        ]

    def __str__(self) -> str:
        return f'{self.jalali_year}/{self.row_number} — {self.summary[:40]}'


class RegisterCheck(TimeStampedModel):
    """کنترل پیش از ثبت (چک‌لیست مسئول دفتر) — گیت رسمی قبل از شماره‌گذاری نهایی."""

    class Result(models.TextChoices):
        PASS = 'pass', 'تأیید و ثبت'
        FAIL = 'fail', 'برگشت جهت اصلاح'

    letter = models.OneToOneField(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='register_check')
    checked_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کنترل‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL)
    checked_at = models.DateTimeField('زمان کنترل', null=True, blank=True)
    has_signature = models.BooleanField('امضا/تأیید مدیر دارد', default=False)
    copies_count_ok = models.BooleanField('تعداد نسخ/رونوشت‌ها درست است', default=False)
    attachments_ok = models.BooleanField('ضمائم با تعداد اعلام‌شده مطابقت دارد', default=False)
    dates_ok = models.BooleanField('شماره و تاریخ درج شده', default=False)
    addressee_ok = models.BooleanField('عنوان گیرنده/رونوشت صحیح است', default=False)
    note = models.TextField('یادداشت', blank=True, default='')
    result = models.CharField('نتیجه', max_length=6, choices=Result.choices, default=Result.FAIL)

    class Meta:
        verbose_name = 'کنترل ثبت'
        verbose_name_plural = 'کنترل‌های ثبت'

    def __str__(self) -> str:
        return f'کنترل نامه {self.letter_id}: {self.get_result_display()}'


class LetterRevision(TimeStampedModel):
    """نسخه متن نامه — هر تغییر مجاز یک snapshot؛ مبنای امضای «نسخه مشخص»."""

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='revisions')
    revision_no = models.PositiveIntegerField('شماره نسخه')
    subject = models.CharField('موضوع', max_length=300)
    body = models.TextField('متن')
    changed_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ویرایش‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL)
    reason = models.CharField('دلیل', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'نسخه نامه'
        verbose_name_plural = 'نسخه‌های نامه'
        ordering = ['-revision_no']
        constraints = [
            models.UniqueConstraint(
                fields=['letter', 'revision_no'], name='uq_letter_revision'),
        ]

    def __str__(self) -> str:
        return f'نامه {self.letter_id} — نسخه {self.revision_no}'


class SignatureRequest(TimeStampedModel):
    """درخواست امضای چندنفره — سری یا موازی، روی یک نسخه مشخص."""

    class Mode(models.TextChoices):
        SEQUENTIAL = 'sequential', 'سری (نوبتی)'
        PARALLEL = 'parallel', 'موازی (هم‌زمان)'

    class Status(models.TextChoices):
        PENDING = 'pending', 'در جریان'
        COMPLETED = 'completed', 'تکمیل‌شده'
        REJECTED = 'rejected', 'ردشده'

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='signature_requests')
    mode = models.CharField('حالت', max_length=12, choices=Mode.choices, default=Mode.SEQUENTIAL)
    status = models.CharField(
        'وضعیت', max_length=12, choices=Status.choices, default=Status.PENDING, db_index=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ایجادکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='created_signature_requests')

    class Meta:
        verbose_name = 'درخواست امضا'
        verbose_name_plural = 'درخواست‌های امضا'
        ordering = ['-created_at']

    @property
    def approved_count(self) -> int:
        return self.steps.filter(status=SignatureStep.Status.APPROVED).count()

    @property
    def total_count(self) -> int:
        return self.steps.count()


class SignatureStep(TimeStampedModel):
    """گام امضا — هر امضاکننده روی «نسخه امضاشده» نظر می‌دهد."""

    class Status(models.TextChoices):
        PENDING = 'pending', 'در انتظار'
        APPROVED = 'approved', 'تأییدشده'
        REJECTED = 'rejected', 'ردشده'
        CHANGES = 'changes', 'درخواست اصلاح'

    request = models.ForeignKey(
        SignatureRequest, verbose_name='درخواست', on_delete=models.CASCADE, related_name='steps')
    order = models.PositiveSmallIntegerField('ترتیب', default=1)
    signer_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='امضاکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='signature_steps')
    signer_position = models.ForeignKey(
        'organization.Position', verbose_name='جایگاه امضاکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='signature_steps')
    signer_label = models.CharField('سمت امضا', max_length=150, blank=True, default='')
    status = models.CharField(
        'وضعیت', max_length=10, choices=Status.choices, default=Status.PENDING)
    comment = models.TextField('نظر', blank=True, default='')
    signed_at = models.DateTimeField('زمان امضا', null=True, blank=True)
    signed_revision = models.ForeignKey(
        LetterRevision, verbose_name='نسخه امضاشده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='signed_steps')
    proxy_user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='امضا از طرف (تفویض)', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='proxy_signed_steps')

    class Meta:
        verbose_name = 'گام امضا'
        verbose_name_plural = 'گام‌های امضا'
        ordering = ['request', 'order']

    def __str__(self) -> str:
        who = self.signer_user or self.signer_label or self.signer_position
        return f'امضا: {who} — {self.get_status_display()}'


class ReadReceipt(TimeStampedModel):
    """رسید «دریافت شد» — چه کسی، کِی، از کدام مسیر دید."""

    class Source(models.TextChoices):
        REFERRAL = 'referral', 'ارجاع'
        COPY = 'copy', 'رونوشت'
        DIRECT = 'direct', 'مستقیم'
        POSITION = 'position', 'جایگاه'

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='read_receipts')
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', on_delete=models.CASCADE,
        related_name='letter_read_receipts')
    source = models.CharField('مبدأ', max_length=12, choices=Source.choices, default=Source.DIRECT)
    seen_at = models.DateTimeField('زمان رؤیت', auto_now_add=True)

    class Meta:
        verbose_name = 'رسید رؤیت'
        verbose_name_plural = 'رسیدهای رؤیت'
        ordering = ['-seen_at']
        constraints = [
            models.UniqueConstraint(
                fields=['letter', 'user', 'source'], name='uq_read_receipt'),
        ]


class SLARule(TimeStampedModel):
    """قاعده مهلت اقدام — بر اساس نوع/اولویت نامه (ساعت کاری)."""

    name = models.CharField('نام قاعده', max_length=150)
    letter_type = models.CharField(
        'نوع نامه', max_length=10, choices=Letter.LetterType.choices, blank=True, default='')
    priority = models.CharField(
        'اولویت', max_length=10, choices=Letter.Priority.choices, blank=True, default='')
    action_hours = models.PositiveIntegerField('مهلت اقدام (ساعت)')
    warn_hours_before = models.PositiveIntegerField('یادآوری قبل از موعد (ساعت)', default=24)
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'قاعده SLA'
        verbose_name_plural = 'قاعده‌های SLA'
        ordering = ['letter_type', 'priority']

    def __str__(self) -> str:
        return f'{self.name} ({self.action_hours} ساعت)'


class NumberReservation(TimeStampedModel):
    """رزرو شماره — «شماره بگیر، بعداً صادر کن» یا شماره آزاد."""

    office = models.ForeignKey(
        Office, verbose_name='دبیرخانه', on_delete=models.CASCADE, related_name='number_reservations')
    letter_type = models.CharField('نوع نامه', max_length=10, choices=Letter.LetterType.choices)
    jalali_year = models.PositiveSmallIntegerField('سال شمسی')
    serial = models.PositiveIntegerField('شماره رزروشده')
    reserved_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='رزروکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='number_reservations')
    note = models.CharField('یادداشت', max_length=200, blank=True, default='')
    used_by_letter = models.ForeignKey(
        Letter, verbose_name='نامه مصرف‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='number_reservations')

    class Meta:
        verbose_name = 'رزرو شماره'
        verbose_name_plural = 'رزروهای شماره'
        ordering = ['-created_at']
        constraints = [
            models.UniqueConstraint(
                fields=['office', 'letter_type', 'jalali_year', 'serial'],
                name='uq_number_reservation'),
        ]


class Paraph(TimeStampedModel):
    """پاراف — «دیده شد» کوتاه روی نامه، جدا از هامش."""

    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='paraphs')
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='paraphs')
    text = models.TextField('متن پاراف')

    class Meta:
        verbose_name = 'پاراف'
        verbose_name_plural = 'پاراف‌ها'
        ordering = ['created_at']


class FrequentPhrase(TimeStampedModel):
    """موضوع/شرح پرتکرار — برای سرعت بخشیدن به ثبت."""

    class Scope(models.TextChoices):
        SUBJECT = 'subject', 'موضوع'
        BODY = 'body', 'شرح/متن'
        NOTE = 'note', 'دستور ارجاع'

    scope = models.CharField('نوع', max_length=10, choices=Scope.choices, default=Scope.SUBJECT)
    title = models.CharField('عنوان', max_length=200)
    text = models.TextField('متن')

    class Meta:
        verbose_name = 'عبارت پرتکرار'
        verbose_name_plural = 'عبارت‌های پرتکرار'
        ordering = ['scope', 'title']

    def __str__(self) -> str:
        return self.title


# ════════════════════════════════════════════════════════════════
# تکمیل دبیرخانه — بستن همه ردیف‌های باقی‌مانده فهرست مرجع
# ════════════════════════════════════════════════════════════════

class NumberFormat(TimeStampedModel):
    """الگوی قابل‌تنظیم شماره نامه — مثل {year}/{prefix}/{serial:04} به تفکیک نوع/دبیرخانه."""

    office = models.ForeignKey(
        Office, verbose_name='دبیرخانه', on_delete=models.CASCADE,
        related_name='number_formats', null=True, blank=True)
    letter_type = models.CharField('نوع نامه', max_length=10, choices=Letter.LetterType.choices)
    pattern = models.CharField(
        'الگوی شماره', max_length=60, default='{year}/{prefix}/{serial:04}',
        help_text='متغیرها: {year} سال، {prefix} پیشوند نوع، {serial} سریال')
    is_default = models.BooleanField('پیش‌فرض', default=True)

    class Meta:
        verbose_name = 'الگوی شماره'
        verbose_name_plural = 'الگوهای شماره'
        ordering = ['letter_type']

    def save(self, *args, **kwargs):
        super().save(*args, **kwargs)
        get_number_format.cache_clear()

    def delete(self, *args, **kwargs):
        super().delete(*args, **kwargs)
        get_number_format.cache_clear()

    def format(self, letter) -> str:
        from apps.core.persian import to_persian_digits
        raw = self.pattern.format(
            year=letter.jalali_year,
            prefix=Letter.TYPE_PREFIX.get(letter.letter_type, 'د'),
            serial=letter.serial)
        return to_persian_digits(raw)


from functools import lru_cache  # noqa: E402


@lru_cache(maxsize=256)
def get_number_format(office_id, letter_type):
    """الگوی شماره با کش — جلوگیری از N+1 در لیست‌های نامه."""
    return (NumberFormat.objects.filter(
        office_id=office_id, letter_type=letter_type).first()
        or NumberFormat.objects.filter(
            office__isnull=True, letter_type=letter_type).first())


class FaxLog(TimeStampedModel):
    """دورنویس — دفتر فاکس‌های وارده/صادره (مطابق ماژول دورنویس راهکاران)."""

    class Direction(models.TextChoices):
        IN = 'in', 'دریافتی'
        OUT = 'out', 'ارسالی'

    class Status(models.TextChoices):
        OK = 'ok', 'موفق'
        FAILED = 'failed', 'ناموفق'
        PENDING = 'pending', 'در انتظار'

    direction = models.CharField('جهت', max_length=5, choices=Direction.choices)
    fax_number = models.CharField('شماره فاکس', max_length=40)
    correspondent = models.CharField('طرف مکاتبه', max_length=200, blank=True, default='')
    sent_at = models.DateTimeField('زمان', null=True, blank=True)
    pages = models.PositiveSmallIntegerField('تعداد صفحه', default=1)
    status = models.CharField(
        'وضعیت', max_length=10, choices=Status.choices, default=Status.OK)
    note = models.TextField('یادداشت', blank=True, default='')
    letter = models.ForeignKey(
        Letter, verbose_name='نامه مرتبط', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='fax_logs')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ثبت‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL)

    class Meta:
        verbose_name = 'رکورد فاکس (دورنویس)'
        verbose_name_plural = 'دفتر فاکس (دورنویس)'
        ordering = ['-sent_at', '-created_at']

    def __str__(self) -> str:
        return f'فاکس {self.get_direction_display()} — {self.fax_number}'


class PersonalArchive(TimeStampedModel):
    """بایگانی شخصی — نگهداری یک نسخه از نامه‌های ارجاع‌شده در بایگانی کاربر."""

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', on_delete=models.CASCADE,
        related_name='personal_archive')
    letter = models.ForeignKey(
        Letter, verbose_name='نامه', on_delete=models.CASCADE, related_name='personal_archives')
    note = models.CharField('یادداشت', max_length=300, blank=True, default='')

    class Meta:
        verbose_name = 'بایگانی شخصی'
        verbose_name_plural = 'بایگانی‌های شخصی'
        ordering = ['-created_at']
        constraints = [
            models.UniqueConstraint(fields=['user', 'letter'], name='uq_personal_archive'),
        ]


class RetentionRule(TimeStampedModel):
    """رده نگهداری اسناد (مطابق جداول عمر مفید سازمان اسناد ملی)."""

    class Action(models.TextChoices):
        KEEP = 'keep', 'نگهداری در بایگانی جاری'
        TEMPORARY = 'temporary', 'انتقال به بایگانی موقت/راکد'
        PERMANENT = 'permanent', 'انتقال دائم به آرشیو'
        DESTROY = 'destroy', 'امحاء (با مجوز)'

    name = models.CharField('نام رده', max_length=150)
    years = models.PositiveIntegerField('مدت نگهداری (سال)')
    action = models.CharField('اقدام پس از مدت', max_length=12, choices=Action.choices)
    description = models.TextField('توضیح', blank=True, default='')

    class Meta:
        verbose_name = 'رده نگهداری'
        verbose_name_plural = 'رده‌های نگهداری'
        ordering = ['years']

    def __str__(self) -> str:
        return f'{self.name} ({self.years} سال — {self.get_action_display()})'
