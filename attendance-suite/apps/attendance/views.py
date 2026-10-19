"""حضور و غیاب: داشبورد، درخواست‌ها، تردد، گزارش‌ها، تنظیمات."""
import datetime as gregorian

import jdatetime
from django.contrib import messages
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.services import audit, has_perm, require_perm, scoped_employees

from .models import (AttendancePolicy, DailyRecord, Deputy, GeoArea, Holiday,
                     LeaveBalance, LeaveType, OvertimeConstant, Punch, Request,
                     RewardPenalty, Shift, ShiftAssignment)
from .services import (approve_request, create_request, monthly_summary,
                       parse_jalali, register_punch, reject_request,
                       undo_request)


def _employee(request):
    return getattr(request.user, 'employee', None)


def _parse_date(text):
    text = (text or '').strip()
    return parse_jalali(text) if text else None


def _parse_time(text):
    text = (text or '').strip()
    if not text:
        return None
    h, m = text.split(':')
    return gregorian.time(int(h), int(m))


@require_perm('attendance', 'view')
def dashboard(request):
    today = jdatetime.date.today()
    employee = _employee(request)
    summary = monthly_summary(employee, today.year, today.month) if employee else None
    pending = []
    if has_perm(request.user, 'requests', 'approve'):
        pending = Request.objects.filter(
            status=Request.Status.PENDING).select_related('employee')[:8]
    return render(request, 'attendance/dashboard.html', {
        'summary': summary, 'today': today, 'pending': pending,
    })


# ---------- درخواست‌ها ----------

@require_perm('requests', 'view')
def request_list(request):
    tab = request.GET.get('tab', 'mine')
    qs = Request.objects.select_related('employee', 'leave_type', 'decided_by')
    employee = _employee(request)
    if tab == 'pending' and has_perm(request.user, 'requests', 'approve'):
        qs = qs.filter(status=Request.Status.PENDING)
    elif tab == 'all' and has_perm(request.user, 'requests', 'report'):
        pass
    else:
        tab = 'mine'
        qs = qs.filter(employee=employee) if employee else qs.none()
    return render(request, 'attendance/requests.html', {
        'requests': qs[:200], 'tab': tab,
        'can_approve': has_perm(request.user, 'requests', 'approve'),
    })


@require_perm('requests', 'add')
def request_new(request):
    employee = _employee(request)
    if employee is None:
        messages.error(request, 'پروفایل پرسنلی به حساب شما متصل نیست.')
        return redirect('attendance:dashboard')
    if request.method == 'POST':
        try:
            req = create_request(
                request.POST.get('kind'), employee,
                leave_type=LeaveType.objects.filter(
                    pk=request.POST.get('leave_type_id') or 0).first(),
                for_date=_parse_date(request.POST.get('for_date')),
                from_date=_parse_date(request.POST.get('from_date')),
                to_date=_parse_date(request.POST.get('to_date')),
                from_time=_parse_time(request.POST.get('from_time')),
                to_time=_parse_time(request.POST.get('to_time')),
                hours=float(request.POST.get('hours') or 0),
                days=float(request.POST.get('days') or 0),
                description=request.POST.get('description', ''))
            audit(request.user, 'add', 'Request', req.pk)
            messages.success(request, 'درخواست ثبت شد.')
            return redirect('attendance:request_detail', pk=req.pk)
        except (ValueError, TypeError) as exc:
            messages.error(request, str(exc))
    from .models import ExitType
    return render(request, 'attendance/request_form.html', {
        'kinds': Request.Kind.choices,
        'leave_types': LeaveType.objects.filter(is_active=True),
        'exit_types': ExitType.objects.filter(is_active=True),
        'today': jdatetime.date.today().strftime('%Y/%m/%d'),
    })


@require_perm('requests', 'view')
def request_detail(request, pk):
    req = get_object_or_404(
        Request.objects.select_related('employee', 'leave_type', 'decided_by'),
        pk=pk)
    employee = _employee(request)
    if not (has_perm(request.user, 'requests', 'report')
            or (employee and req.employee_id == employee.pk)
            or has_perm(request.user, 'requests', 'approve')):
        return render(request, 'core/forbidden.html')
    return render(request, 'attendance/request_detail.html', {
        'req': req,
        'can_decide': has_perm(request.user, 'requests', 'approve'),
    })


