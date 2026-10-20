"""مدیریت جدول زمانی و شیفت موقت، جدا از صفحهٔ شیفت‌های پایه."""
import datetime as gregorian
import re
from decimal import Decimal, InvalidOperation
from urllib.parse import urlencode

import jdatetime
from django.contrib import messages
from django.db import transaction
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse

from apps.core.services import audit, require_perm, scoped_employees
from apps.org.models import Employee

from .models import (
    AttendanceCalendar, CalendarHoliday, DailyRecord, Punch,
    ScheduleDefinition, ScheduleRule, TemporarySchedule, ValidLeave,
)
from .services import parse_jalali


TIME_FIELDS = (
    'from_time', 'to_time', 'start_from', 'end_from', 'start_to', 'end_to',
    'ramadan_from_time', 'ramadan_to_time',
)
DURATION_FIELDS = {
    'late_minutes': 'late',
    'early_minutes': 'early',
    'suspend_minutes': 'suspend',
    'interval_before_minutes': 'interval_before',
}


def _clock(value):
    value = (value or '').strip()
    if not value:
        return None
    return gregorian.time.fromisoformat(value)


def _duration(value, default):
    value = (value or '').strip()
    if not value:
        return default
    parts = value.split(':')
    if len(parts) != 2:
        raise ValueError('مدت باید به شکل ساعت:دقیقه وارد شود.')
    hours, minutes = map(int, parts)
    if hours < 0 or not 0 <= minutes < 60 or hours > 24 or (hours == 24 and minutes):
        raise ValueError('مدت واردشده معتبر نیست.')
    return hours * 60 + minutes


def _duration_text(value):
    return f'{value // 60:02d}:{value % 60:02d}'


def _schedule_form_values(schedule=None):
    values = {}
    for field in TIME_FIELDS:
        value = getattr(schedule, field, None) if schedule else None
        values[field] = value.strftime('%H:%M') if value else ''
    for field, input_name in DURATION_FIELDS.items():
        values[input_name] = _duration_text(getattr(schedule, field, 0)) if schedule else {
            'late': '00:15', 'early': '00:15',
            'suspend': '00:00', 'interval_before': '00:00',
        }[input_name]
    values.update({
        'name': schedule.name if schedule else '',
        'symbol': schedule.symbol if schedule else '',
        'work_days': str(schedule.work_days) if schedule else '1',
        'work_minutes': str(schedule.work_minutes) if schedule else '480',
        'color': schedule.color if schedule else '#00ff00',
        'rest_time': schedule.rest_time if schedule else False,
        'before_i': schedule.before_i if schedule else True,
        'after_i': schedule.after_i if schedule else True,
        'rule_id': schedule.rule_id if schedule else '',
        'calendar_id': schedule.calendar_id if schedule else '',
        'rest_times': schedule.rest_times if schedule else [],
    })
    return values


