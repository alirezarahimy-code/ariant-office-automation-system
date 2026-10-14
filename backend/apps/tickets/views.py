"""نماهای تیکت بومی — با دروازه TICKET_MODE برای دوران گذار.

bridge (پیش‌فرض): فقط آینه تیکت‌های PHP + پیوند ورود به سامانه فعلی.
native: چرخه کامل تیکت بومی (ثبت، پیام، انتساب، وضعیت).
"""
from django.conf import settings
from django.contrib import messages
from django.contrib.auth import get_user_model
from django.contrib.auth.decorators import login_required
from django.db.models import Q
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from .models import Ticket
from .services import add_message, assign_ticket, change_status, create_ticket

User = get_user_model()


def native_mode() -> bool:
    return getattr(settings, 'TICKET_MODE', 'bridge') == 'native'


def _can_assign(user) -> bool:
    return user.is_superuser or user.has_perm('tickets.assign_ticket')


def _can_view_all(user) -> bool:
    return user.is_superuser or user.has_perm('tickets.view_all_ticket')


def _visible_tickets(user):
    if _can_view_all(user):
        return Ticket.objects.all()
    return Ticket.objects.filter(
        Q(requester=user) | Q(assignee=user)).distinct()


@login_required
def ticket_list(request):
    if not native_mode():
        from apps.ticket_bridge.models import ExternalTicket
        return render(request, 'tickets/mirror_list.html', {
            'external_tickets': ExternalTicket.objects.all()[:200],
            'bridge_url': '/bridge/jump/',
        })
    tab = request.GET.get('tab', 'mine')
    tickets = _visible_tickets(request.user).select_related('requester', 'assignee')
    if tab == 'mine':
        tickets = tickets.filter(requester=request.user)
    elif tab == 'assigned':
        tickets = tickets.filter(assignee=request.user)
    elif tab == 'open':
        tickets = tickets.filter(status__in=[
            Ticket.Status.OPEN, Ticket.Status.IN_PROGRESS, Ticket.Status.WAITING_USER])
    elif tab == 'all':
        pass  # _visible_tickets سطح دسترسی را اعمال کرده است
    else:
        tab = 'mine'
        tickets = tickets.filter(requester=request.user)
    return render(request, 'tickets/list.html', {
        'tickets': tickets[:200], 'tab': tab,
        'can_assign': _can_assign(request.user),
        'can_view_all': _can_view_all(request.user),
    })


@login_required
def ticket_new(request):
    if not native_mode():
        messages.info(request, 'ثبت تیکت بومی غیرفعال است — از سامانه تیکتینگ استفاده کنید.')
        return redirect('bridge:jump')
    if request.method == 'POST':
        subject = request.POST.get('subject', '').strip()[:300]
        if not subject:
            messages.error(request, 'موضوع تیکت الزامی است.')
        else:
            assignee = None
            if _can_assign(request.user) and request.POST.get('assignee_id'):
                assignee = User.objects.filter(pk=request.POST['assignee_id']).first()
            ticket = create_ticket(
                requester=request.user, subject=subject,
                body=request.POST.get('body', ''),
                priority=request.POST.get('priority', Ticket.Priority.NORMAL),
                service_group=request.POST.get('service_group', ''),
                assignee=assignee)
            messages.success(request, f'تیکت «{ticket.subject}» ثبت شد.')
            return redirect('tickets:detail', pk=ticket.pk)
    return render(request, 'tickets/new.html', {
        'users': User.objects.filter(is_active=True).order_by('first_name')[:200],
        'can_assign': _can_assign(request.user),
        'groups': sorted(Ticket.Priority.choices, key=lambda c: c[0]),
    })


@login_required
def ticket_detail(request, pk):
    if not native_mode():
        return redirect('tickets:list')
    ticket = get_object_or_404(
        Ticket.objects.select_related('requester', 'assignee'), pk=pk)
    if not (_can_view_all(request.user)
            or ticket.requester_id == request.user.pk
            or ticket.assignee_id == request.user.pk):
        messages.error(request, 'به این تیکت دسترسی ندارید.')
        return redirect('tickets:list')
    return render(request, 'tickets/detail.html', {
        'ticket': ticket,
        'msgs': ticket.messages.select_related('author')[:200],
        'events': ticket.events.select_related('actor')[:100],
        'can_assign': _can_assign(request.user),
        'users': User.objects.filter(is_active=True).order_by('first_name')[:200],
        'is_participant': ticket.requester_id == request.user.pk
                          or ticket.assignee_id == request.user.pk,
    })


@login_required
@require_POST
def ticket_message(request, pk):
    if not native_mode():
        return redirect('tickets:list')
    ticket = get_object_or_404(Ticket, pk=pk)
    if not (_can_view_all(request.user) or _can_assign(request.user)
            or ticket.requester_id == request.user.pk
            or ticket.assignee_id == request.user.pk):
        messages.error(request, 'اجازه ارسال پیام ندارید.')
        return redirect('tickets:detail', pk=pk)
    if add_message(ticket, request.user, request.POST.get('body', '')) is None:
        messages.error(request, 'متن پیام خالی است.')
    else:
        messages.success(request, 'پیام شما ثبت شد.')
    return redirect('tickets:detail', pk=pk)


@login_required
@require_POST
def ticket_status(request, pk):
    if not native_mode():
        return redirect('tickets:list')
    ticket = get_object_or_404(Ticket, pk=pk)
    if not (_can_assign(request.user) or ticket.requester_id == request.user.pk):
        messages.error(request, 'اجازه تغییر وضعیت ندارید.')
        return redirect('tickets:detail', pk=pk)
    if change_status(ticket, request.user, request.POST.get('status', ''),
                     request.POST.get('note', '')) is None:
        messages.error(request, 'وضعیت نامعتبر است.')
    else:
        messages.success(request, 'وضعیت تیکت به‌روز شد.')
    return redirect('tickets:detail', pk=pk)


@login_required
@require_POST
def ticket_assign(request, pk):
    if not native_mode():
        return redirect('tickets:list')
    ticket = get_object_or_404(Ticket, pk=pk)
    if not _can_assign(request.user):
        messages.error(request, 'مجوز انتساب تیکت ندارید.')
        return redirect('tickets:detail', pk=pk)
    assignee = User.objects.filter(pk=request.POST.get('assignee_id')).first()
    if assignee is None:
        messages.error(request, 'کارشناس مسئول نامعتبر است.')
    else:
        assign_ticket(ticket, request.user, assignee)
        messages.success(request, f'تیکت به {assignee} منتسب شد.')
    return redirect('tickets:detail', pk=pk)
