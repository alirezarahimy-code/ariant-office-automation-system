"""مدل‌های وظایف: وظیفه با مسئول/همکاران/مهلت/پیشرفت و رویدادنامه."""
from django.conf import settings
from django.db import models
from django_jalali.db import models as jmodels

from apps.core.models import TimeStampedModel


class Task(TimeStampedModel):
    """وظیفه — قابل انتساب از مصوبه جلسه یا نامه."""

    class Status(models.TextChoices):
        OPEN = 'open', 'باز'
        IN_PROGRESS = 'in_progress', 'در جریان'
        DONE = 'done', 'انجام‌شده'
        CANCELLED = 'cancelled', 'لغوشده'

    class Priority(models.TextChoices):
        NORMAL = 'normal', 'عادی'
        URGENT = 'urgent', 'فوری'
        IMMEDIATE = 'immediate', 'آنی'

    title = models.CharField('عنوان', max_length=300)
    description = models.TextField('شرح', blank=True, default='')
    responsible = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='مسئول', on_delete=models.PROTECT,
        related_name='assigned_tasks')
    collaborators = models.ManyToManyField(
        settings.AUTH_USER_MODEL, verbose_name='همکاران', blank=True,
        related_name='collaborated_tasks')
    due_date = jmodels.jDateField('مهلت', null=True, blank=True)
    priority = models.CharField(
        'فوریت', max_length=10, choices=Priority.choices, default=Priority.NORMAL)
    status = models.CharField(
        'وضعیت', max_length=12, choices=Status.choices, default=Status.OPEN, db_index=True)
    progress = models.PositiveSmallIntegerField('پیشرفت (٪)', default=0)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='ایجادکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='created_tasks')
    source_resolution = models.ForeignKey(
        'meetings.Resolution', verbose_name='مصوبه مبدأ', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='tasks')
    source_letter = models.ForeignKey(
        'secretariat.Letter', verbose_name='نامه مبدأ', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='tasks')

    class Meta:
        verbose_name = 'وظیفه'
        verbose_name_plural = 'وظایف'
        ordering = ['-created_at']
        permissions = [
            ('manage_tasks', 'مدیریت وظایف دیگران'),
        ]

    def __str__(self) -> str:
        return self.title

    @property
    def is_overdue(self) -> bool:
        import jdatetime
        return (self.due_date is not None
                and self.status in (Task.Status.OPEN, Task.Status.IN_PROGRESS)
                and self.due_date < jdatetime.date.today())


class TaskEvent(TimeStampedModel):
    """رویدادنامه وظیفه — ایجاد، پیشرفت، وضعیت و گفت‌وگو."""

    class Action(models.TextChoices):
        CREATED = 'created', 'ایجاد'
        PROGRESS = 'progress', 'پیشرفت'
        STATUS = 'status', 'تغییر وضعیت'
        COMMENT = 'comment', 'گفت‌وگو'

    task = models.ForeignKey(
        Task, verbose_name='وظیفه', on_delete=models.CASCADE, related_name='events')
    actor = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کنشگر', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    action = models.CharField('اقدام', max_length=12, choices=Action.choices)
    note = models.TextField('یادداشت', blank=True, default='')

    class Meta:
        verbose_name = 'رویداد وظیفه'
        verbose_name_plural = 'رویدادهای وظیفه'
        ordering = ['-created_at']

    def __str__(self) -> str:
        return f'{self.get_action_display()} — {self.task.title[:40]}'
