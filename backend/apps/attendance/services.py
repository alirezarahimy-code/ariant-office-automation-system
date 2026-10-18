"""سرویس‌های تردد: محاسبه وضعیت روز، خلاصه ماهانه و ثبت رکورد.

هر ورودی (دستی/دستگاه/درون‌ریزی) از register_record می‌گذرد تا
منطق تأخیر و اضافه‌کاری یکسان بماند — الگوی هاب مرکزی مانند اعلان‌ها.
"""
from __future__ import annotations

import jdatetime
from django.db import transaction
from django.utils import timezone

from .models import (AttendancePunch, AttendancePolicy, AttendanceRecord,
                     AttendanceRequest, Deputy, GeoArea, LeaveBalance,
                     LeaveType, OvertimeConstant, RewardPenalty, WorkSchedule)

DEFAULT_SCHEDULE_NAME = 'ساعات اداری'


def default_schedule() -> WorkSchedule:
    """برنامه کاری پیش‌فرض (۸ تا ۱۶، شنبه تا چهارشنبه، ۱۵ دقیقه مهلت)."""
    schedule, _ = WorkSchedule.objects.get_or_create(
        name=DEFAULT_SCHEDULE_NAME,
        defaults={'start_time': __import__('datetime').time(8, 0),
                  'end_time': __import__('datetime').time(16, 0),
                  'work_days': [0, 1, 2, 3, 4],
                  'grace_minutes': 15})
    return schedule


def _minutes_diff(later, earlier) -> int:
    return (later.hour * 60 + later.minute) - (earlier.hour * 60 + earlier.minute)


def day_metrics(record: AttendanceRecord | None,
                schedule: WorkSchedule | None = None) -> dict:
    """وضعیت یک روز: حالت، دقیقه تأخیر (پس از مهلت) و دقیقه اضافه‌کاری."""
    schedule = schedule or default_schedule()
    if record is None or record.check_in is None:
        return {'state': 'absent', 'late_minutes': 0, 'overtime_minutes': 0,
                'worked_minutes': 0}
    late = _minutes_diff(record.check_in, schedule.start_time)
    grace = schedule.grace_minutes or 0
    late_minutes = max(0, late - grace)
    overtime_minutes = 0
    worked = 0
    if record.check_out is not None:
        overtime_minutes = max(0, _minutes_diff(record.check_out, schedule.end_time))
        worked = max(0, _minutes_diff(record.check_out, record.check_in))
        state = 'late' if late_minutes else 'ontime'
    else:
        state = 'incomplete'
    return {'state': state, 'late_minutes': late_minutes,
            'overtime_minutes': overtime_minutes, 'worked_minutes': worked}


STATE_LABELS = {
    'ontime': 'به‌موقع',
    'late': 'با تأخیر',
    'incomplete': 'خروج ثبت نشده',
    'absent': 'غایب',
    'holiday': 'تعطیل',
}


def monthly_summary(user, jyear: int, jmonth: int,
                    schedule: WorkSchedule | None = None) -> dict:
    """خلاصه ماهانه + ردیف‌های روزانه برای نمایش جدول ماه."""
    schedule = schedule or default_schedule()
    # ذخیره میلادی است؛ بازه شمسی را به مرز میلادی تبدیل می‌کنیم (الگوی دبیرخانه)
    start_g = jdatetime.date(jyear, jmonth, 1).togregorian()
    if jmonth == 12:
        end_g = jdatetime.date(jyear + 1, 1, 1).togregorian()
    else:
        end_g = jdatetime.date(jyear, jmonth + 1, 1).togregorian()
    records = {(r.date.day): r for r in
               AttendanceRecord.objects.filter(
                   user=user, date__gte=start_g, date__lt=end_g)}
    rows = []
    present = late_days = absent = overtime_total = worked_total = 0
    day = 1
    while True:
        try:
            jdate = jdatetime.date(jyear, jmonth, day)
        except ValueError:
            break
        is_workday = schedule.is_workday(jdate)
        record = records.get(day)
        if not is_workday:
            metrics = {'state': 'holiday', 'late_minutes': 0,
                       'overtime_minutes': 0, 'worked_minutes': 0}
        else:
            metrics = day_metrics(record, schedule)
            if metrics['state'] == 'absent':
                absent += 1
            else:
                present += 1
                if metrics['state'] == 'late':
                    late_days += 1
                overtime_total += metrics['overtime_minutes']
                worked_total += metrics['worked_minutes']
        rows.append({'jdate': jdate, 'is_workday': is_workday,
                     'record': record, **metrics})
        day += 1
    return {'rows': rows, 'present_days': present, 'late_days': late_days,
            'absent_days': absent, 'overtime_minutes': overtime_total,
            'worked_minutes': worked_total, 'schedule': schedule,
            'jyear': jyear, 'jmonth': jmonth, 'user': user}


