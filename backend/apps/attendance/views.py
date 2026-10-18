"""نماهای کنترل تردد: فهرست ماهانه، ثبت دستی و گزارش روزانه."""
from __future__ import annotations

import datetime as gregorian_datetime

import jdatetime
from django.contrib import messages
from django.contrib.auth import get_user_model
from django.contrib.auth.decorators import login_required
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_POST

from apps.core.services import feature_enabled

from .models import (AttendancePunch, AttendancePolicy, AttendanceRecord,
                     AttendanceRequest, Deputy, GeoArea, LeaveBalance, LeaveType,
                     OvertimeConstant, RewardPenalty)
from .services import (STATE_LABELS, approve_request, can_approve, create_request,
                       default_schedule, leaves_report, location_report,
                       monthly_report, monthly_summary, overtime_report,
                       policy, register_punch, register_record, reject_request,
                       undo_request)

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


# ─────────────────────────────────────────────────────────────
# لایه حرفه‌ای نماها
# ─────────────────────────────────────────────────────────────
def _parse_date(raw: str):
    from apps.secretariat.views import _parse_jalali_input
    return _parse_jalali_input(raw)


def _parse_money(raw: str):
    from decimal import Decimal
    return Decimal((raw or '0').replace(',', '').strip() or '0')


@login_required
def dashboard(request):
    """داشبورد حضور و غیاب — آینه‌ی صفحه Home سامانه مرجع."""
    from .services import monthly_summary as _ms
    today = jdatetime.date.today()
    summary = _ms(request.user, today.year, today.month)
    my_requests = AttendanceRequest.objects.filter(user=request.user)[:8]
    pending = []
    if _can_manage(request.user):
        pending = AttendanceRequest.objects.filter(
            status=AttendanceRequest.Status.PENDING).select_related('user')[:10]
    else:
        for req in AttendanceRequest.objects.filter(
                status=AttendanceRequest.Status.PENDING).select_related('user'):
            if can_approve(request.user, req):
                pending.append(req)
            if len(pending) >= 10:
                break
    counts = {
        'all': AttendanceRequest.objects.filter(user=request.user).count(),
        'pending': AttendanceRequest.objects.filter(
            user=request.user, status=AttendanceRequest.Status.PENDING).count(),
        'approved': AttendanceRequest.objects.filter(
            user=request.user, status=AttendanceRequest.Status.APPROVED).count(),
        'cancelled': AttendanceRequest.objects.filter(
            user=request.user, status__in=['cancelled', 'rejected']).count(),
    }
    return render(request, 'attendance/dashboard.html', {
        'summary': summary, 'counts': counts,
        'my_requests': my_requests, 'pending': pending,
        'month_name': MONTHS[today.month - 1], 'state_labels': STATE_LABELS,
        'kinds': AttendanceRequest.Kind.choices,
        'leave_types': LeaveType.objects.filter(is_active=True),
    })


@login_required
def request_list(request):
    tab = request.GET.get('tab', 'mine')
    qs = AttendanceRequest.objects.select_related('user', 'leave_type', 'decided_by')
    if tab == 'mine':
        qs = qs.filter(user=request.user)
    elif tab == 'pending':
        qs = qs.filter(status=AttendanceRequest.Status.PENDING)
        if not _can_manage(request.user):
            qs = [r for r in qs if can_approve(request.user, r)]
    elif not _can_manage(request.user):
        qs = qs.filter(user=request.user)
    return render(request, 'attendance/request_list.html', {
        'requests': qs, 'tab': tab, 'can_manage': _can_manage(request.user),
    })