@require_perm('requests', 'view')
def request_decide(request, pk):
    if request.method != 'POST':
        return redirect('attendance:request_detail', pk=pk)
    req = get_object_or_404(Request, pk=pk)
    action = request.POST.get('action')
    try:
        if action == 'approve':
            approve_request(req, request.user, request.POST.get('note', ''))
            messages.success(request, 'درخواست تأیید شد.')
        elif action == 'reject':
            reject_request(req, request.user, request.POST.get('note', ''))
            messages.success(request, 'درخواست رد شد.')
        elif action == 'cancel':
            undo_request(req, request.user)
            messages.success(request, 'درخواست لغو (ابطال) شد.')
        audit(request.user, f'request_{action}', 'Request', req.pk)
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('attendance:request_detail', pk=pk)


# ---------- تردد ----------

@require_perm('attendance', 'view')
def punches(request):
    employee = _employee(request)
    qs = Punch.objects.select_related('employee', 'device', 'area')
    if not has_perm(request.user, 'attendance', 'report'):
        qs = qs.filter(employee=employee)
    return render(request, 'attendance/punches.html', {
        'punches': qs[:200],
        'can_add': has_perm(request.user, 'attendance', 'add'),
    })


@require_perm('attendance', 'add')
def punch_add(request):
    if request.method == 'POST':
        employee = _employee(request)
        if has_perm(request.user, 'attendance', 'manage') and \
                request.POST.get('employee_id'):
            employee = scoped_employees(
                request.user, 'attendance',
                Employee_qs()).filter(
                pk=request.POST.get('employee_id')).first() or employee
        try:
            register_punch(
                employee, _parse_date(request.POST.get('date')),
                _parse_time(request.POST.get('time')),
                request.POST.get('direction', 'in'),
                lat=float(request.POST['lat']) if request.POST.get('lat') else None,
                lng=float(request.POST['lng']) if request.POST.get('lng') else None,
                description=request.POST.get('description', ''),
                created_by=request.user)
            audit(request.user, 'add', 'Punch')
            messages.success(request, 'رویداد تردد ثبت شد.')
        except (ValueError, TypeError) as exc:
            messages.error(request, f'ورودی نامعتبر: {exc}')
    return redirect('attendance:punches')


def Employee_qs():
    from apps.org.models import Employee
    return Employee.objects.all()


# ---------- گزارش‌های فردی ----------

@require_perm('attendance', 'view')
def monthly(request, jyear=None, jmonth=None):
    today = jdatetime.date.today()
    jyear = int(jyear or today.year)
    jmonth = int(jmonth or today.month)
    employee = _employee(request)
    target = employee
    if request.GET.get('employee_id') and has_perm(request.user, 'reports', 'report'):
        target = scoped_employees(request.user, 'reports', Employee_qs()).filter(
            pk=request.GET.get('employee_id')).first() or employee
    summary = monthly_summary(target, jyear, jmonth) if target else None
    return render(request, 'attendance/monthly.html', {
        'summary': summary, 'jyear': jyear, 'jmonth': jmonth,
        'employees': scoped_employees(request.user, 'reports', Employee_qs())
        if has_perm(request.user, 'reports', 'report') else [],
    })


@require_perm('attendance', 'view')
def leaves(request):
    employee = _employee(request)
    rows = []
    if employee:
        year = jdatetime.date.today().year
        for lt in LeaveType.objects.filter(is_active=True):
            balance = LeaveBalance.objects.filter(
                employee=employee, leave_type=lt, jyear=year).first()
            granted = float(balance.granted) if balance else float(
                lt.limit_per_year or 0)
            used = float(balance.used) if balance else 0.0
            rows.append({'leave_type': lt, 'granted': granted, 'used': used,
                         'remaining': granted - used})
    return render(request, 'attendance/leaves.html', {
        'rows': rows, 'year': jdatetime.date.today().year,
        'requests': Request.objects.filter(
            employee=employee, kind__in=Request.LEAVE_KINDS)[:50]
        if employee else [],
    })