def register_record(*, user, date, check_in=None, check_out=None,
                    source: str = AttendanceRecord.Source.MANUAL,
                    note: str = '') -> AttendanceRecord:
    """ثبت/به‌روزرسانی تردد یک روز (idempotent برای همان کاربر و تاریخ)."""
    record, _ = AttendanceRecord.objects.update_or_create(
        user=user, date=date,
        defaults={'check_in': check_in, 'check_out': check_out,
                  'source': source, 'note': note})
    return record


# ─────────────────────────────────────────────────────────────
# لایه حرفه‌ای — آینه‌ی رفتار سامانه مرجع (MyTime3)
# ─────────────────────────────────────────────────────────────
def policy() -> AttendancePolicy:
    return AttendancePolicy.get()


def register_punch(*, user, date, time, direction: str = 'in',
                   source: str = 'manual', description: str = '',
                   lat=None, lng=None, created_by=None) -> AttendancePunch:
    """ثبت رویداد تردد — ورود/خروج روزانه را هم همگام می‌کند (first-in/last-out)."""
    area = detect_area(lat, lng) if lat is not None and lng is not None else None
    punch = AttendancePunch.objects.create(
        user=user, date=date, time=time, direction=direction, source=source,
        description=description[:300], lat=lat, lng=lng, area=area,
        created_by=created_by)
    record, _ = AttendanceRecord.objects.get_or_create(user=user, date=date)
    changed = False
    if direction == 'in' and (record.check_in is None or time < record.check_in):
        record.check_in = time
        changed = True
    if direction == 'out' and (record.check_out is None or time > record.check_out):
        record.check_out = time
        changed = True
    if changed:
        record.save(update_fields=['check_in', 'check_out', 'updated_at'])
    return punch


def detect_area(lat, lng) -> GeoArea | None:
    """تشخیص محدوده مجاز از روی مختصات (geo-fence با شعاع متر)."""
    if lat is None or lng is None:
        return None
    for area in GeoArea.objects.filter(is_active=True):
        if area_distance_m(float(lat), float(lng),
                           float(area.lat), float(area.lng)) <= area.radius_m:
            return area
    return None


def area_distance_m(lat1: float, lng1: float, lat2: float, lng2: float) -> float:
    """فاصله دو نقطه جغرافیایی به متر (haversine)."""
    from math import asin, cos, radians, sin, sqrt
    rlat1, rlat2 = radians(lat1), radians(lat2)
    dlat = rlat2 - rlat1
    dlng = radians(lng2 - lng1)
    a = sin(dlat / 2) ** 2 + cos(rlat1) * cos(rlat2) * sin(dlng / 2) ** 2
    return 2 * 6371000 * asin(sqrt(a))


def create_request(*, kind: str, user, description: str = '',
                   leave_type: LeaveType | None = None,
                   for_date=None, from_date=None, to_date=None,
                   from_time=None, to_time=None,
                   hours=0, days=0) -> AttendanceRequest:
    """ثبت درخواست — با کنترل سهمیه‌ی اصلاح رکورد و مهلت زمانی (سیاست)."""
    p = policy()
    if kind == AttendanceRequest.Kind.RECORD_FIX:
        today = jdatetime.date.today()
        daily = AttendanceRequest.objects.filter(
            user=user, kind=kind, created_at__date=today.togregorian()).count()
        if daily >= p.record_fix_daily_limit:
            raise ValueError('سهمیه درخواست اصلاح رکورد امروز تمام شده است.')
        month_count = AttendanceRequest.objects.filter(
            user=user, kind=kind,
            created_at__date__gte=jdatetime.date(today.year, today.month, 1).togregorian(),
            created_at__date__lte=today.togregorian()).count()
        if month_count >= p.record_fix_monthly_limit:
            raise ValueError('سهمیه درخواست اصلاح رکورد این ماه تمام شده است.')
    if p.max_request_age_days and for_date:
        age = (jdatetime.date.today() - for_date).days
        if age > p.max_request_age_days:
            raise ValueError('قدمت درخواست بیش از محدودیت مجاز است.')
    if p.last_day_for_request and jdatetime.date.today().day > p.last_day_for_request             and kind in (AttendanceRequest.Kind.LEAVE_DAILY,
                         AttendanceRequest.Kind.LEAVE_HOURLY):
        raise ValueError(
            f'ثبت مرخصی فقط تا روز {p.last_day_for_request} ماه مجاز است.')
    request = AttendanceRequest.objects.create(
        kind=kind, user=user, leave_type=leave_type, for_date=for_date,
        from_date=from_date, to_date=to_date, from_time=from_time,
        to_time=to_time, hours=hours or 0, days=days or 0,
        description=description[:300])
    for manager in approval_targets(user):
        from apps.core.services import send_notification
        send_notification(manager, f'درخواست تازه از {user}',
                          f'{request.get_kind_display()}: {description[:150]}',
                          f'/attendance/requests/{request.pk}/')
    return request