@login_required
def request_new(request):
    if request.method != 'POST':
        return render(request, 'attendance/request_new.html', {
            'kinds': AttendanceRequest.Kind.choices,
            'leave_types': LeaveType.objects.filter(is_active=True),
            'today': jdatetime.date.today().strftime('%Y/%m/%d'),
            'users': User.objects.filter(is_active=True).order_by('username')
            if _can_manage(request.user) else [],
        })
    kind = request.POST.get('kind', '')
    if kind not in AttendanceRequest.Kind.values:
        messages.error(request, 'نوع درخواست نامعتبر است.')
        return redirect('attendance:request_new')
    target = request.user
    if _can_manage(request.user) and request.POST.get('user_id'):
        target = User.objects.filter(pk=request.POST.get('user_id')).first() or request.user
    leave_type = None
    if request.POST.get('leave_type_id'):
        leave_type = LeaveType.objects.filter(
            pk=request.POST.get('leave_type_id')).first()
    try:
        req = create_request(
            kind=kind, user=target, leave_type=leave_type,
            for_date=_parse_date(request.POST.get('for_date', '')),
            from_date=_parse_date(request.POST.get('from_date', '')),
            to_date=_parse_date(request.POST.get('to_date', '')),
            from_time=_parse_time(request.POST.get('from_time', '')),
            to_time=_parse_time(request.POST.get('to_time', '')),
            hours=_parse_money(request.POST.get('hours', '0') or '0'),
            days=_parse_money(request.POST.get('days', '0') or '0'),
            description=request.POST.get('description', '').strip())
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
        return redirect('attendance:request_new')
    messages.success(request, f'درخواست {req.get_kind_display()} ثبت شد.')
    return redirect('attendance:request_detail', pk=req.pk)


@login_required
def request_detail(request, pk):
    req = get_object_or_404(
        AttendanceRequest.objects.select_related('user', 'leave_type', 'decided_by'),
        pk=pk)
    is_owner = req.user == request.user
    if not is_owner and not _can_manage(request.user) and not can_approve(request.user, req):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('attendance:request_list')
    return render(request, 'attendance/request_detail.html', {
        'req': req, 'is_owner': is_owner,
        'can_decide': can_approve(request.user, req),
    })


@login_required
@require_POST
def request_decide(request, pk):
    req = get_object_or_404(AttendanceRequest, pk=pk)
    action = request.POST.get('action')
    try:
        if action == 'approve':
            approve_request(req, request.user, note=request.POST.get('note', ''))
            messages.success(request, 'درخواست تأیید شد.')
        elif action == 'reject':
            reject_request(req, request.user, note=request.POST.get('note', ''))
            messages.success(request, 'درخواست رد شد.')
        elif action == 'cancel':
            undo_request(req, request.user)
            messages.success(request, 'درخواست لغو (ابطال) شد.')
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('attendance:request_detail', pk=pk)


@login_required
def punches(request):
    if not feature_enabled('attendance.punches'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'attendance.punches'})
    qs = AttendancePunch.objects.select_related('user', 'area')
    if not _can_manage(request.user):
        qs = qs.filter(user=request.user)
    return render(request, 'attendance/punches.html', {
        'punches': qs[:100], 'can_manage': _can_manage(request.user),
        'areas': GeoArea.objects.filter(is_active=True),
    })


@login_required
@require_POST
def punch_add(request):
    target = request.user
    if _can_manage(request.user) and request.POST.get('user_id'):
        target = User.objects.filter(pk=request.POST.get('user_id')).first() or request.user
    if target != request.user and not _can_manage(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('attendance:punches')
    jdate = _parse_date(request.POST.get('date', '')) or jdatetime.date.today()
    t = _parse_time(request.POST.get('time', '')) or         gregorian_datetime.datetime.now().time()
    try:
        register_punch(
            user=target, date=jdate, time=t,
            direction=request.POST.get('direction', 'in'),
            source=AttendancePunch.Source.MANUAL,
            description=request.POST.get('description', ''),
            lat=_parse_money(request.POST.get('lat', '') or None) if request.POST.get('lat') else None,
            lng=_parse_money(request.POST.get('lng', '') or None) if request.POST.get('lng') else None,
            created_by=request.user)
        messages.success(request, 'رویداد تردد ثبت شد.')
    except (ValueError, ArithmeticError) as exc:
        messages.error(request, str(exc))
    return redirect('attendance:punches')


@login_required
def leaves(request):
    if not feature_enabled('attendance.daily_leave') and not feature_enabled('attendance.hourly_leave'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'attendance.daily_leave'})
    year = jdatetime.date.today().year
    rows = []
    for lt in LeaveType.objects.filter(is_active=True):
        from .services import leave_balance_row
        rows.append(leave_balance_row(request.user, lt, year))
    return render(request, 'attendance/leaves.html', {
        'rows': rows, 'year': year,
        'requests': AttendanceRequest.objects.filter(
            user=request.user,
            kind__in=AttendanceRequest.LEAVE_KINDS)[:20],
    })


