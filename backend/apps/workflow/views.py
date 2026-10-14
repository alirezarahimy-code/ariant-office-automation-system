"""نماهای گردش کار: فهرست، جزئیات، اتصال و اجرای گذار."""
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from .models import Transition, Workflow, WorkflowInstance
from .services import apply_transition, available_transitions, bind_workflow, instance_for


@login_required
def workflow_list(request):
    workflows = Workflow.objects.filter(is_active=True)
    return render(request, 'workflow/list.html', {'workflows': workflows})


@login_required
def workflow_detail(request, pk):
    workflow = get_object_or_404(Workflow, pk=pk)
    return render(request, 'workflow/detail.html', {
        'workflow': workflow,
        'stages': workflow.stages.all(),
        'transitions': workflow.transitions.select_related('from_stage', 'to_stage'),
        'instances_count': workflow.instances.count(),
    })


@login_required
@require_POST
def instance_apply(request, pk):
    """اجرای گذار روی نمونه — بازگشت به سند مبدأ."""
    instance = get_object_or_404(
        WorkflowInstance.objects.select_related('workflow', 'current_stage'), pk=pk)
    transition = get_object_or_404(Transition, pk=request.POST.get('transition'))
    note = request.POST.get('note', '')[:500]
    result = apply_transition(instance, transition, request.user, note=note)
    if result is None:
        messages.error(request, 'این گذار برای شما مجاز نیست یا شرط آن برقرار نیست.')
    else:
        messages.success(request, f'گردش به مرحله «{transition.to_stage.title}» رفت.')
    obj = instance.content_object
    if hasattr(obj, 'get_absolute_url'):
        return redirect(obj.get_absolute_url())
    from apps.secretariat.models import Letter
    if isinstance(obj, Letter):
        return redirect('secretariat:letter_detail', pk=obj.pk)
    return redirect('workflow:list')


@login_required
@require_POST
def bind_letter(request, letter_pk):
    """اتصال گردش پیش‌فرض به نامه (از کارت نامه)."""
    from apps.secretariat.models import Letter
    letter = get_object_or_404(Letter, pk=letter_pk)
    if not (request.user.is_superuser
            or request.user.has_perm('secretariat.manage_secretariat')):
        messages.error(request, 'مجوز مدیریت گردش نامه را ندارید.')
        return redirect('secretariat:letter_detail', pk=letter_pk)
    workflow = Workflow.objects.filter(code='letter-default', is_active=True).first()
    if workflow is None:
        messages.error(request, 'گردش پیش‌فرض تعریف نشده — setup_default_workflow را اجرا کنید.')
        return redirect('secretariat:letter_detail', pk=letter_pk)
    bind_workflow(letter, workflow, request.user)
    messages.success(request, f'گردش «{workflow.name}» به نامه متصل شد.')
    return redirect('secretariat:letter_detail', pk=letter_pk)
