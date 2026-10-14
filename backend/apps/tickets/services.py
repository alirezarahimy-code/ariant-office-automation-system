"""سرویس‌های تیکت بومی — ایجاد، پیام، انتساب و تغییر وضعیت با اعلان."""
from __future__ import annotations

from .models import Ticket, TicketEvent, TicketMessage


def _notify(user, title: str, body: str, ticket: Ticket) -> None:
    if user is None:
        return
    from apps.core.services import send_notification
    send_notification(user, title, body[:200], f'/tickets/{ticket.pk}/')


def create_ticket(*, requester, subject: str, body: str = '',
                  priority: str = Ticket.Priority.NORMAL,
                  service_group: str = '', assignee=None) -> Ticket:
    ticket = Ticket.objects.create(
        subject=subject[:300], body=body, requester=requester,
        priority=priority, service_group=service_group[:40], assignee=assignee)
    TicketEvent.objects.create(
        ticket=ticket, actor=requester, action=TicketEvent.Action.CREATED,
        note='تیکت ثبت شد')
    if assignee:
        TicketEvent.objects.create(
            ticket=ticket, actor=requester, action=TicketEvent.Action.ASSIGNED,
            note=f'کارشناس مسئول: {assignee}')
        _notify(assignee, f'تیکت تازه: {subject[:60]}', body, ticket)
    return ticket


def add_message(ticket: Ticket, author, body: str) -> TicketMessage | None:
    body = (body or '').strip()
    if not body:
        return None
    msg = TicketMessage.objects.create(ticket=ticket, author=author, body=body[:5000])
    TicketEvent.objects.create(ticket=ticket, actor=author,
                               action=TicketEvent.Action.MESSAGE, note=body[:200])
    # اعلان به طرف مقابل (درخواست‌دهنده یا کارشناس)
    target = ticket.assignee if author == ticket.requester else ticket.requester
    if target and target != author:
        _notify(target, f'پیام تازه روی تیکت: {ticket.subject[:60]}', body, ticket)
    return msg


def assign_ticket(ticket: Ticket, user, assignee) -> Ticket:
    ticket.assignee = assignee
    ticket.save(update_fields=['assignee', 'updated_at'])
    TicketEvent.objects.create(
        ticket=ticket, actor=user, action=TicketEvent.Action.ASSIGNED,
        note=f'انتساب به {assignee}')
    _notify(assignee, f'تیکت به شما منتسب شد: {ticket.subject[:60]}', '', ticket)
    if ticket.requester and ticket.requester != user:
        _notify(ticket.requester, f'کارشناس تیکت شما مشخص شد: {ticket.subject[:60]}',
                str(assignee), ticket)
    return ticket


def change_status(ticket: Ticket, user, status: str, note: str = '') -> Ticket | None:
    if status not in Ticket.Status.values:
        return None
    old = ticket.status
    ticket.status = status
    ticket.save(update_fields=['status', 'updated_at'])
    TicketEvent.objects.create(
        ticket=ticket, actor=user, action=TicketEvent.Action.STATUS,
        note=note or f'وضعیت از «{old}» به «{status}» تغییر کرد')
    target = ticket.requester if user == ticket.assignee else ticket.assignee
    if target and target != user:
        _notify(target, f'وضعیت تیکت تغییر کرد: {ticket.subject[:60]}',
                note or ticket.get_status_display(), ticket)
    return ticket