@require_perm('reports', 'view')
def overtime(request):
    jyear = int(request.GET.get('year') or jdatetime.date.today().year)
    jmonth = int(request.GET.get('month') or jdatetime.date.today().month)
    rows = []
    for emp in scoped_employees(request.user, 'reports', Employee_qs()):
        s = monthly_summary(emp, jyear, jmonth)
        if s['overtime_minutes']:
            rows.append({'employee': emp,
                         'overtime_minutes': s['overtime_minutes']})
    return render(request, 'attendance/overtime.html', {
        'rows': rows, 'year': jyear, 'month': jmonth,
        'constants': OvertimeConstant.objects.all(),
        'can_manage': has_perm(request.user, 'attendance', 'manage'),
    })


@require_perm('attendance', 'manage')
def overtime_constant_add(request):
    if request.method == 'POST':
        OvertimeConstant.objects.get_or_create(
            key=request.POST.get('key', '').strip(),
            defaults={'title': request.POST.get('title', ''),
                      'value': float(request.POST.get('value') or 0)})
        messages.success(request, 'ثبت شد.')
    return redirect('attendance:overtime')


@require_perm('attendance', 'view')
def rewards(request):
    employee = _employee(request)
    qs = RewardPenalty.objects.select_related('employee')
    if not has_perm(request.user, 'attendance', 'report'):
        qs = qs.filter(employee=employee)
    return render(request, 'attendance/rewards.html', {
        'rows': qs[:200],
        'can_manage': has_perm(request.user, 'attendance', 'manage'),
    })


@require_perm('attendance', 'manage')
def reward_add(request):
    if request.method == 'POST':
        RewardPenalty.objects.create(
            employee_id=request.POST.get('employee_id'),
            kind=request.POST.get('kind', 'reward'),
            date=_parse_date(request.POST.get('date')) or gregorian.date.today(),
            title=request.POST.get('title', ''),
            amount=int(float(request.POST.get('amount') or 0)))
        audit(request.user, 'add', 'RewardPenalty')
        messages.success(request, 'ثبت شد.')
    return redirect('attendance:rewards')


# ---------- تنظیمات ----------

@require_perm('attendance', 'manage')
def policy(request):
    p = AttendancePolicy.get()
    if request.method == 'POST':
        p.hours_per_day = float(request.POST.get('hours_per_day') or 8)
        p.show_days = int(request.POST.get('show_days') or 38)
        p.last_day_for_request = int(request.POST.get('last_day_for_request') or 16)
        p.max_request_age_days = int(request.POST.get('max_request_age_days') or 0)
        p.record_fix_daily_limit = int(
            request.POST.get('record_fix_daily_limit') or 1)
        p.record_fix_monthly_limit = int(
            request.POST.get('record_fix_monthly_limit') or 3)
        p.valid_late_early = request.POST.get('valid_late_early') == '1'
        p.allow_hourly_free = request.POST.get('allow_hourly_free') == '1'
        p.overtime_holiday_rate = float(request.POST.get('overtime_holiday_rate')
                                        or 1.4)
        p.overtime_night_rate = float(request.POST.get('overtime_night_rate')
                                      or 1.2)
        p.save()
        audit(request.user, 'change', 'AttendancePolicy')
        messages.success(request, 'سیاست‌ها ذخیره شد.')
        return redirect('attendance:policy')
    return render(request, 'attendance/policy.html', {'policy': p})


@require_perm('attendance', 'manage')
def holidays(request):
    if request.method == 'POST':
        try:
            Holiday.objects.get_or_create(
                date=_parse_date(request.POST.get('date')),
                defaults={'title': request.POST.get('title', ''),
                          'kind': request.POST.get('kind', 'national')})
            messages.success(request, 'تعطیلی ثبت شد.')
        except (ValueError, TypeError) as exc:
            messages.error(request, str(exc))
        return redirect('attendance:holidays')
    return render(request, 'attendance/holidays.html', {
        'rows': Holiday.objects.all()[:400]})


