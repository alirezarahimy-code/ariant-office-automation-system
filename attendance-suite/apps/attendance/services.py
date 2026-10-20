"""منطق حضور و غیاب: ثبت تردد، محاسبات روز/ماه، درخواست‌ها، مانده."""
from __future__ import annotations

import datetime as gregorian
import math
from decimal import Decimal

import jdatetime
from django.db import transaction
from django.utils import timezone

from .models import (AttendancePolicy, DailyRecord, Deputy, GeoArea,
                     Holiday, LeaveBalance, LeaveType, Punch, Request,
                     Shift, ShiftAssignment, TemporarySchedule,
                     CalendarHoliday)


# ---------- تبدیل تاریخ ----------

def to_jdate(value):
    if value is None:
        return None
    if isinstance(value, jdatetime.date):
        return value
    return jdatetime.date.fromgregorian(date=value)


def parse_jalali(text: str) -> gregorian.date:
    """ورودی '1404/07/30' یا '1404-07-30' → میلادی."""
    text = (text or '').strip().replace('-', '/')
    parts = text.split('/')
    if len(parts) != 3:
        raise ValueError('تاریخ باید به شکل 1404/07/30 باشد.')
    jy, jm, jd = (int(p) for p in parts)
    return jdatetime.date(jy, jm, jd).togregorian()


# ---------- شیفت و محاسبات ----------

def shift_for_employee(employee, date: gregorian.date):
    """Return a date-specific override first, then the current regular shift."""
    temporary = TemporarySchedule.objects.filter(
        employee=employee, date=date, schedule__is_active=True
    ).select_related('schedule').first()
    if temporary:
        return temporary.schedule
    from django.db.models import Q
    assign = ShiftAssignment.objects.filter(
        employee=employee, from_date__lte=date
    ).filter(Q(to_date__isnull=True) | Q(to_date__gte=date)
             ).select_related('shift').order_by('-from_date').first()
    return assign.shift if assign else None