def approval_targets(user) -> list:
    """تأییدکنندگان: مدیر (سمت بالادست) + جانشینان فعال او."""
    from django.contrib.auth import get_user_model
    User = get_user_model()
    targets: list = list(User.objects.filter(
        is_active=True, is_superuser=True).exclude(pk=user.pk))
    manager = getattr(user, 'manager', None)
    if manager is None:
        profile = getattr(user, 'profile', None)
        manager = getattr(profile, 'manager', None) if profile is not None else None
    if manager is not None and manager.pk != user.pk and manager not in targets:
        targets.append(manager)
    today = jdatetime.date.today().togregorian()
    for deputy in Deputy.objects.filter(
            for_user__in=[u.pk for u in targets], is_active=True,
            from_date__lte=today, to_date__gte=today).select_related('user'):
        if deputy.user.pk != user.pk and deputy.user not in targets:
            targets.append(deputy.user)
    return targets


def can_approve(user, request: AttendanceRequest) -> bool:
    from django.contrib.auth import get_user_model
    User = get_user_model()
    if user.is_superuser or user.has_perm('attendance.manage_attendance'):
        return True
    if user.pk == request.user.pk:
        return False
    if user in approval_targets(request.user):
        return True
    from django.db.models import Q
    today = jdatetime.date.today().togregorian()
    managers = User.objects.filter(is_active=True).filter(
        Q(is_superuser=True) | Q(user_permissions__codename='manage_attendance')
    ).distinct()
    return Deputy.objects.filter(
        user=user, is_active=True, for_user__in=managers,
        from_date__lte=today, to_date__gte=today).exists()


def approve_request(request: AttendanceRequest, approver,
                    note: str = '') -> AttendanceRequest:
    if request.status != AttendanceRequest.Status.PENDING:
        raise ValueError('فقط درخواست در انتظار تأیید قابل بررسی است.')
    if not can_approve(approver, request):
        raise ValueError('شما تأییدکننده این درخواست نیستید.')
    with transaction.atomic():
        request.status = AttendanceRequest.Status.APPROVED
        request.decided_by = approver
        request.decided_at = timezone.now()
        request.decision_note = note[:300]
        request.save(update_fields=['status', 'decided_by', 'decided_at',
                                    'decision_note', 'updated_at'])
        if request.is_leave and request.leave_type and request.leave_type.needs_balance:
            days = request.days or 0
            if request.kind == AttendanceRequest.Kind.LEAVE_HOURLY and not days:
                hours = request.hours or 0
                p = policy()
                days = float(hours) / float(p.hours_per_day or 8)
            if days:
                year = (request.from_date or request.for_date
                        or jdatetime.date.today()).year
                balance, _ = LeaveBalance.objects.get_or_create(
                    user=request.user, leave_type=request.leave_type, jyear=year)
                balance.used += days
                balance.save(update_fields=['used', 'updated_at'])
    from apps.core.services import send_notification
    send_notification(request.user, f'درخواست شما تأیید شد',
                      f'{request.get_kind_display()} — {note[:150]}',
                      f'/attendance/requests/{request.pk}/')
    return request


def reject_request(request: AttendanceRequest, approver,
                   note: str = '') -> AttendanceRequest:
    if request.status != AttendanceRequest.Status.PENDING:
        raise ValueError('فقط درخواست در انتظار تأیید قابل بررسی است.')
    if not can_approve(approver, request):
        raise ValueError('شما تأییدکننده این درخواست نیستید.')
    request.status = AttendanceRequest.Status.REJECTED
    request.decided_by = approver
    request.decided_at = timezone.now()
    request.decision_note = note[:300]
    request.save(update_fields=['status', 'decided_by', 'decided_at',
                                'decision_note', 'updated_at'])
    from apps.core.services import send_notification
    send_notification(request.user, 'درخواست شما رد شد',
                      f'{request.get_kind_display()} — {note[:150]}',
                      f'/attendance/requests/{request.pk}/')
    return request


