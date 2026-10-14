"""سرویس‌های تردد: محاسبه وضعیت روز، خلاصه ماهانه و ثبت رکورد.

هر ورودی (دستی/دستگاه/درون‌ریزی) از register_record می‌گذرد تا
منطق تأخیر و اضافه‌کاری یکسان بماند — الگوی هاب مرکزی مانند اعلان‌ها.
"""
from __future__ import annotations

import jdatetime

from .models import AttendanceRecord, WorkSchedule

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
