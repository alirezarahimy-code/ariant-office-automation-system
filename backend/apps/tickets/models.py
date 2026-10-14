"""مدل‌های زیرسامانه بومی تیکت‌ها — پایان‌باز و هم‌واژه با سامانه PHP (docs/14)."""
from django.conf import settings
from django.db import models

from apps.core.models import TimeStampedModel


class Ticket(TimeStampedModel):
    """تیکت بومی؛ در دوران گذار با external_ref به آینه PHP گره می‌خورد."""

    class Status(models.TextChoices):
        OPEN = 'open', 'باز'
        IN_PROGRESS = 'in_progress', 'در جریان'
        WAITING_USER = 'waiting_user', 'در انتظار کاربر'
        RESOLVED = 'resolved', 'حل‌شده'
        CLOSED = 'closed', 'بسته'

    class Priority(models.TextChoices):
        LOW = 'low', 'کم'
        NORMAL = 'normal', 'عادی'
        HIGH = 'high', 'زیاد'
        URGENT = 'urgent', 'فوری'

    #: نگاشت وضعیت‌های سامانه PHP به واژگان بومی
    EXTERNAL_STATUS_MAP = {
        'new': Status.OPEN, 'manager_review': Status.OPEN, 'reopened': Status.OPEN,
        'assigned': Status.IN_PROGRESS, 'in_progress': Status.IN_PROGRESS,
        'pending': Status.IN_PROGRESS, 'waiting_user': Status.WAITING_USER,
        'resolved': Status.RESOLVED, 'closed': Status.CLOSED,
    }

    subject = models.CharField('موضوع', max_length=300)
    body = models.TextField('شرح درخواست', blank=True, default='')
    status = models.CharField(
        'وضعیت', max_length=14, choices=Status.choices, default=Status.OPEN, db_index=True)
    priority = models.CharField(
        'اولویت', max_length=8, choices=Priority.choices, default=Priority.NORMAL)
    service_group = models.CharField('حوزه خدمت', max_length=40, blank=True, default='')
    requester = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='درخواست‌دهنده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='requested_tickets')
    assignee = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کارشناس مسئول', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='assigned_tickets')
    external_ref = models.CharField(
        'ارجاع بیرونی', max_length=80, blank=True, default='', db_index=True,
        help_text='شناسه یکتای تیکت آینه‌شده — «سامانه:شناسه» مانند php-ticketing:IT-1042')

    class Meta:
        verbose_name = 'تیکت'
        verbose_name_plural = 'تیکت‌ها'
        ordering = ['-created_at']
        constraints = [
            models.UniqueConstraint(
                fields=['external_ref'],
                condition=~models.Q(external_ref=''),
                name='uq_ticket_external_ref'),
        ]
        permissions = [
            ('view_all_ticket', 'مشاهده همه تیکت‌ها'),
            ('assign_ticket', 'انتساب و رسیدگی به تیکت‌ها'),
        ]

    def __str__(self) -> str:
        return f'{self.subject[:50]} ({self.get_status_display()})'

    @property
    def is_open(self) -> bool:
        return self.status in (Ticket.Status.OPEN, Ticket.Status.IN_PROGRESS,
                               Ticket.Status.WAITING_USER)


class TicketMessage(TimeStampedModel):
    """گفت‌وگوی درخواست‌دهنده و کارشناس روی تیکت."""

    ticket = models.ForeignKey(
        Ticket, verbose_name='تیکت', on_delete=models.CASCADE, related_name='messages')
    author = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='نویسنده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    body = models.TextField('متن پیام')

    class Meta:
        verbose_name = 'پیام تیکت'
        verbose_name_plural = 'پیام‌های تیکت'
        ordering = ['created_at']

    def __str__(self) -> str:
        return f'{self.ticket_id}: {self.body[:40]}'


class TicketEvent(TimeStampedModel):
    """رویدادنامه تیکت — ایجاد، انتساب، تغییر وضعیت."""

    class Action(models.TextChoices):
        CREATED = 'created', 'ایجاد'
        ASSIGNED = 'assigned', 'انتساب'
        STATUS = 'status', 'تغییر وضعیت'
        MESSAGE = 'message', 'پیام'
        IMPORTED = 'imported', 'ورود از آینه'

    ticket = models.ForeignKey(
        Ticket, verbose_name='تیکت', on_delete=models.CASCADE, related_name='events')
    actor = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کنشگر', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    action = models.CharField('اقدام', max_length=10, choices=Action.choices)
    note = models.TextField('یادداشت', blank=True, default='')

    class Meta:
        verbose_name = 'رویداد تیکت'
        verbose_name_plural = 'رویدادهای تیکت'
        ordering = ['-created_at']

    def __str__(self) -> str:
        return f'{self.get_action_display()} — {self.ticket_id}'