def undo_request(request: AttendanceRequest, by_user) -> AttendanceRequest:
    """ابطال درخواست (Undo) — بازگردانی مانده مرخصی در صورت تأییدشده بودن."""
    if request.status in (AttendanceRequest.Status.CANCELLED,):
        raise ValueError('این درخواست قبلاً لغو شده است.')
    with transaction.atomic():
        if request.status == AttendanceRequest.Status.APPROVED and request.is_leave                 and request.leave_type and request.leave_type.needs_balance:
            days = request.days or 0
            if request.kind == AttendanceRequest.Kind.LEAVE_HOURLY and not days:
                p = policy()
                days = float(request.hours or 0) / float(p.hours_per_day or 8)
            if days:
                year = (request.from_date or request.for_date
                        or jdatetime.date.today()).year
                balance = LeaveBalance.objects.filter(
                    user=request.user, leave_type=request.leave_type,
                    jyear=year).first()
                if balance is not None:
                    balance.used = max(float(balance.used) - float(days), 0)
                    balance.save(update_fields=['used', 'updated_at'])
        request.status = AttendanceRequest.Status.CANCELLED
        request.decided_by = by_user
        request.decided_at = timezone.now()
        request.save(update_fields=['status', 'decided_by', 'decided_at', 'updated_at'])
    return request


def leave_balance_row(user, leave_type: LeaveType, year: int) -> dict:
    balance = LeaveBalance.objects.filter(
        user=user, leave_type=leave_type, jyear=year).first()
    granted = float(balance.granted) if balance else float(leave_type.limit_per_year or 0)
    used = float(balance.used) if balance else 0.0
    return {'leave_type': leave_type, 'granted': granted, 'used': used,
            'remaining': granted - used}


def overtime_report(jyear: int, jmonth: int) -> list[dict]:
    """گزارش اضافه‌کاری ماه — از خلاصه‌های روزانه."""
    from django.contrib.auth import get_user_model
    User = get_user_model()
    rows = []
    for user in User.objects.filter(is_active=True):
        summary = monthly_summary(user, jyear, jmonth)
        if summary['overtime_minutes']:
            rows.append({'user': user,
                         'overtime_minutes': summary['overtime_minutes'],
                         'overtime_hours': round(summary['overtime_minutes'] / 60, 2)})
    rows.sort(key=lambda r: -r['overtime_minutes'])
    return rows


def leaves_report(jyear: int, jmonth: int) -> list[dict]:
    start_g = jdatetime.date(jyear, jmonth, 1).togregorian()
    end_g = (jdatetime.date(jyear + 1, 1, 1) if jmonth == 12
             else jdatetime.date(jyear, jmonth + 1, 1)).togregorian()
    return list(AttendanceRequest.objects.filter(
        kind__in=AttendanceRequest.LEAVE_KINDS,
        status=AttendanceRequest.Status.APPROVED,
        created_at__date__gte=start_g, created_at__date__lte=end_g
    ).select_related('user', 'leave_type'))


def location_report(jyear: int, jmonth: int) -> list[dict]:
    """گزارش موقعیت — رویدادهای تردد با مختصات و وضعیت داخل/خارج محدوده."""
    start_g = jdatetime.date(jyear, jmonth, 1).togregorian()
    end_g = (jdatetime.date(jyear + 1, 1, 1) if jmonth == 12
             else jdatetime.date(jyear, jmonth + 1, 1)).togregorian()
    rows = []
    for punch in AttendancePunch.objects.filter(
            date__gte=start_g, date__lt=end_g).exclude(lat__isnull=True
            ).select_related('user', 'area'):
        rows.append({'punch': punch, 'inside': punch.area_id is not None})
    return rows


def monthly_report(user, jyear: int, jmonth: int) -> dict:
    """گزارش ماهانه‌ی غنی — خلاصه + درخواست‌های تأییدشده ماه."""
    summary = monthly_summary(user, jyear, jmonth)
    start_g = jdatetime.date(jyear, jmonth, 1).togregorian()
    end_g = (jdatetime.date(jyear + 1, 1, 1) if jmonth == 12
             else jdatetime.date(jyear, jmonth + 1, 1)).togregorian()
    summary['requests'] = list(AttendanceRequest.objects.filter(
        user=user, status=AttendanceRequest.Status.APPROVED,
        created_at__date__gte=start_g, created_at__date__lte=end_g))
    return summary
