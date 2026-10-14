"""مجموعه‌داده‌های گزارش‌ساز — هر گزارش: عنوان، سرستون‌ها، ردیف‌ها.

همه گزارش‌ها بازه تاریخ شمسی می‌گیرند و داده را از مدل‌های بومی
می‌سازند؛ خروجی در پیش‌نمایش، CSV، XLSX و نسخه چاپی مشترک است.
"""
from __future__ import annotations

import jdatetime

from apps.attendance.models import AttendanceRecord
from apps.secretariat.models import Letter
from apps.tasks.models import Task
from apps.tickets.models import Ticket


def _greg(jdate):
    return jdate.togregorian() if jdate is not None else None


def _rows_for_letters(date_from, date_to):
    qs = Letter.objects.all()
    if date_from:
        qs = qs.filter(letter_date__gte=_greg(date_from))
    if date_to:
        qs = qs.filter(letter_date__lte=_greg(date_to))
    rows = []
    for letter in qs.select_related('created_by')[:2000]:
        rows.append([
            letter.number_display or '—', letter.get_letter_type_display(),
            letter.subject, str(letter.letter_date or '—'),
            letter.get_status_display(),
            letter.created_by.username if letter.created_by else '—'])
    return ['شماره', 'نوع', 'موضوع', 'تاریخ', 'وضعیت', 'ثبت‌کننده'], rows


def _rows_for_tasks(date_from, date_to):
    qs = Task.objects.all()
    if date_from:
        qs = qs.filter(due_date__gte=date_from)
    if date_to:
        qs = qs.filter(due_date__lte=date_to)
    rows = []
    for task in qs.select_related('responsible')[:2000]:
        rows.append([
            task.title, task.get_responsible_display_name()
            if hasattr(task, 'get_responsible_display_name')
            else task.responsible.username,
            str(task.due_date or '—'), task.get_priority_display(),
            task.get_status_display(), f'{task.progress}٪'])
    return ['عنوان', 'مسئول', 'مهلت', 'فوریت', 'وضعیت', 'پیشرفت'], rows


def _rows_for_attendance(date_from, date_to):
    qs = AttendanceRecord.objects.all()
    if date_from:
        qs = qs.filter(date__gte=date_from)
    if date_to:
        qs = qs.filter(date__lte=date_to)
    rows = []
    for record in qs.select_related('user')[:2000]:
        rows.append([
            record.user.username, str(record.date),
            record.check_in.strftime('%H:%M') if record.check_in else '—',
            record.check_out.strftime('%H:%M') if record.check_out else '—',
            record.get_source_display()])
    return ['کاربر', 'تاریخ', 'ورود', 'خروج', 'منبع'], rows


def _rows_for_tickets(date_from, date_to):
    qs = Ticket.objects.all()
    rows = []
    for ticket in qs.select_related('requester', 'assignee')[:2000]:
        rows.append([
            ticket.subject, ticket.get_status_display(),
            ticket.get_priority_display(),
            ticket.requester.username if ticket.requester else '—',
            ticket.assignee.username if ticket.assignee else '—',
            ticket.service_group or '—'])
    return ['موضوع', 'وضعیت', 'اولویت', 'درخواست‌دهنده', 'کارشناس', 'حوزه'], rows


REPORTS = {
    'letters': {'title': 'گزارش نامه‌ها', 'fn': _rows_for_letters},
    'tasks': {'title': 'گزارش وظایف', 'fn': _rows_for_tasks},
    'attendance': {'title': 'گزارش تردد', 'fn': _rows_for_attendance},
    'tickets': {'title': 'گزارش تیکت‌ها', 'fn': _rows_for_tickets},
}


def run_report(slug: str, date_from=None, date_to=None):
    """اجرای گزارش → (عنوان، سرستون‌ها، ردیف‌ها)."""
    spec = REPORTS.get(slug)
    if spec is None:
        raise ValueError(f'گزارش ناشناخته: {slug}')
    headers, rows = spec['fn'](date_from, date_to)
    return spec['title'], headers, rows


def report_titles() -> list[tuple[str, str]]:
    return [(slug, spec['title']) for slug, spec in REPORTS.items()]