@require_perm('attendance', 'manage')
def schedule_definitions(request, pk=None):
    """جدول زمانی T5: مشخصات ساعت، پنجره ورود/خروج، استراحت و قانون."""
    schedule = get_object_or_404(ScheduleDefinition, pk=pk) if pk else None
    values = _schedule_form_values(schedule)
    errors = []
    if request.method == 'POST':
        if request.POST.get('action') == 'delete' and schedule:
            label = schedule.name
            schedule.delete()
            audit(request.user, 'delete', 'ScheduleDefinition', pk, detail=label)
            messages.success(request, 'جدول زمانی حذف شد.')
            return redirect('attendance:schedule_definitions')

        values = request.POST.dict()
        rest_from = request.POST.getlist('rest_from')
        rest_to = request.POST.getlist('rest_to')
        rest_times = []
        try:
            name = request.POST.get('name', '').strip()
            symbol = request.POST.get('symbol', '').strip()
            if not name or len(name) > 120:
                errors.append('نام جدول زمانی الزامی است و حداکثر ۱۲۰ نویسه دارد.')
            if not symbol or len(symbol) > 8:
                errors.append('نماد جدول زمانی الزامی است و حداکثر ۸ نویسه دارد.')
            clocks = {field: _clock(request.POST.get(field)) for field in TIME_FIELDS}
            if not clocks['from_time'] or not clocks['to_time']:
                errors.append('ساعت شروع و پایان کار الزامی است.')
            durations = {
                field: _duration(request.POST.get(input_name), 0)
                for field, input_name in DURATION_FIELDS.items()
            }
            work_days = Decimal(request.POST.get('work_days', '1'))
            work_minutes = Decimal(request.POST.get('work_minutes', '480'))
            if work_days < 0 or work_days > 31 or work_minutes < 0 or work_minutes > 10080:
                errors.append('مقدار روزهای کاری یا دقایق کاری خارج از محدوده است.')
            if len(rest_from) != len(rest_to) or len(rest_from) > 12:
                errors.append('بازه‌های استراحت معتبر نیستند.')
            else:
                for start, end in zip(rest_from, rest_to):
                    if not start and not end:
                        continue
                    if not start or not end:
                        raise ValueError('برای هر بازه استراحت، ساعت شروع و پایان را وارد کنید.')
                    rest_times.append([_clock(start).strftime('%H:%M'), _clock(end).strftime('%H:%M')])
            color = request.POST.get('color', '#00ff00').strip()
            if not re.fullmatch(r'#[0-9a-fA-F]{6}', color):
                errors.append('رنگ جدول زمانی معتبر نیست.')
            rule_id = request.POST.get('rule_id') or None
            calendar_id = request.POST.get('calendar_id') or None
            rule = ScheduleRule.objects.filter(pk=rule_id, is_active=True).first() if rule_id else None
            calendar = AttendanceCalendar.objects.filter(pk=calendar_id).first() if calendar_id else None
            if rule_id and rule is None:
                errors.append('قانون انتخاب‌شده معتبر نیست.')
            if calendar_id and calendar is None:
                errors.append('تقویم انتخاب‌شده معتبر نیست.')
        except (ValueError, InvalidOperation, TypeError) as exc:
            errors.append(str(exc) or 'مقادیر فرم معتبر نیستند.')
            clocks = {}
            durations = {}
            work_days = Decimal('1')
            work_minutes = Decimal('480')
            rest_times = []
            color = '#00ff00'
            rule = calendar = None

        if not errors:
            is_new = schedule is None
            schedule = schedule or ScheduleDefinition()
            for field, value in clocks.items():
                setattr(schedule, field, value)
            for field, value in durations.items():
                setattr(schedule, field, value)
            schedule.name = name
            schedule.symbol = symbol
            schedule.work_days = work_days
            schedule.work_minutes = work_minutes
            schedule.color = color
            schedule.rest_time = request.POST.get('rest_time') == 'on'
            schedule.before_i = request.POST.get('before_i') == 'on'
            schedule.after_i = request.POST.get('after_i') == 'on'
            schedule.rest_times = rest_times
            schedule.rule = rule
            schedule.calendar = calendar
            schedule.save()
            audit(request.user, 'add' if is_new else 'change',
                  'ScheduleDefinition', schedule.pk, detail=schedule.name)
            messages.success(request, 'جدول زمانی ذخیره شد.')
            return redirect('attendance:schedule_definitions')

    rows = ScheduleDefinition.objects.select_related('rule', 'calendar').all()
    if request.method == 'POST':
        # Preserve the submitted repeatable rest interval rows after validation errors.
        values['rest_times'] = list(zip(request.POST.getlist('rest_from'),
                                        request.POST.getlist('rest_to')))
    return render(request, 'attendance/schedule_definitions.html', {
        'schedule': schedule,
        'rows': rows,
        'values': values,
        'errors': errors,
        'rules': ScheduleRule.objects.filter(is_active=True),
        'calendars': AttendanceCalendar.objects.all(),
    })


