"""مدل‌های جلسات: جلسه، دعوت‌شدگان، دستور جلسه، صورت‌جلسه با امضا و مصوبات."""
from django.conf import settings
from django.db import models
from django_jalali.db import models as jmodels

from apps.core.models import TimeStampedModel
from apps.secretariat.models import Letter


class Meeting(TimeStampedModel):
    """جلسه — زمان/مکان/دعوت‌شدگان با وضعیت برگزاری."""

    class Status(models.TextChoices):
        SCHEDULED = 'scheduled', 'برنامه‌ریزی‌شده'
        HELD = 'held', 'برگزارشده'
        CANCELLED = 'cancelled', 'لغوشده'

    title = models.CharField('عنوان جلسه', max_length=200)
    jalali_date = jmodels.jDateField('تاریخ شمسی')
    start_time = models.TimeField('ساعت شروع', null=True, blank=True)
    location = models.CharField('مکان', max_length=200, blank=True, default='')
    description = models.TextField('توضیح', blank=True, default='')
    status = models.CharField(
        'وضعیت', max_length=12, choices=Status.choices, default=Status.SCHEDULED)
    organizer = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='برگزارکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='organized_meetings')

    class Meta:
        verbose_name = 'جلسه'
        verbose_name_plural = 'جلسات'
        ordering = ['-jalali_date', '-created_at']
        permissions = [
            ('manage_meetings', 'مدیریت جلسات'),
        ]

    def __str__(self) -> str:
        return self.title


class Attendee(TimeStampedModel):
    """دعوت‌شدگان جلسه با نقش و وضعیت حضور."""

    class Role(models.TextChoices):
        CHAIR = 'chair', 'رئیس جلسه'
        SECRETARY = 'secretary', 'دبیر جلسه'
        MEMBER = 'member', 'عضو'
        GUEST = 'guest', 'مهمان'

    class Attendance(models.TextChoices):
        INVITED = 'invited', 'دعوت‌شده'
        PRESENT = 'present', 'حاضر'
        ABSENT = 'absent', 'غایب'
        EXCUSED = 'excused', 'موجه'

    meeting = models.ForeignKey(
        Meeting, verbose_name='جلسه', on_delete=models.CASCADE, related_name='attendees')
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کاربر', on_delete=models.CASCADE,
        related_name='meeting_attendances')
    role = models.CharField('نقش', max_length=12, choices=Role.choices, default=Role.MEMBER)
    attendance = models.CharField(
        'وضعیت حضور', max_length=12, choices=Attendance.choices, default=Attendance.INVITED)
    note = models.CharField('یادداشت', max_length=200, blank=True, default='')

    class Meta:
        verbose_name = 'دعوت‌شده'
        verbose_name_plural = 'دعوت‌شدگان'
        constraints = [
            models.UniqueConstraint(fields=['meeting', 'user'], name='uq_attendee'),
        ]

    def __str__(self) -> str:
        return f'{self.user} — {self.get_role_display()}'


class AgendaItem(TimeStampedModel):
    """دستور جلسه — ردیف‌های شماره‌دار با ارائه‌کننده."""

    meeting = models.ForeignKey(
        Meeting, verbose_name='جلسه', on_delete=models.CASCADE, related_name='agenda')
    order = models.PositiveSmallIntegerField('ترتیب', default=1)
    title = models.CharField('عنوان', max_length=300)
    description = models.TextField('توضیح', blank=True, default='')
    presenter = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ارائه‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')

    class Meta:
        verbose_name = 'دستور جلسه'
        verbose_name_plural = 'دستورهای جلسه'
        ordering = ['order']

    def __str__(self) -> str:
        return self.title


class Minutes(TimeStampedModel):
    """صورت‌جلسه — متن، امضاها و نهایی‌سازی."""

    meeting = models.OneToOneField(
        Meeting, verbose_name='جلسه', on_delete=models.CASCADE, related_name='minutes')
    body = models.TextField('متن صورت‌جلسه', blank=True, default='')
    is_final = models.BooleanField('نهایی', default=False)
    finalized_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='نهایی‌کننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    finalized_at = models.DateTimeField('زمان نهایی‌سازی', null=True, blank=True)

    class Meta:
        verbose_name = 'صورت‌جلسه'
        verbose_name_plural = 'صورت‌جلسات'

    def __str__(self) -> str:
        return f'صورت‌جلسه {self.meeting.title}'


class MinutesSignature(TimeStampedModel):
    """امضای صورت‌جلسه توسط حاضران."""

    minutes = models.ForeignKey(
        Minutes, verbose_name='صورت‌جلسه', on_delete=models.CASCADE, related_name='signatures')
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='امضاکننده', on_delete=models.CASCADE,
        related_name='+')
    position = models.CharField('سمت', max_length=100, blank=True, default='')

    class Meta:
        verbose_name = 'امضای صورت‌جلسه'
        verbose_name_plural = 'امضاهای صورت‌جلسه'
        constraints = [
            models.UniqueConstraint(fields=['minutes', 'user'], name='uq_minutes_signature'),
        ]

    def __str__(self) -> str:
        return f'{self.user} ← {self.minutes}'


class Resolution(TimeStampedModel):
    """مصوبه — قابل تبدیل به نامه صادره یا وظیفه."""

    class Status(models.TextChoices):
        OPEN = 'open', 'باز'
        CONVERTED = 'converted', 'تبدیل‌شده'
        DONE = 'done', 'انجام‌شده'
        CANCELLED = 'cancelled', 'لغوشده'

    meeting = models.ForeignKey(
        Meeting, verbose_name='جلسه', on_delete=models.CASCADE, related_name='resolutions')
    agenda_item = models.ForeignKey(
        AgendaItem, verbose_name='بند دستور', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    number = models.PositiveSmallIntegerField('شماره مصوبه', default=1)
    title = models.CharField('عنوان مصوبه', max_length=300)
    description = models.TextField('شرح مصوبه', blank=True, default='')
    responsible = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='مسئول اجرا', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='assigned_resolutions')
    due_date = jmodels.jDateField('مهلت اجرا', null=True, blank=True)
    status = models.CharField(
        'وضعیت', max_length=12, choices=Status.choices, default=Status.OPEN)
    converted_letter = models.ForeignKey(
        Letter, verbose_name='نامه صادره', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='source_resolutions')
    converted_task_id = models.PositiveIntegerField('وظیفه مقصد', null=True, blank=True)

    class Meta:
        verbose_name = 'مصوبه'
        verbose_name_plural = 'مصوبات'
        ordering = ['number']
        constraints = [
            models.UniqueConstraint(fields=['meeting', 'number'], name='uq_resolution_number'),
        ]

    def __str__(self) -> str:
        return f'مصوبه {self.number} — {self.title[:60]}'
