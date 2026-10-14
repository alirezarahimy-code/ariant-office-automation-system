"""نماهای کنترل تردد: فهرست ماهانه، ثبت دستی و گزارش روزانه."""
from __future__ import annotations

import datetime as gregorian_datetime

import jdatetime
from django.contrib import messages
from django.contrib.auth import get_user_model
from django.contrib.auth.decorators import login_required
from django.shortcuts import redirect, render
from django.views.decorators.http import require_POST

from .models import AttendanceRecord
from .services import (STATE_LABELS, default_schedule, monthly_summary,
                       register_record)

User = get_user_model()

MONTHS = ('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
          'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند')


def _can_manage(user) -> bool:
    return user.is_superuser or user.has_perm('attendance.manage_attendance')


def _current_jalali_month() -> tuple[int, int]:
    today = jdatetime.date.today()
    return today.year, today.month


def _month_from_request(request) -> tuple[int, int]:
    today_y, today_m = _current_jalali_month()
    try:
        year = int(request.GET.get('year', today_y))
        month = int(request.GET.get('month', today_m))
        jdatetime.date(year, month, 1)
    except (TypeError, ValueError):
        year, month = today_y, today_m
    return year, month


def _user_from_request(request, fallback):
    if not _can_manage(request.user):
        return fallback
    raw = request.GET.get('user') or request.POST.get('user_id')
    if raw:
        user = User.objects.filter(pk=raw).first()
        if user is not None:
            return user
    return fallback


def _parse_time(raw: str):
    raw = (raw or '').strip()
    if not raw:
        return None
    try:
        return gregorian_datetime.datetime.strptime(raw, '%H:%M').time()
    except ValueError:
        return None


@login_required
def attendance_list(request):
    year, month = _month_from_request(request)
    target = _user_from_request(request, request.user)
    summary = monthly_summary(target, year, month)
    users = User.objects.filter(is_active=True).order_by('username') \
        if _can_manage(request.user) else []
    return render(request, 'attendance/list.html', {
        'summary': summary, 'target': target, 'users': users,
        'year': year, 'month': month, 'month_name': MONTHS[month - 1],
        'state_labels': STATE_LABELS, 'can_manage': _can_manage(request.user),
    })


@login_required
def attendance_month(request, user_pk=None):
    """گزارش ماهانه کارکرد — همان خلاصه در قالب کارت‌های آماری."""
    year, month = _month_from_request(request)
    if user_pk is not None:
        target = User.objects.filter(pk=user_pk).first() or request.user
    else:
        target = _user_from_request(request, request.user)
    summary = monthly_summary(target, year, month)
    return render(request, 'attendance/monthly.html', {
        'summary': summary, 'target': target,
        'year': year, 'month': month, 'month_name': MONTHS[month - 1],
        'state_labels': STATE_LABELS,
    })


@login_required
def attendance_new(request):
    if request.method != 'POST':
        return render(request, 'attendance/new.html', {
            'users': User.objects.filter(is_active=True).order_by('username')
            if _can_manage(request.user) else [request.user],
            'today': jdatetime.date.today().strftime('%Y/%m/%d'),
        })
    target = _user_from_request(request, request.user)
    if target != request.user and not _can_manage(request.user):
        messages.error(request, 'امکان ثبت تردد برای دیگران را ندارید.')
        return redirect('attendance:list')

    from apps.secretariat.views import _parse_jalali_input
    jdate = _parse_jalali_input(request.POST.get('date', ''))
    if jdate is None:
        messages.error(request, 'تاریخ شمسی معتبر نیست (مثلاً ۱۴۰۴/۰۷/۲۴).')
        return redirect('attendance:new')
    check_in = _parse_time(request.POST.get('check_in', ''))
    check_out = _parse_time(request.POST.get('check_out', ''))
    if check_in is None and check_out is None:
        messages.error(request, 'حداقل یکی از ساعت ورود یا خروج لازم است.')
        return redirect('attendance:new')
    register_record(
        user=target, date=jdate, check_in=check_in, check_out=check_out,
        source=AttendanceRecord.Source.MANUAL,
        note=request.POST.get('note', '').strip()[:300])
    messages.success(request, f'تردد {jdate.strftime("%Y/%m/%d")} برای {target} ثبت شد.')
    return redirect('attendance:list')