@require_perm('attendance', 'manage')
def calendar_holidays(request, calendar_id):
    calendar = get_object_or_404(AttendanceCalendar, pk=calendar_id)
    if request.method == 'POST':
        if request.POST.get('action') == 'delete':
            holiday = get_object_or_404(
                CalendarHoliday, pk=request.POST.get('holiday_id'), calendar=calendar)
            audit(request.user, 'delete', 'CalendarHoliday', holiday.pk,
                  detail=f'{calendar.name}: {holiday.date}')
            holiday.delete()
            messages.success(request, 'تعطیلی از تقویم حذف شد.')
        else:
            try:
                date = parse_jalali(request.POST.get('date', ''))
                description = request.POST.get('description', '').strip()
                if len(description) > 200:
                    raise ValueError('شرح تعطیلی حداکثر ۲۰۰ نویسه است.')
                holiday, created = CalendarHoliday.objects.get_or_create(
                    calendar=calendar, date=date,
                    defaults={'description': description})
                if not created:
                    holiday.description = description
                    holiday.save(update_fields=['description'])
                audit(request.user, 'add' if created else 'change',
                      'CalendarHoliday', holiday.pk,
                      detail=f'{calendar.name}: {date}; {description}')
                messages.success(request, 'تعطیلی تقویم ذخیره شد.')
            except (ValueError, TypeError) as exc:
                messages.error(request, str(exc) or 'تاریخ معتبر نیست.')
    return render(request, 'attendance/calendar_holidays.html', {
        'calendar': calendar,
        'holidays': calendar.holidays.all(),
    })


def _permit_duration(value):
    parts = (value or '').strip().split(':')
    if len(parts) != 2:
        raise ValueError('مدت مجوز را به شکل ساعت:دقیقه وارد کنید.')
    hours, minutes = map(int, parts)
    if hours < 0 or hours > 8760 or not 0 <= minutes < 60:
        raise ValueError('مدت مجوز معتبر نیست.')
    total = hours * 60 + minutes
    if total <= 0:
        raise ValueError('مدت مجوز باید بیشتر از صفر باشد.')
    return total


def _permit_duration_text(value):
    return f'{value // 60:02d}:{value % 60:02d}'


@require_perm('attendance', 'manage')
def valid_leaves(request, pk=None):
    """CRUD مجوز مرخصی معتبر با انتخاب پرسنل و بازهٔ جلالی."""
    allowed = scoped_employees(
        request.user, 'attendance', Employee.objects.filter(is_active=True))
    item = (get_object_or_404(ValidLeave, pk=pk, employee__in=allowed)
            if pk is not None else None)
    values = {
        'employee_id': item.employee_id if item else '',
        'from_date': _jalali_text(item.from_date) if item else '',
        'to_date': _jalali_text(item.to_date) if item else '',
        'valid_hours': _permit_duration_text(item.valid_minutes) if item else '',
        'description': item.description if item else '',
    }
    errors = []
    if request.method == 'POST':
        if request.POST.get('action') == 'delete' and item:
            audit(request.user, 'delete', 'ValidLeave', item.pk,
                  detail=f'{item.employee.badge_no}: {item.description}')
            item.delete()
            messages.success(request, 'مجوز مرخصی حذف شد.')
            return redirect('attendance:valid_leaves')
        values = request.POST.dict()
        employee = allowed.filter(pk=request.POST.get('employee_id')).first()
        try:
            from_date = parse_jalali(request.POST.get('from_date', ''))
            to_date = parse_jalali(request.POST.get('to_date', ''))
            minutes = _permit_duration(request.POST.get('valid_hours', ''))
            description = request.POST.get('description', '').strip()
        except (ValueError, TypeError) as exc:
            errors.append(str(exc) or 'اطلاعات مجوز معتبر نیست.')
            from_date = to_date = None
            minutes = 0
            description = request.POST.get('description', '').strip()
        if not employee:
            errors.append('پرسنل را از دامنهٔ مجاز انتخاب کنید.')
        if from_date and to_date and to_date < from_date:
            errors.append('تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.')
        if len(description) > 300:
            errors.append('شرح حداکثر ۳۰۰ نویسه است.')
        if not errors:
            was_new = item is None
            item = item or ValidLeave()
            item.employee = employee
            item.from_date = from_date
            item.to_date = to_date
            item.valid_minutes = minutes
            item.description = description
            item.save()
            audit(request.user, 'add' if was_new else 'change',
                  'ValidLeave', item.pk,
                  detail=f'{employee.badge_no}; {from_date}..{to_date}; {minutes}')
            messages.success(request, 'مجوز مرخصی ذخیره شد.')
            return redirect('attendance:valid_leaves')
        values['valid_hours'] = request.POST.get('valid_hours', '')
    rows = ValidLeave.objects.filter(employee__in=allowed).select_related('employee')
    return render(request, 'attendance/valid_leaves.html', {
        'item': item, 'rows': rows, 'employees': allowed,
        'values': values, 'errors': errors,
    })