def day_metrics(date: gregorian.date, check_in=None, check_out=None,
                employee=None) -> dict:
    """محاسبات روز: تأخیر/تعجیل/اضافه‌کاری/کارکرد + وضعیت."""
    shift = shift_for_employee(employee, date) if employee else None
    if shift is None:
        shift = Shift.objects.filter(is_active=True).order_by('id').first()
    holiday = Holiday.objects.filter(date=date).exists()
    if shift and getattr(shift, 'calendar_id', None):
        holiday = holiday or CalendarHoliday.objects.filter(
            calendar_id=shift.calendar_id, date=date).exists()
    jwday = jdatetime.date.fromgregorian(date=date).weekday()  # 0=شنبه

    if check_in is None and check_out is None:
        state = DailyRecord.State.HOLIDAY if holiday else DailyRecord.State.ABSENT
        return {'state': state, 'late_minutes': 0, 'early_minutes': 0,
                'overtime_minutes': 0, 'worked_minutes': 0}

    worked = 0
    if check_in and check_out:
        in_dt = gregorian.datetime.combine(date, check_in)
        out_dt = gregorian.datetime.combine(date, check_out)
        if out_dt < in_dt:
            out_dt += gregorian.timedelta(days=1)
        worked = int((out_dt - in_dt).total_seconds() // 60)

    late = early = overtime = 0
    if shift:
        if check_in:
            late = max(0, int((gregorian.datetime.combine(date, check_in)
                               - gregorian.datetime.combine(date, shift.start_time)
                               ).total_seconds() // 60) - shift.grace_minutes)
        if check_out and not holiday:
            early_margin = getattr(shift, 'early_minutes', 0)
            early = max(0, int((gregorian.datetime.combine(date, shift.end_time)
                                - gregorian.datetime.combine(date, check_out)
                                ).total_seconds() // 60) - early_margin)
        scheduled = scheduled_minutes(shift, date)
        overtime = max(0, worked - scheduled) if not holiday else worked
        if holiday:
            late = early = 0

    if holiday:
        state = DailyRecord.State.HOLIDAY
    elif check_in is None or check_out is None:
        state = DailyRecord.State.INCOMPLETE
    elif late > 0:
        state = DailyRecord.State.LATE
    else:
        state = DailyRecord.State.ONTIME

    return {'state': state, 'late_minutes': late, 'early_minutes': early,
            'overtime_minutes': overtime, 'worked_minutes': worked}


def scheduled_minutes(shift, date: gregorian.date) -> int:
    work_days = getattr(shift, 'work_day_indexes', None)
    if work_days is None:
        work_days = getattr(shift, 'work_days', [])
    if work_days and jdatetime.date.fromgregorian(date=date).weekday() not in work_days:
        return 0
    configured_minutes = getattr(shift, 'work_minutes', None)
    if configured_minutes is not None and configured_minutes > 0:
        return int(configured_minutes)
    start = gregorian.datetime.combine(date, shift.start_time)
    end = gregorian.datetime.combine(date, shift.end_time)
    if end <= start:
        end += gregorian.timedelta(days=1)
    return int((end - start).total_seconds() // 60)


def refresh_daily(employee, date: gregorian.date) -> DailyRecord:
    """بازسازی خلاصه روز از روی رویدادها (idempotent)."""
    punches = Punch.objects.filter(employee=employee, date=date).order_by('time')
    check_in = punches.filter(direction=Punch.Direction.IN).first()
    check_out = punches.filter(direction=Punch.Direction.OUT).last()
    m = day_metrics(date,
                    check_in.time if check_in else None,
                    check_out.time if check_out else None,
                    employee=employee)
    obj, _ = DailyRecord.objects.update_or_create(
        employee=employee, date=date,
        defaults={
            'check_in': check_in.time if check_in else None,
            'check_out': check_out.time if check_out else None,
            **m})
    return obj


def register_punch(employee, date, time, direction, source=Punch.Source.WEB,
                   device=None, lat=None, lng=None, area=None,
                   description='', created_by=None) -> Punch:
    if lat is not None and lng is not None and area is None:
        area = detect_area(lat, lng)
    punch = Punch.objects.create(
        employee=employee, date=date, time=time, direction=direction,
        source=source, device=device, lat=lat, lng=lng, area=area,
        description=description[:300], created_by=created_by)
    refresh_daily(employee, date)
    return punch


# ---------- geo ----------

def area_distance_m(lat1, lng1, lat2, lng2) -> float:
    r = 6371000.0
    p1, p2 = math.radians(float(lat1)), math.radians(float(lat2))
    dp = math.radians(float(lat2) - float(lat1))
    dl = math.radians(float(lng2) - float(lng1))
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


def detect_area(lat, lng) -> GeoArea | None:
    for area in GeoArea.objects.filter(is_active=True):
        if area_distance_m(lat, lng, area.lat, area.lng) <= area.radius_m:
            return area
    return None


# ---------- درخواست‌ها ----------

def approval_targets(employee):
    """تأییدکنندگان: مدیر مستقیم + جانشین‌های فعال او + مدیران منابع انسانی."""
    from apps.org.models import Employee
    today = gregorian.date.today()
    targets = []
    if employee.manager_id:
        targets.append(employee.manager)
    deputies = Deputy.objects.filter(
        for_employee__in=[t.pk for t in targets] or [employee.pk],
        is_active=True, from_date__lte=today, to_date__gte=today
    ).select_related('employee')
    targets.extend(d.employee for d in deputies)
    if not targets:
        targets = list(Employee.objects.filter(
            position__is_manager=True, is_active=True)[:3])
    return targets


def can_approve(user, request: Request) -> bool:
    from apps.core.services import has_perm, scoped_employees
    if user.is_superuser:
        return True
    if has_perm(user, 'requests', 'approve'):
        from apps.org.models import Employee
        allowed = scoped_employees(user, 'requests', Employee.objects.all())
        if allowed.filter(pk=request.employee_id).exists():
            return True
    emp = getattr(user, 'employee', None)
    return emp is not None and emp in approval_targets(request.employee)


def create_request(kind, employee, *, leave_type=None, for_date=None,
                   from_date=None, to_date=None, from_time=None, to_time=None,
                   hours=0, days=0, description='') -> Request:
    policy = AttendancePolicy.get()
    cls = Request
    if kind in cls.LEAVE_KINDS:
        limit = int(policy.last_day_for_request)
        today = jdatetime.date.today()
        ref = from_date or to_date or for_date
        if limit and ref and (to_jdate(ref).year, to_jdate(ref).month) == (
                today.year, today.month) and today.day > limit:
            raise ValueError(f'ثبت مرخصی فقط تا روز {limit} ماه مجاز است.')
    if kind == cls.Kind.RECORD_FIX and for_date:
        used = cls.objects.filter(
            employee=employee, kind=cls.Kind.RECORD_FIX, for_date=for_date
        ).exclude(status=cls.Status.CANCELLED).count()
        if used >= policy.record_fix_daily_limit:
            raise ValueError('سهمیه اصلاح رکورد امروز تمام شده است.')
    if policy.max_request_age_days and for_date:
        age = (gregorian.date.today() - for_date).days
        if age > policy.max_request_age_days:
            raise ValueError('قدمت درخواست بیش از حد مجاز است.')
    request = cls.objects.create(
        kind=kind, employee=employee, leave_type=leave_type, for_date=for_date,
        from_date=from_date, to_date=to_date, from_time=from_time,
        to_time=to_time, hours=hours or 0, days=days or 0,
        description=(description or '')[:300])
    from .models import WorkflowRule
    rule = WorkflowRule.objects.filter(kind=kind).first()
    if rule and not rule.needs_approval:
        request.status = cls.Status.APPROVED
        request.save(update_fields=['status'])
    return request


def _leave_days(request: Request) -> Decimal:
    if request.days:
        return Decimal(request.days)
    if request.hours:
        policy = AttendancePolicy.get()
        return (Decimal(request.hours) / policy.hours_per_day).quantize(
            Decimal('0.01'))
    return Decimal(0)


def approve_request(request: Request, by_user, note='') -> Request:
    if request.status != Request.Status.PENDING:
        raise ValueError('فقط درخواست در انتظار تأیید قابل بررسی است.')
    if not can_approve(by_user, request):
        raise ValueError('شما تأییدکننده این درخواست نیستید.')
    with transaction.atomic():
        request.status = Request.Status.APPROVED
        request.decided_by = by_user
        request.decided_at = timezone.now()
        request.decision_note = (note or '')[:300]
        request.save()
        if request.is_leave and request.leave_type and \
                request.leave_type.needs_balance:
            _change_balance(request, +_leave_days(request))
    return request


def reject_request(request: Request, by_user, note='') -> Request:
    if request.status != Request.Status.PENDING:
        raise ValueError('فقط درخواست در انتظار تأیید قابل بررسی است.')
    if not can_approve(by_user, request):
        raise ValueError('شما تأییدکننده این درخواست نیستید.')
    request.status = Request.Status.REJECTED
    request.decided_by = by_user
    request.decided_at = timezone.now()
    request.decision_note = (note or '')[:300]
    request.save()
    return request


def undo_request(request: Request, by_user) -> Request:
    if request.status == Request.Status.CANCELLED:
        raise ValueError('این درخواست قبلاً لغو شده است.')
    with transaction.atomic():
        if request.status == Request.Status.APPROVED and request.is_leave \
                and request.leave_type and request.leave_type.needs_balance:
            _change_balance(request, -_leave_days(request))
        request.status = Request.Status.CANCELLED
        request.decided_by = by_user
        request.decided_at = timezone.now()
        request.save()
    return request


def _change_balance(request: Request, delta: Decimal) -> None:
    jyear = to_jdate(request.from_date or request.for_date).year
    balance, _ = LeaveBalance.objects.get_or_create(
        employee=request.employee, leave_type=request.leave_type, jyear=jyear,
        defaults={'granted': request.leave_type.limit_per_year})
    balance.used = max(Decimal(0), Decimal(balance.used) + delta)
    balance.save(update_fields=['used'])


# ---------- گزارش‌ها ----------

def monthly_summary(employee, jyear: int, jmonth: int) -> dict:
    start = jdatetime.date(jyear, jmonth, 1).togregorian()
    end = (jdatetime.date(jyear + 1, 1, 1) if jmonth == 12
           else jdatetime.date(jyear, jmonth + 1, 1)).togregorian()
    rows = list(DailyRecord.objects.filter(
        employee=employee, date__gte=start, date__lt=end).order_by('date'))
    summary = {
        'rows': rows,
        'present_days': sum(1 for r in rows if r.state not in (
            DailyRecord.State.ABSENT, DailyRecord.State.HOLIDAY)),
        'late_days': sum(1 for r in rows if r.late_minutes > 0),
        'absent_days': sum(1 for r in rows if r.state == DailyRecord.State.ABSENT),
        'overtime_minutes': sum(r.overtime_minutes for r in rows),
        'worked_minutes': sum(r.worked_minutes for r in rows),
        'jyear': jyear, 'jmonth': jmonth, 'employee': employee,
    }
    return summary


# ---------- انتقال مانده مرخصی (TransferRemainedLeave) ----------

def transfer_leave_balances(from_year: int, to_year: int) -> dict:
    """انتقال مانده مرخصی سال قبل به سال تازه (به‌روزرسانی یا ایجاد)."""
    transferred = updated = 0
    for balance in LeaveBalance.objects.filter(jyear=from_year):
        remaining = balance.remaining
        if remaining <= 0:
            continue
        obj, created = LeaveBalance.objects.update_or_create(
            employee=balance.employee, leave_type=balance.leave_type,
            jyear=to_year,
            defaults={'granted': remaining, 'used': 0})
        if created:
            transferred += 1
        else:
            obj.granted = remaining
            obj.save(update_fields=['granted'])
            updated += 1
    return {'transferred': transferred, 'updated': updated}
