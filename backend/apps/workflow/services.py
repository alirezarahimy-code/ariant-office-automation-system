"""موتور گردش: اتصال، گذارهای مجاز، و اجرای گذار با ژورنال."""
from __future__ import annotations

from django.contrib.contenttypes.models import ContentType

from .models import Transition, Workflow, WorkflowAction, WorkflowInstance


def instance_for(obj) -> WorkflowInstance | None:
    ct = ContentType.objects.get_for_model(obj)
    return WorkflowInstance.objects.filter(
        content_type=ct, object_id=obj.pk).select_related(
        'workflow', 'current_stage').first()


def bind_workflow(obj, workflow: Workflow, user=None, note: str = '') -> WorkflowInstance:
    """اتصال سند به گردش و قرارگیری در مرحله آغاز (idempotent)."""
    ct = ContentType.objects.get_for_model(obj)
    start = (workflow.stages.filter(is_start=True).order_by('order').first()
             or workflow.stages.order_by('order').first())
    if start is None:
        raise ValueError('گردش هیچ مرحله‌ای ندارد.')
    instance, created = WorkflowInstance.objects.get_or_create(
        content_type=ct, object_id=obj.pk,
        defaults={'workflow': workflow, 'current_stage': start, 'started_by': user})
    if created:
        WorkflowAction.objects.create(
            instance=instance, to_stage=start, actor=user, note=note or 'آغاز گردش کار')
    return instance


def available_transitions(instance: WorkflowInstance, user) -> list[Transition]:
    """گذارهای مجاز برای کاربر: مجوز + شرط میدانی روی سند."""
    obj = instance.content_object
    result = []
    queryset = Transition.objects.filter(
        workflow=instance.workflow, from_stage=instance.current_stage,
    ).select_related('to_stage')
    for transition in queryset:
        if transition.permission_codename and not (
                user.is_superuser or user.has_perm(transition.permission_codename)):
            continue
        if transition.condition_field:
            actual = str(getattr(obj, transition.condition_field, ''))
            if actual != transition.condition_value:
                continue
        result.append(transition)
    return result


def apply_transition(instance: WorkflowInstance, transition: Transition, user,
                     note: str = '') -> WorkflowInstance | None:
    """اجرای گذار پس از اعتبارسنجی؛ در صورت عدم مجاز بودن None."""
    if transition not in available_transitions(instance, user):
        return None
    from_stage = instance.current_stage
    instance.current_stage = transition.to_stage
    instance.save(update_fields=['current_stage', 'updated_at'])
    WorkflowAction.objects.create(
        instance=instance, transition=transition, from_stage=from_stage,
        to_stage=transition.to_stage, actor=user, note=note)
    return instance