def _jalali_text(date):
    return jdatetime.date.fromgregorian(date=date).strftime('%Y/%m/%d')


def _selected_range(request, allowed):
    selected_ids = request.GET.getlist('employee_id')
    employees = list(allowed.filter(pk__in=selected_ids)
                     .select_related('department').order_by('pk')[:100])
    today = jdatetime.date.today()
    default_start = jdatetime.date(today.year, today.month, 1).togregorian()
    default_end = today.togregorian()
    try:
        start = parse_jalali(request.GET.get('start_date') or _jalali_text(default_start))
        end = parse_jalali(request.GET.get('end_date') or _jalali_text(default_end))
    except (ValueError, TypeError):
        start, end = default_start, default_end
    return employees, start, end


def _range_redirect(employee_ids, start_text, end_text):
    params = [('employee_id', str(pk)) for pk in employee_ids]
    params += [('start_date', start_text), ('end_date', end_text)]
    return redirect(f"{reverse('attendance:temp_schedules')}?{urlencode(params)}")


@require_perm('attendance', 'manage')
def temp_schedules(request):
    """مدیریت گروهی شیفت موقت و مشاهدهٔ رکوردهای همان بازه."""
    allowed = scoped_employees(
        request.user, 'attendance', Employee.objects.filter(is_active=True))
    if request.method == 'POST' and request.POST.get('action') == 'show':
        selected_tokens = request.POST.getlist('employee_id')
        employees = list(allowed.filter(
            pk__in=selected_tokens).order_by('pk')
            .values_list('pk', flat=True)[:101])
        try:
            start = parse_jalali(request.POST.get('start_date', ''))
            end = parse_jalali(request.POST.get('end_date', ''))
        except (ValueError, TypeError):
            messages.error(request, 'بازهٔ تاریخ معتبر نیست.')
            return redirect('attendance:temp_schedules')
        if (not employees or len(employees) > 100
                or len(employees) != len(set(selected_tokens))
                or end < start or (end - start).days > 61):
            messages.error(request, '۱ تا ۱۰۰ پرسنل و بازهٔ حداکثر ۶۲روزه انتخاب کنید.')
            return redirect('attendance:temp_schedules')
        return _range_redirect(employees, request.POST['start_date'],
                               request.POST['end_date'])

    if request.method == 'POST' and request.POST.get('action') == 'save_day':
        employee_ids = request.POST.getlist('employee_id')
        if len(employee_ids) > 100:
            messages.error(request, 'حداکثر ۱۰۰ پرسنل را هم‌زمان انتخاب کنید.')
            return redirect('attendance:temp_schedules')
        employees = list(allowed.filter(pk__in=employee_ids))
        try:
            day = parse_jalali(request.POST.get('day', ''))
            start_bound = parse_jalali(request.POST.get('start_date') or request.POST.get('day', ''))
            end_bound = parse_jalali(request.POST.get('end_date') or request.POST.get('day', ''))
        except (ValueError, TypeError):
            messages.error(request, 'تاریخ انتخاب‌شده معتبر نیست.')
            return redirect('attendance:temp_schedules')
        if end_bound < start_bound or (end_bound - start_bound).days > 61 \
                or not start_bound <= day <= end_bound:
            messages.error(request, 'تاریخ باید داخل بازهٔ حداکثر ۶۲روزه باشد.')
            return redirect('attendance:temp_schedules')
        schedule_token = request.POST.get('schedule_id', '')
        if schedule_token == '':
            messages.error(request, 'ابتدا جدول زمانی را انتخاب کنید.')
            return redirect('attendance:temp_schedules')
        schedule = None
        if schedule_token != '-1':
            schedule = ScheduleDefinition.objects.filter(
                pk=schedule_token, is_active=True).first()
        if not employees or len(employees) != len(set(employee_ids)):
            messages.error(request, 'فهرست پرسنل معتبر یا در دامنهٔ دسترسی نیست.')
            return redirect('attendance:temp_schedules')
        if schedule_token not in ('', '-1') and schedule is None:
            messages.error(request, 'جدول زمانی انتخاب‌شده معتبر نیست.')
            return redirect('attendance:temp_schedules')
        with transaction.atomic():
            if schedule is None:
                TemporarySchedule.objects.filter(
                    employee__in=employees, date=day).delete()
                action = 'remove'
            else:
                for employee in employees:
                    TemporarySchedule.objects.update_or_create(
                        employee=employee, date=day,
                        defaults={'schedule': schedule})
                action = 'assign'
            audit(request.user, f'temp_schedule_{action}', 'TemporarySchedule',
                  detail=f'date={day}; employees={len(employees)}; schedule={schedule.pk if schedule else "-"}')
        messages.success(request, 'تغییر شیفت موقت ذخیره شد.')
        return _range_redirect(
            [employee.pk for employee in employees],
            request.POST.get('start_date', _jalali_text(day)),
            request.POST.get('end_date', _jalali_text(day)))

    employees, start, end = _selected_range(request, allowed)
    days = (end - start).days + 1
    if days < 1 or days > 62:
        employees = []
        start = end = jdatetime.date.today().togregorian()
        days = 1
        messages.error(request, 'بازهٔ نمایش باید حداکثر ۶۲ روز باشد.')
    employee_ids = [employee.pk for employee in employees]
    schedules = list(ScheduleDefinition.objects.filter(is_active=True))
    if employees:
        records = DailyRecord.objects.filter(
            employee__in=employees, date__gte=start, date__lte=end
        ).select_related('employee')
        record_map = {(record.employee_id, record.date): record for record in records}
        punches = Punch.objects.filter(
            employee__in=employees, date__gte=start, date__lte=end
        ).order_by('time')
        punch_map = {}
        for punch in punches:
            punch_map.setdefault((punch.employee_id, punch.date), []).append(punch.time)
        overrides = TemporarySchedule.objects.filter(
            employee__in=employees, date__gte=start, date__lte=end
        ).select_related('schedule', 'employee')
        override_map = {(item.employee_id, item.date): item for item in overrides}
    else:
        record_map, punch_map, override_map = {}, {}, {}

    record_rows = []
    for offset in range(days):
        day = start + gregorian.timedelta(days=offset)
        for employee in employees:
            override = override_map.get((employee.pk, day))
            record_rows.append({
                'employee': employee,
                'day': day,
                'jday': _jalali_text(day),
                'record': record_map.get((employee.pk, day)),
                'punches': (punch_map.get((employee.pk, day), [])[:6]
                            + [''] * (6 - len(punch_map.get((employee.pk, day), [])[:6]))),
                'override': override,
            })

    group_rows = []
    for offset in range(days):
        day = start + gregorian.timedelta(days=offset)
        day_overrides = [override_map.get((employee.pk, day)) for employee in employees]
        selected_names = sorted({item.schedule.name for item in day_overrides
                                 if item and item.schedule_id})
        group_rows.append({
            'day': day,
            'jday': _jalali_text(day),
            'weekday': jdatetime.date.fromgregorian(date=day).strftime('%A'),
            'schedule_names': selected_names,
            'has_override': any(day_overrides),
        })

    return render(request, 'attendance/temp_schedules.html', {
        'employees': allowed.order_by('last_name', 'first_name'),
        'selected_employees': employees,
        'employee_ids': employee_ids,
        'start_date': _jalali_text(start),
        'end_date': _jalali_text(end),
        'schedules': schedules,
        'group_rows': group_rows,
        'record_rows': record_rows,
    })