@login_required
def overtime_view(request):
    if not feature_enabled('attendance.overtime'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'attendance.overtime'})
    year, month = _month_from_request(request)
    rows = overtime_report(year, month)
    return render(request, 'attendance/overtime.html', {
        'rows': rows, 'year': year, 'month': month,
        'month_name': MONTHS[month - 1],
        'constants': OvertimeConstant.objects.all()
        if feature_enabled('attendance.overtime_constants') else [],
    })


@login_required
@require_POST
def overtime_constant_add(request):
    if not _can_manage(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('attendance:overtime')
    OvertimeConstant.objects.create(
        key=request.POST.get('key', '').strip() or f'c{OvertimeConstant.objects.count()+1}',
        title=request.POST.get('title', '').strip(),
        value=_parse_money(request.POST.get('value', '0')),
        description=request.POST.get('description', ''))
    messages.success(request, 'ثابت اضافه‌کاری ثبت شد.')
    return redirect('attendance:overtime')


@login_required
def rewards(request):
    if not feature_enabled('attendance.rewards'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'attendance.rewards'})
    qs = RewardPenalty.objects.select_related('user')
    if not _can_manage(request.user):
        qs = qs.filter(user=request.user)
    return render(request, 'attendance/rewards.html', {
        'rows': qs[:100], 'can_manage': _can_manage(request.user),
    })


@login_required
@require_POST
def reward_add(request):
    if not _can_manage(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('attendance:rewards')
    target = User.objects.filter(pk=request.POST.get('user_id')).first()
    if target is None:
        messages.error(request, 'کاربر را انتخاب کنید.')
        return redirect('attendance:rewards')
    RewardPenalty.objects.create(
        user=target, kind=request.POST.get('kind', 'reward'),
        date=_parse_date(request.POST.get('date', '')) or jdatetime.date.today(),
        title=request.POST.get('title', '').strip(),
        amount=_parse_money(request.POST.get('amount', '0')),
        description=request.POST.get('description', ''))
    messages.success(request, 'ثبت شد.')
    return redirect('attendance:rewards')


@login_required
def geo_list(request):
    if not feature_enabled('attendance.geo_fence'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'attendance.geo_fence'})
    return render(request, 'attendance/geo.html', {
        'areas': GeoArea.objects.all(), 'can_manage': _can_manage(request.user),
    })


@login_required
@require_POST
def geo_add(request):
    if not _can_manage(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('attendance:geo')
    GeoArea.objects.create(
        name=request.POST.get('name', '').strip(),
        lat=_parse_money(request.POST.get('lat', '0')),
        lng=_parse_money(request.POST.get('lng', '0')),
        radius_m=int(request.POST.get('radius_m', '100') or '100'),
        description=request.POST.get('description', ''))
    messages.success(request, 'محدوده مجاز ثبت شد.')
    return redirect('attendance:geo')


@login_required
def location(request):
    if not feature_enabled('attendance.location_report'):
        return render(request, 'core/feature_disabled.html',
                      {'feature_code': 'attendance.location_report'})
    year, month = _month_from_request(request)
    rows = location_report(year, month)
    return render(request, 'attendance/location.html', {
        'rows': rows, 'year': year, 'month': month,
        'month_name': MONTHS[month - 1],
    })


@login_required
def policy_view(request):
    if not _can_manage(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('attendance:dashboard')
    p = policy()
    if request.method == 'POST':
        p.hours_per_day = _parse_money(request.POST.get('hours_per_day', '8') or '8')
        p.show_days = int(request.POST.get('show_days', '38') or '38')
        p.last_day_for_request = int(request.POST.get('last_day_for_request', '16') or '16')
        p.max_request_age_days = int(request.POST.get('max_request_age_days', '0') or '0')
        p.record_fix_daily_limit = int(request.POST.get('record_fix_daily_limit', '1') or '1')
        p.record_fix_monthly_limit = int(request.POST.get('record_fix_monthly_limit', '3') or '3')
        p.valid_late_early = request.POST.get('valid_late_early') == '1'
        p.allow_hourly_free = request.POST.get('allow_hourly_free') == '1'
        p.overtime_holiday_rate = _parse_money(
            request.POST.get('overtime_holiday_rate', '1.4') or '1.4')
        p.overtime_night_rate = _parse_money(
            request.POST.get('overtime_night_rate', '1.2') or '1.2')
        p.save()
        messages.success(request, 'سیاست‌ها ذخیره شد.')
        return redirect('attendance:policy')
    return render(request, 'attendance/policy.html', {'policy': p})
