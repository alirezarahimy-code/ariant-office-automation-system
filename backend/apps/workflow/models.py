"""مدل‌های موتور گردش کار — تعریف گردش، مرحله، گذار، نمونه و ژورنال اقدام.

موتور عمومی است: WorkflowInstance با GenericFK به هر سندی وصل می‌شود
(قاعده طلایی بند ۵ docs/08 — هر جریان تأیید یک تعریف روی این موتور).
"""
from django.conf import settings
from django.contrib.contenttypes.fields import GenericForeignKey
from django.contrib.contenttypes.models import ContentType
from django.db import models

from apps.core.models import TimeStampedModel


class Workflow(TimeStampedModel):
    """تعریف گردش — کد یکتا مثل letter-default."""

    code = models.CharField('کد', max_length=60, unique=True)
    name = models.CharField('نام', max_length=150)
    applies_to = models.CharField('دامنه کاربرد', max_length=60, blank=True, default='')
    description = models.TextField('توضیح', blank=True, default='')
    is_active = models.BooleanField('فعال', default=True)

    class Meta:
        verbose_name = 'گردش کار'
        verbose_name_plural = 'گردش‌های کار'
        ordering = ['code']

    def __str__(self) -> str:
        return self.name


class Stage(TimeStampedModel):
    """مرحله گردش — کلید یکتا در هر گردش."""

    workflow = models.ForeignKey(
        Workflow, verbose_name='گردش', on_delete=models.CASCADE, related_name='stages')
    key = models.CharField('کلید', max_length=40)
    title = models.CharField('عنوان', max_length=100)
    order = models.PositiveSmallIntegerField('ترتیب', default=1)
    is_start = models.BooleanField('مرحله آغاز', default=False)
    is_final = models.BooleanField('مرحله پایان', default=False)

    class Meta:
        verbose_name = 'مرحله'
        verbose_name_plural = 'مراحل'
        ordering = ['order']
        constraints = [
            models.UniqueConstraint(fields=['workflow', 'key'], name='uq_stage_key'),
        ]

    def __str__(self) -> str:
        return self.title


class Transition(TimeStampedModel):
    """گذار بین دو مرحله — با مجوز لازم و شرط میدانی اختیاری روی سند."""

    workflow = models.ForeignKey(
        Workflow, verbose_name='گردش', on_delete=models.CASCADE, related_name='transitions')
    from_stage = models.ForeignKey(
        Stage, verbose_name='از مرحله', on_delete=models.CASCADE, related_name='outgoing')
    to_stage = models.ForeignKey(
        Stage, verbose_name='به مرحله', on_delete=models.CASCADE, related_name='incoming')
    title = models.CharField('عنوان', max_length=100)
    permission_codename = models.CharField(
        'مجوز لازم', max_length=100, blank=True, default='',
        help_text='مثل secretariat.refer_letter — خالی یعنی همه مجاز')
    condition_field = models.CharField(
        'فیلد شرط', max_length=60, blank=True, default='',
        help_text='نام فیلد روی سند — مثل priority')
    condition_value = models.CharField('مقدار شرط', max_length=60, blank=True, default='')
    order = models.PositiveSmallIntegerField('ترتیب', default=1)

    class Meta:
        verbose_name = 'گذار'
        verbose_name_plural = 'گذارها'
        ordering = ['order']

    def __str__(self) -> str:
        return f'{self.from_stage_id} → {self.to_stage_id}: {self.title}'


class WorkflowInstance(TimeStampedModel):
    """نمونه گردش روی یک سند — GenericFK با یکتایی (یک نمونه به ازای سند)."""

    workflow = models.ForeignKey(
        Workflow, verbose_name='گردش', on_delete=models.CASCADE, related_name='instances')
    content_type = models.ForeignKey(
        ContentType, verbose_name='نوع سند', on_delete=models.CASCADE, related_name='+')
    object_id = models.PositiveIntegerField('شناسه سند')
    content_object = GenericForeignKey('content_type', 'object_id')
    current_stage = models.ForeignKey(
        Stage, verbose_name='مرحله جاری', on_delete=models.PROTECT, related_name='+')
    started_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='آغازکننده', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')

    class Meta:
        verbose_name = 'نمونه گردش'
        verbose_name_plural = 'نمونه‌های گردش'
        constraints = [
            models.UniqueConstraint(
                fields=['content_type', 'object_id'], name='uq_wf_instance_object'),
        ]

    def __str__(self) -> str:
        return f'{self.workflow.code} #{self.object_id} @ {self.current_stage_id}'


class WorkflowAction(TimeStampedModel):
    instance = models.ForeignKey(
        WorkflowInstance, verbose_name='نمونه', on_delete=models.CASCADE, related_name='actions')
    transition = models.ForeignKey(
        Transition, verbose_name='گذار', null=True, blank=True, on_delete=models.SET_NULL)
    from_stage = models.ForeignKey(
        Stage, null=True, blank=True, on_delete=models.SET_NULL, related_name='+')
    to_stage = models.ForeignKey(
        Stage, null=True, blank=True, on_delete=models.SET_NULL, related_name='+')
    actor = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name='کنشگر', null=True, blank=True,
        on_delete=models.SET_NULL, related_name='+')
    note = models.TextField('یادداشت', blank=True, default='')

    class Meta:
        verbose_name = 'اقدام گردش'
        verbose_name_plural = 'اقدام‌های گردش'
        ordering = ['-created_at']

    def __str__(self) -> str:
        return f'{self.instance_id}: {self.from_stage_id} → {self.to_stage_id}'
