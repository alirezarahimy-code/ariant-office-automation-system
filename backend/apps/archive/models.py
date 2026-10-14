"""مدل‌های بایگانی الکترونیک: زونکن، پوشه موضوعی، سند بایگانی و امحا."""
from django.conf import settings
from django.contrib.contenttypes.fields import GenericForeignKey
from django.contrib.contenttypes.models import ContentType
from django.db import models

from apps.core.models import TimeStampedModel


class ArchiveBox(TimeStampedModel):
    """زونکن — بالاترین سطح دسته‌بندی بایگانی."""

    code = models.CharField('کد زونکن', max_length=32, unique=True)
    title = models.CharField('عنوان', max_length=120)
    description = models.TextField('توضیح', blank=True, default='')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'زونکن'
        verbose_name_plural = 'زونکن‌ها'
        ordering = ['code']
        permissions = [
            ('view_archive', 'مشاهده بایگانی'),
            ('manage_archive', 'مدیریت بایگانی'),
            ('approve_disposal', 'تأیید درخواست امحا'),
        ]

    def __str__(self) -> str:
        return f'{self.code} — {self.title}'


class ArchiveFolder(TimeStampedModel):
    """پوشه/پرونده موضوعی با کد بایگانی یکتا و دوره نگهداری."""

    class SubjectKind(models.TextChoices):
        GENERAL = 'general', 'عمومی'
        FINANCIAL = 'financial', 'مالی'
        ADMIN = 'admin', 'اداری'
        LEGAL = 'legal', 'حقوقی'
        TECHNICAL = 'technical', 'فنی'

    box = models.ForeignKey(
        ArchiveBox, verbose_name='زونکن', on_delete=models.PROTECT, related_name='folders')
    title = models.CharField('موضوع پوشه', max_length=200)
    subject_kind = models.CharField(
        'رده موضوعی', max_length=12, choices=SubjectKind.choices,
        default=SubjectKind.GENERAL)
    code = models.CharField('کد بایگانی', max_length=64, unique=True)
    retention_years = models.PositiveSmallIntegerField('دوره نگهداری (سال)', default=5)
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'پوشه بایگانی'
        verbose_name_plural = 'پوشه‌های بایگانی'
        ordering = ['code']
        constraints = [
            models.UniqueConstraint(fields=['box', 'title'], name='uq_folder_box_title'),
        ]

    def __str__(self) -> str:
        return f'{self.code} — {self.title}'


class ArchiveItem(TimeStampedModel):
    """سند بایگانی‌شده — GenericFK: امروز نامه، فردا هر سند دیگر پلتفرم."""

    folder = models.ForeignKey(
        ArchiveFolder, verbose_name='پوشه', on_delete=models.PROTECT, related_name='items')
    content_type = models.ForeignKey(
        ContentType, verbose_name='نوع سند', on_delete=models.CASCADE, related_name='+')
    object_id = models.PositiveIntegerField('شناسه سند')
    content_object = GenericForeignKey('content_type', 'object_id')
    title = models.CharField('عنوان سند', max_length=300)
    reference = models.CharField('شماره/ارجاع', max_length=64, blank=True, default='')
    archived_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='بایگانی‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    note = models.TextField('یادداشت', blank=True, default='')

    class Meta:
        verbose_name = 'سند بایگانی'
        verbose_name_plural = 'اسناد بایگانی'
        ordering = ['-created_at']
        constraints = [
            models.UniqueConstraint(
                fields=['content_type', 'object_id', 'folder'], name='uq_archive_item_doc'),
        ]

    def __str__(self) -> str:
        return f'{self.folder.code} ← {self.title[:60]}'


class DisposalRequest(TimeStampedModel):
    """درخواست امحای پوشه — با گردش تأیید و اجرای نهایی."""

    class Status(models.TextChoices):
        PENDING = 'pending', 'در انتظار تأیید'
        APPROVED = 'approved', 'تأییدشده'
        REJECTED = 'rejected', 'ردشده'
        EXECUTED = 'executed', 'امحاشده'

    folder = models.ForeignKey(
        ArchiveFolder, verbose_name='پوشه', on_delete=models.PROTECT, related_name='disposals')
    reason = models.TextField('دلیل امحا')
    status = models.CharField(
        'وضعیت', max_length=12, choices=Status.choices, default=Status.PENDING)
    requested_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='درخواست‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    decided_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='تصمیم‌گیرنده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    decided_at = models.DateTimeField('زمان تصمیم', null=True, blank=True)
    decision_note = models.TextField('یادداشت تصمیم', blank=True, default='')
    executed_at = models.DateTimeField('زمان امحا', null=True, blank=True)

    class Meta:
        verbose_name = 'درخواست امحا'
        verbose_name_plural = 'درخواست‌های امحا'
        ordering = ['-created_at']

    def __str__(self) -> str:
        return f'امحای {self.folder.code} — {self.get_status_display()}'