@require_perm('attendance', 'manage')
def shifts(request):
    if request.method == 'POST':
        days = [int(d) for d in request.POST.getlist('work_days')]
        Shift.objects.create(
            name=request.POST.get('name', ''),
            start_time=_parse_time(request.POST.get('start_time'))
            or gregorian.time(8, 0),
            end_time=_parse_time(request.POST.get('end_time'))
            or gregorian.time(16, 0),
            work_days=days or [0, 1, 2, 3, 4],
            grace_minutes=int(request.POST.get('grace_minutes') or 15))
        messages.success(request, 'شیفت ثبت شد.')
        return redirect('attendance:shifts')
    return render(request, 'attendance/shifts.html', {
        'shifts': Shift.objects.all(),
        'assignments': ShiftAssignment.objects.select_related(
            'employee', 'shift')[:100],
        'employees': Employee_qs().filter(is_active=True),
    })


@require_perm('attendance', 'manage')
def shift_assign(request):
    if request.method == 'POST':
        ShiftAssignment.objects.create(
            employee_id=request.POST.get('employee_id'),
            shift_id=request.POST.get('shift_id'),
            from_date=_parse_date(request.POST.get('from_date'))
            or gregorian.date.today())
        messages.success(request, 'انتساب شیفت ثبت شد.')
    return redirect('attendance:shifts')


@require_perm('attendance', 'view')
def geo(request):
    return render(request, 'attendance/geo.html', {
        'areas': GeoArea.objects.all(),
        'can_manage': has_perm(request.user, 'attendance', 'manage'),
    })


@require_perm('attendance', 'manage')
def geo_add(request):
    if request.method == 'POST':
        GeoArea.objects.create(
            name=request.POST.get('name', ''),
            lat=float(request.POST.get('lat') or 0),
            lng=float(request.POST.get('lng') or 0),
            radius_m=int(request.POST.get('radius_m') or 100))
        messages.success(request, 'محدوده ثبت شد.')
    return redirect('attendance:geo')


@require_perm('requests', 'manage')
def deputy(request):
    if request.method == 'POST':
        Deputy.objects.create(
            employee_id=request.POST.get('employee_id'),
            for_employee_id=request.POST.get('for_employee_id'),
            from_date=_parse_date(request.POST.get('from_date'))
            or gregorian.date.today(),
            to_date=_parse_date(request.POST.get('to_date'))
            or gregorian.date.today())
        messages.success(request, 'جانشین ثبت شد.')
        return redirect('attendance:deputy')
    return render(request, 'attendance/deputy.html', {
        'deputies': Deputy.objects.select_related('employee', 'for_employee'),
        'employees': Employee_qs().filter(is_active=True),
    })


@require_perm('attendance', 'manage')
def leave_transfer(request):
    """انتقال مانده مرخصی سال قبل به سال تازه (TransferRemainedLeave)."""
    if request.method == 'POST':
        from .services import transfer_leave_balances
        from_year = int(request.POST.get('from_year') or 0)
        to_year = int(request.POST.get('to_year') or 0)
        if not (from_year and to_year):
            messages.error(request, 'سال مبدأ و مقصد الزامی است.')
        else:
            result = transfer_leave_balances(from_year, to_year)
            audit(request.user, 'leave_transfer', detail=str(result))
            messages.success(
                request,
                f"انتقال انجام شد: {result['transferred']} تازه، "
                f"{result['updated']} به‌روزرسانی.")
        return redirect('attendance:leaves')
    return render(request, 'attendance/leave_transfer.html', {
        'from_year': jdatetime.date.today().year - 1,
        'to_year': jdatetime.date.today().year,
    })


@require_perm('attendance', 'manage')
def exit_type_list(request):
    """مدیریت انواع خروج (ExitType)."""
    from .models import ExitType
    if request.method == 'POST':
        ExitType.objects.get_or_create(
            code=request.POST.get('code', '').strip(),
            defaults={'name': request.POST.get('name', '').strip()})
        messages.success(request, 'نوع خروج ثبت شد.')
        return redirect('attendance:exit_types')
    return render(request, 'attendance/exit_types.html', {
        'rows': ExitType.objects.all()})
