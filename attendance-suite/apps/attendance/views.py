"""حضور و غیاب: داشبورد، درخواست‌ها، تردد، گزارش‌ها، تنظیمات."""
import datetime as gregorian

import jdatetime
from django.contrib import messages
from django.http import JsonResponse
from django.utils import timezone
from django.shortcuts import get_object_or_404, redirect, render
from django.utils.http import url_has_allowed_host_and_scheme
from django.views.decorators.http import require_GET

from apps.core.services import audit, has_perm, require_perm, scoped_employees

from .models import (AttendanceCalendar, AttendancePolicy, CalcParameter,
                     DailyRecord, Deputy, GeoArea, Holiday, LeaveBalance,
                     LeaveType, OvertimeConstant, Punch, RamadanRule, Request,
                     RewardPenalty, ScheduleRule, Shift, ShiftAssignment)
from .services import (approve_request, can_approve, create_request,
                       monthly_summary, parse_jalali, register_punch,
                       reject_request, undo_request)


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
        allowed = scoped_employees(request.user, 'requests', Employee_qs())
        qs = qs.filter(status=Request.Status.PENDING, employee__in=allowed)
    elif tab == 'all' and has_perm(request.user, 'requests', 'report'):
        allowed = scoped_employees(request.user, 'requests', Employee_qs())
        qs = qs.filter(employee__in=allowed)
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
    is_owner = bool(employee and req.employee_id == employee.pk)
    within_scope = scoped_employees(
        request.user, 'requests', Employee_qs()
    ).filter(pk=req.employee_id).exists()
    can_report = has_perm(request.user, 'requests', 'report') and within_scope
    can_decide = can_approve(request.user, req)
    if not (is_owner or can_report or can_decide):
        return render(request, 'core/forbidden.html', status=403)
    return render(request, 'attendance/request_detail.html', {
        'req': req,
        'can_decide': can_decide,
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
            is_owner = bool(request.user.employee_id
                            and req.employee_id == request.user.employee_id)
            if not (is_owner or can_approve(request.user, req)):
                raise ValueError('شما مجاز به لغو این درخواست نیستید.')
            undo_request(req, request.user)
            messages.success(request, 'درخواست لغو (ابطال) شد.')
        audit(request.user, f'request_{action}', 'Request', req.pk)
    except ValueError as exc:
        messages.error(request, str(exc))
    return redirect('attendance:request_detail', pk=pk)


# ---------- تردد ----------

@require_perm('attendance', 'view')
def punches(request):
    allowed_employees = scoped_employees(
        request.user, 'attendance', Employee_qs())
    qs = Punch.objects.select_related('employee', 'device', 'area').filter(
        employee__in=allowed_employees)
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
    allowed_employees = scoped_employees(
        request.user, 'attendance', Employee_qs())
    qs = RewardPenalty.objects.select_related('employee').filter(
        employee__in=allowed_employees)
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
        action = request.POST.get('action', 'add_holiday')
        if action == 'add_calendar':
            name = request.POST.get('calendar_name', '').strip()
            if not name or len(name) > 120:
                messages.error(request, 'نام تقویم الزامی و حداکثر ۱۲۰ نویسه است.')
            else:
                calendar = AttendanceCalendar.objects.create(name=name)
                audit(request.user, 'add', 'AttendanceCalendar', calendar.pk,
                      detail=name)
                messages.success(request, 'تقویم ثبت شد.')
            return redirect('attendance:holidays')
        if action == 'edit_calendar':
            calendar = get_object_or_404(
                AttendanceCalendar, pk=request.POST.get('calendar_id'))
            name = request.POST.get('calendar_name', '').strip()
            if not name or len(name) > 120:
                messages.error(request, 'نام تقویم الزامی و حداکثر ۱۲۰ نویسه است.')
            else:
                calendar.name = name
                calendar.save(update_fields=['name'])
                audit(request.user, 'change', 'AttendanceCalendar', calendar.pk,
                      detail=name)
                messages.success(request, 'تقویم ویرایش شد.')
            return redirect('attendance:holidays')
        if action == 'delete_calendar':
            calendar = get_object_or_404(
                AttendanceCalendar, pk=request.POST.get('calendar_id'))
            audit(request.user, 'delete', 'AttendanceCalendar', calendar.pk,
                  detail=calendar.name)
            calendar.delete()
            messages.success(request, 'تقویم حذف شد.')
            return redirect('attendance:holidays')
        try:
            date = _parse_date(request.POST.get('date'))
            title = request.POST.get('title', '').strip()
            kind = request.POST.get('kind', Holiday.Kind.NATIONAL)
            if date is None:
                raise ValueError('تاریخ تعطیلی الزامی است.')
            if not title or len(title) > 160:
                raise ValueError('عنوان تعطیلی الزامی است.')
            if kind not in Holiday.Kind.values:
                raise ValueError('نوع تعطیلی معتبر نیست.')
            holiday, created = Holiday.objects.get_or_create(
                date=date,
                defaults={'title': title, 'kind': kind})
            if not created:
                messages.info(request, 'برای این تاریخ قبلاً تعطیلی ثبت شده است.')
            else:
                audit(request.user, 'add', 'Holiday', holiday.pk, detail=title)
                messages.success(request, 'تعطیلی ثبت شد.')
        except (ValueError, TypeError) as exc:
            messages.error(request, str(exc))
        return redirect('attendance:holidays')
    return render(request, 'attendance/holidays.html', {
        'rows': Holiday.objects.all()[:400],
        'calendars': AttendanceCalendar.objects.all(),
    })


@require_perm('attendance', 'manage')
def shifts(request):
    allowed_employees = scoped_employees(
        request.user, 'attendance', Employee_qs().filter(is_active=True))
    if request.method == 'POST':
        name = request.POST.get('name', '').strip()
        try:
            days = [int(day) for day in request.POST.getlist('work_days')]
            start_time = _parse_time(request.POST.get('start_time'))
            end_time = _parse_time(request.POST.get('end_time'))
            grace = int(request.POST.get('grace_minutes') or 15)
            if any(day not in range(7) for day in days):
                raise ValueError
            if not 0 <= grace <= 1440:
                raise ValueError
        except (TypeError, ValueError):
            messages.error(request, 'زمان یا روزهای کاری معتبر نیست.')
            return redirect('attendance:shifts')
        if not name or len(name) > 120 or not start_time or not end_time:
            messages.error(request, 'نام و ساعت شروع و پایان الزامی است.')
        else:
            shift = Shift.objects.create(
                name=name, start_time=start_time, end_time=end_time,
                work_days=days or [0, 1, 2, 3, 4], grace_minutes=grace)
            audit(request.user, 'add', 'Shift', shift.pk, detail=name)
            messages.success(request, 'شیفت ثبت شد.')
        return redirect('attendance:shifts')
    return render(request, 'attendance/shifts.html', {
        'shifts': Shift.objects.all(),
        'assignments': ShiftAssignment.objects.filter(
            employee__in=allowed_employees).select_related(
                'employee', 'shift')[:100],
        'employees': allowed_employees,
    })


@require_perm('attendance', 'manage')
def shift_assign(request):
    from apps.org.models import Employee
    allowed = scoped_employees(
        request.user, 'attendance', Employee.objects.filter(is_active=True))
    if request.method == 'POST':
        employee = allowed.filter(pk=request.POST.get('employee_id')).first()
        shift = Shift.objects.filter(
            pk=request.POST.get('shift_id'), is_active=True).first()
        try:
            from_date = _parse_date(request.POST.get('from_date'))
            to_date = _parse_date(request.POST.get('to_date')) or None
        except (ValueError, TypeError):
            messages.error(request, 'تاریخ انتساب معتبر نیست.')
            return redirect('attendance:shifts')
        if not employee or not shift:
            messages.error(request, 'پرسنل یا شیفت معتبر در دامنهٔ مجاز انتخاب کنید.')
        elif not from_date or (to_date and to_date < from_date):
            messages.error(request, 'بازهٔ تاریخ انتساب معتبر نیست.')
        else:
            ShiftAssignment.objects.create(
                employee=employee, shift=shift,
                from_date=from_date, to_date=to_date)
            audit(request.user, 'add', 'ShiftAssignment',
                  detail=f'employee={employee.pk}; shift={shift.pk}')
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
    employees = scoped_employees(
        request.user, 'requests', Employee_qs().filter(is_active=True))
    if request.method == 'POST':
        substitute = employees.filter(pk=request.POST.get('employee_id')).first()
        principal = employees.filter(pk=request.POST.get('for_employee_id')).first()
        try:
            from_date = _parse_date(request.POST.get('from_date'))
            to_date = _parse_date(request.POST.get('to_date'))
        except (ValueError, TypeError):
            from_date = to_date = None
        if not substitute or not principal:
            messages.error(request, 'جانشین و پرسنل اصلی باید در دامنهٔ مجاز باشند.')
        elif substitute.pk == principal.pk:
            messages.error(request, 'پرسنل نمی‌تواند جانشین خودش باشد.')
        elif not from_date or not to_date or to_date < from_date:
            messages.error(request, 'بازهٔ تاریخ جانشینی معتبر نیست.')
        else:
            deputy_obj = Deputy.objects.create(
                employee=substitute, for_employee=principal,
                from_date=from_date, to_date=to_date)
            audit(request.user, 'add', 'Deputy', deputy_obj.pk)
            messages.success(request, 'جانشین ثبت شد.')
        return redirect('attendance:deputy')
    return render(request, 'attendance/deputy.html', {
        'deputies': Deputy.objects.filter(
            for_employee__in=employees
        ).select_related('employee', 'for_employee'),
        'employees': employees,
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


@require_perm('attendance', 'view')
def total_control(request):
    """کنترل جامع روزانه (TotalControl) — جدول روزانه با تأخیر/زود/غیاب/مرخصی/مأموریت/اضافه‌کار."""
    from apps.org.models import Employee
    from .models import DailyRecord
    import jdatetime
    day = request.GET.get('day', '')
    if not day:
        day = jdatetime.date.today().strftime('%Y-%m-%d')
    emps = scoped_employees(
        request.user, 'attendance',
        Employee.objects.filter(is_active=True).select_related('department'),
    )[:300]
    rows = []
    for e in emps:
        d = DailyRecord.objects.filter(employee=e, date=day).first()
        rows.append({'emp': e, 'd': d})
    return render(request, 'attendance/total_control.html', {
        'rows': rows, 'day': day})


@require_perm('attendance', 'add')
@require_GET
def insert_record_people(request):
    """Scoped, paged JSON search for the T5-style personnel picker."""
    from django.db.models import Q
    from apps.org.models import Employee

    query = request.GET.get('q', '').strip()[:100]
    try:
        page = max(1, min(int(request.GET.get('page', '1')), 10000))
    except (TypeError, ValueError):
        page = 1
    page_size = 100
    employees = scoped_employees(
        request.user, 'attendance', Employee.objects.filter(is_active=True)
    ).select_related('department').order_by('badge_no')
    if query:
        employees = employees.filter(
            Q(badge_no__icontains=query)
            | Q(first_name__icontains=query)
            | Q(last_name__icontains=query)
            | Q(department__name__icontains=query)
        )

    start = (page - 1) * page_size
    rows = list(employees[start:start + page_size + 1])
    has_more = len(rows) > page_size
    rows = rows[:page_size]
    return JsonResponse({
        'results': [{
            'badgeNo': row.badge_no,
            'name': f'{row.first_name} {row.last_name}'.strip(),
            'department': row.department.name if row.department else '',
        } for row in rows],
        'has_more': has_more,
        'page': page,
    })


@require_perm('attendance', 'add')
def insert_record(request):
    """ثبت دستی تردد با قراردادهای اصلی T5ِ تاریخ/ساعت/CheckType.

    مدل محلی فقط ورود/خروج را به‌عنوان Punch پشتیبانی می‌کند؛ کدهای LeaveType
    عمداً تا پیاده‌سازی مدل/محاسبهٔ متناظر پذیرفته نمی‌شوند.
    """
    from apps.org.models import Employee

    allowed_employees = scoped_employees(
        request.user, 'attendance', Employee.objects.filter(is_active=True)
    ).order_by('badge_no')

    def safe_referrer(value):
        value = (value or '').strip()
        if value and url_has_allowed_host_and_scheme(
                value, allowed_hosts={request.get_host()},
                require_https=request.is_secure()):
            return value
        return ''

    def render_form(*, selected_badges=None, frozen=False, frozen_badge='',
                    date_value=None, time_value=None, check_type='0',
                    referrer_url=''):
        selected_badges = [str(value) for value in (selected_badges or [])]
        employees = list(allowed_employees[:100])
        visible_badges = {employee.badge_no for employee in employees}
        # Keep a frozen or previously selected, scoped employee visible even when
        # their badge is outside the first page of the picker.
        if selected_badges:
            extras = allowed_employees.filter(
                badge_no__in=selected_badges).exclude(badge_no__in=visible_badges)
            employees.extend(extras)
        return render(request, 'attendance/insert_record.html', {
            'employees': employees,
            'selected_badge_numbers': selected_badges,
            'is_freeze': frozen,
            'frozen_badge_number': str(frozen_badge or ''),
            'date_value': (jdatetime.date.today().strftime('%Y/%m/%d')
                            if date_value is None else date_value),
            'time_value': '08:00' if time_value is None else time_value,
            'check_type': str(check_type),
            'referrer_url': safe_referrer(referrer_url),
        })

    if request.method == 'POST':
        frozen = request.POST.get('isFreeze', '').lower() in {'true', '1', 'on'}
        frozen_badge = request.POST.get('badgeNumber', '').strip()
        badges = request.POST.getlist('selectedBadgeNumbers')
        if frozen:
            badges = [frozen_badge] if frozen_badge else []

        # `dateHijri`/`timeOfDay`/`checkType` are the T5 field names. The legacy
        # names remain accepted for existing callers and tests.
        date_text = request.POST.get('dateHijri')
        legacy_date = not bool(date_text)
        date_text = (date_text or request.POST.get('date', '')).strip()
        time_text = request.POST.get('timeOfDay')
        if time_text is None:
            time_text = request.POST.get('time')
        if time_text is None:
            time_text = '08:00'
        time_text = time_text.strip()
        raw_type = request.POST.get('checkType')
        if raw_type is None:
            raw_type = request.POST.get('check_type', 'in')
        description = request.POST.get('description', '').strip()
        referrer_url = safe_referrer(request.POST.get('referrerURL'))

        try:
            if not date_text:
                raise ValueError('تاریخ نامعتبر است.')
            if legacy_date:
                # The old local form accepted ISO Gregorian dates as well as
                # Jalali dates; retain that compatibility during the rebuild.
                try:
                    date_value = gregorian.date.fromisoformat(date_text)
                except ValueError:
                    date_value = _parse_date(date_text)
            else:
                date_value = _parse_date(date_text)
            time_value = _parse_time(time_text)
            if time_value is None:
                raise ValueError('ساعت نامعتبر است.')
        except (TypeError, ValueError, OverflowError):
            messages.error(request, 'تاریخ یا ساعت نامعتبر است؛ مقدار را بررسی کنید.')
            return render_form(
                selected_badges=badges, frozen=frozen,
                frozen_badge=frozen_badge, date_value=date_text,
                time_value=time_text, check_type=raw_type,
                referrer_url=referrer_url)

        try:
            if str(raw_type).strip().lower() in {'in', 'out'}:
                check_type = 0 if str(raw_type).strip().lower() == 'in' else 1
            else:
                check_type = int(raw_type)
            if check_type not in (0, 1):
                raise ValueError
        except (TypeError, ValueError, OverflowError):
            messages.error(request, 'CheckType نامعتبر است؛ فعلاً فقط ۰ (ورود) و ۱ (خروج) پشتیبانی می‌شود.')
            return render_form(
                selected_badges=badges, frozen=frozen,
                frozen_badge=frozen_badge, date_value=date_text,
                time_value=time_text, check_type=raw_type,
                referrer_url=referrer_url)

        if not badges:
            messages.error(request, 'پرسنلی انتخاب نشده است.')
            return render_form(
                selected_badges=badges, frozen=frozen,
                frozen_badge=frozen_badge, date_value=date_text,
                time_value=time_text, check_type=check_type,
                referrer_url=referrer_url)

        employees_by_badge = {
            employee.badge_no: employee
            for employee in allowed_employees.filter(badge_no__in=set(badges))
        }
        inserted = 0
        rejected = 0
        direction = (Punch.Direction.IN if check_type == 0
                     else Punch.Direction.OUT)
        for badge in badges:
            employee = employees_by_badge.get(str(badge))
            if employee is None:
                rejected += 1
                continue
            punch = Punch.objects.create(
                employee=employee,
                date=date_value,
                time=time_value,
                direction=direction,
                check_type=check_type,
                source=Punch.Source.WEB,
                description=description,
                created_by=request.user,
            )
            audit(request.user, 'add', 'Punch', punch.pk,
                  detail=f'T5 InsertRecord; badge={employee.badge_no}; '
                         f'checkType={check_type}; {date_value} {time_value}')
            inserted += 1

        if inserted:
            messages.success(request, f'{inserted} تردد ثبت شد.')
        if rejected:
            messages.warning(request, f'{rejected} شناسه خارج از دامنهٔ مجاز نادیده گرفته شد.')
        if not inserted and not rejected:
            messages.error(request, 'هیچ رکوردی درج نشد.')

        return render_form(
            selected_badges=badges, frozen=frozen,
            frozen_badge=frozen_badge, date_value=date_text,
            time_value=time_text, check_type=check_type,
            referrer_url=referrer_url)

    raw_badge = request.GET.get('badgeNumber', '-1').strip()
    frozen = raw_badge not in {'', '-1'}
    selected = [raw_badge] if frozen and allowed_employees.filter(
        badge_no=raw_badge).exists() else []
    if frozen and not selected:
        frozen = False
        messages.error(request, 'پرسنل انتخاب‌شده در دامنهٔ دسترسی شما نیست.')
    return render_form(
        selected_badges=selected, frozen=frozen,
        frozen_badge=selected[0] if selected else '',
        date_value=jdatetime.date.today().strftime('%Y/%m/%d'),
        time_value='08:00', check_type='0',
        referrer_url=request.GET.get('referrerURL', ''))


@require_perm('attendance', 'change')
def edit_late_early(request):
    """اصلاح تأخیر/خروج زودهنگام (EditLateEarly) — با سهمیه روزانه/ماهانه."""
    from apps.org.models import Employee
    from .models import DailyRecord
    allowed_employees = scoped_employees(
        request.user, 'attendance', Employee.objects.filter(is_active=True))
    if request.method == 'POST':
        emp_id = request.POST.get('employee_id')
        date = request.POST.get('date', '')
        reason = request.POST.get('reason', '')
        obj = DailyRecord.objects.filter(
            employee__in=allowed_employees, employee_id=emp_id, date=date
        ).first()
        if obj:
            obj.late_minutes = int(request.POST.get('late_minutes', 0) or 0)
            obj.early_minutes = int(request.POST.get('early_minutes', 0) or 0)
            obj.save()
            audit(request.user, 'change', 'DailyRecord', obj.pk, detail=reason)
            messages.success(request, 'اصلاح ثبت شد.')
        else:
            messages.error(request, 'رکورد روز موردنظر یافت نشد — ابتدا تردد ثبت کنید.')
        return redirect('attendance:edit_late_early')
    recent = DailyRecord.objects.filter(employee__in=allowed_employees).select_related(
        'employee').order_by('-date')[:100]
    return render(request, 'attendance/edit_late_early.html', {
        'employees': allowed_employees[:400],
        'recent': recent})


@require_perm('attendance', 'manage')
def daily_leave_batch(request):
    """ثبت گروهی مرخصی/مأموریت روزانه با محاسبه روز کاری و ثبت مانده."""
    from django.db import transaction
    from decimal import Decimal
    from apps.org.models import Employee
    from .models import Holiday, LeaveType, Request, Shift
    from .services import _change_balance, scheduled_minutes, shift_for_employee

    if request.method == 'POST':
        badges = request.POST.getlist('selectedBadgeNumbers')
        operation = request.POST.get('operation', Request.Kind.LEAVE_DAILY)
        date_from_text = request.POST.get('date_from', '')
        date_to_text = request.POST.get('date_to', '') or date_from_text
        description = request.POST.get('description', '').strip()
        leave_type = LeaveType.objects.filter(
            pk=request.POST.get('leave_type_id') or 0, is_active=True
        ).first() if operation == Request.Kind.LEAVE_DAILY else None
        try:
            date_from = _parse_date(date_from_text)
            date_to = _parse_date(date_to_text)
            if operation not in (Request.Kind.LEAVE_DAILY, Request.Kind.MISSION_DAILY):
                raise ValueError('نوع ثبت گروهی نامعتبر است.')
            if not badges:
                raise ValueError('حداقل یک پرسنل را انتخاب کنید.')
            if not date_from or not date_to or date_to < date_from:
                raise ValueError('بازهٔ تاریخ نامعتبر است.')
            if jdatetime.date.fromgregorian(date=date_from).year != \
                    jdatetime.date.fromgregorian(date=date_to).year:
                raise ValueError('بازهٔ ثبت گروهی نباید از مرز سال جلالی عبور کند.')
            if operation == Request.Kind.LEAVE_DAILY and leave_type is None:
                raise ValueError('نوع مرخصی معتبر را انتخاب کنید.')

            allowed_employees = scoped_employees(
                request.user, 'attendance', Employee.objects.filter(is_active=True))
            employees = list(allowed_employees.filter(badge_no__in=badges))
            if not employees:
                raise ValueError('پرسنل فعال و معتبری از انتخاب‌ها یافت نشد.')
            fallback_shift = Shift.objects.filter(is_active=True).order_by('id').first()
            day_count_by_employee = {}
            for emp in employees:
                day = date_from
                count = 0
                while day <= date_to:
                    if not Holiday.objects.filter(date=day).exists():
                        shift = shift_for_employee(emp, day) or fallback_shift
                        if shift and scheduled_minutes(shift, day) > 0:
                            count += 1
                    day += gregorian.timedelta(days=1)
                if count:
                    day_count_by_employee[emp.pk] = count
            if not day_count_by_employee:
                raise ValueError('در بازهٔ انتخاب‌شده روز کاری معتبری وجود ندارد.')

            with transaction.atomic():
                for emp in employees:
                    days = day_count_by_employee.get(emp.pk)
                    if not days:
                        continue
                    req = Request.objects.create(
                        employee=emp,
                        kind=operation,
                        leave_type=leave_type,
                        from_date=date_from,
                        to_date=date_to,
                        days=Decimal(days),
                        description=description[:300],
                        status=Request.Status.APPROVED,
                        decided_by=request.user,
                        decided_at=timezone.now(),
                    )
                    if req.is_leave and leave_type.needs_balance:
                        _change_balance(req, Decimal(days))
                count = len(day_count_by_employee)
            audit(request.user, 'add', 'Request',
                  detail=f'batch {operation}; people={count}; {date_from}:{date_to}')
            messages.success(request, f'ثبت گروهی برای {count} نفر انجام شد.')
        except (ValueError, TypeError) as exc:
            messages.error(request, str(exc))
        return redirect('attendance:daily_leave_batch')

    return render(request, 'attendance/daily_leave_batch.html', {
        'employees': scoped_employees(
            request.user, 'attendance', Employee.objects.filter(is_active=True))[:400],
        'leave_types': LeaveType.objects.filter(is_active=True),
        'operations': [
            (Request.Kind.LEAVE_DAILY, 'مرخصی روزانه'),
            (Request.Kind.MISSION_DAILY, 'مأموریت روزانه'),
        ],
    })


@require_perm('attendance', 'view')
def calc_parameters(request):
    """پارامترهای محاسباتی با فرمول (Parameters)."""
    from .models import CalcParameter
    if request.method == 'POST' and has_perm(request.user, 'attendance', 'manage'):
        CalcParameter.objects.create(
            name=request.POST.get('name', '').strip(),
            symbol=request.POST.get('symbol', '').strip(),
            formula=request.POST.get('formula', '').strip(),
            is_sum=request.POST.get('is_sum') == 'on',
            sum_function=request.POST.get('sum_function') or 'SUM')
        audit(request.user, 'add', 'CalcParameter')
        return redirect('attendance:calc_parameters')
    return render(request, 'attendance/calc_parameters.html', {
        'params': CalcParameter.objects.all(),
        'can_manage': has_perm(request.user, 'attendance', 'manage')})


@require_perm('attendance', 'view')
def rules(request):
    """قواعد زمان‌بندی (Rule/AssignRule)."""
    from .models import ScheduleRule
    if request.method == 'POST' and has_perm(request.user, 'attendance', 'manage'):
        name = request.POST.get('name', '').strip()
        try:
            priority = int(request.POST.get('priority', 100) or 100)
        except (TypeError, ValueError):
            priority = -1
        if not name or len(name) > 120 or not 0 <= priority <= 32767:
            messages.error(request, 'نام و اولویت قانون معتبر نیست.')
        else:
            rule = ScheduleRule.objects.create(name=name, priority=priority)
            audit(request.user, 'add', 'ScheduleRule', rule.pk, detail=name)
            messages.success(request, 'قانون ثبت شد.')
        return redirect('attendance:rules')
    return render(request, 'attendance/rules.html', {
        'rules': ScheduleRule.objects.all(),
        'can_manage': has_perm(request.user, 'attendance', 'manage')})


@require_perm('attendance', 'view')
def ramadan(request):
    """تنظیمات ماه رمضان (Ramezan)."""
    from .models import RamadanRule
    if request.method == 'POST' and has_perm(request.user, 'attendance', 'manage'):
        shift = Shift.objects.filter(
            pk=request.POST.get('shift_id'), is_active=True).first()
        try:
            from_date = _parse_date(request.POST.get('from_date'))
            to_date = _parse_date(request.POST.get('to_date'))
            reduced = int(request.POST.get('reduced_minutes', 0) or 0)
        except (TypeError, ValueError):
            from_date = to_date = None
            reduced = -1
        if not shift or not from_date or not to_date or to_date < from_date \
                or not 0 <= reduced <= 1440:
            messages.error(request, 'اطلاعات قاعدهٔ رمضان معتبر نیست.')
        else:
            rule = RamadanRule.objects.create(
                shift=shift, from_date=from_date, to_date=to_date,
                reduced_minutes=reduced)
            audit(request.user, 'add', 'RamadanRule', rule.pk)
            messages.success(request, 'قاعدهٔ رمضان ثبت شد.')
        return redirect('attendance:ramadan')
    return render(request, 'attendance/ramadan.html', {
        'rules': RamadanRule.objects.select_related('shift'),
        'shifts': Shift.objects.filter(is_active=True),
        'can_manage': has_perm(request.user, 'attendance', 'manage')})
