"""مدیریت انواع مرخصی/ماموریت بر اساس فرم LeaveType مرجع T5Web."""
from decimal import Decimal, InvalidOperation
import re

from django.contrib import messages
from django.core.exceptions import ValidationError
from django.db import IntegrityError, transaction
from django.db.models.deletion import ProtectedError
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.services import audit, require_perm

from .models import CalcParameter, LeaveType


_COLOR = re.compile(r'^#[0-9a-fA-F]{6}$')
_DIGIT_TRANSLATION = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')
_CHECKBOXES = ('ForNextYearSum', 'Payment', 'GetInHolidays', 'AutoInHolidays')


def _text(value, default=''):
    return str(value if value is not None else default).strip().translate(_DIGIT_TRANSLATION)


def _duration_seconds(value):
    parts = _text(value).split(':')
    if len(parts) not in (2, 3) or any(not p.isdigit() for p in parts):
        raise ValueError('زمان باید به صورت ساعت:دقیقه یا ساعت:دقیقه:ثانیه باشد.')
    hours, minutes = int(parts[0]), int(parts[1])
    seconds = int(parts[2]) if len(parts) == 3 else 0
    if hours > 9999 or minutes > 59 or seconds > 59:
        raise ValueError('مقدار زمان خارج از محدوده است.')
    return hours * 3600 + minutes * 60 + seconds


def _decimal(value, label, default):
    try:
        result = Decimal(_text(value, default).replace(',', '.'))
    except (InvalidOperation, ValueError):
        raise ValueError(f'{label} باید عدد معتبر باشد.')
    if not result.is_finite() or result < 0 or result > Decimal('9999999'):
        raise ValueError(f'{label} خارج از محدوده مجاز است.')
    return result


def _time_text(seconds):
    hours, rem = divmod(int(seconds or 0), 3600)
    minutes, secs = divmod(rem, 60)
    return f'{hours:02d}:{minutes:02d}' + (f':{secs:02d}' if secs else '')


def _form_values(row=None):
    row = row or LeaveType()
    return {
        'leave_type_id': row.pk or -1,
        'LeaveName': row.name,
        'Symbol': row.symbol,
        'LeaveType1': row.leave_kind,
        'IsHourly': row.is_hourly,
        'Color': row.color,
        'StateKey': row.state_key,
        'MaxHoursInADay': _time_text(row.max_hours_in_a_day_seconds),
        'MaxHoursInAMonth': _time_text(row.max_hours_in_a_month_seconds),
        'MaxDailyInYear': row.max_daily_in_year,
        'MaxDailyInMonth': row.max_daily_in_month,
        'EachXHoursADay': _time_text(row.each_x_hours_a_day_seconds),
        'GetInType': row.get_in_type,
        'ParameterID': row.parameter_id,
        'ForNextYear': row.for_next_year,
        'ForNextYearSum': row.for_next_year_sum,
        'MaxMinus': row.max_minus,
        'Payment': row.payment,
        'DecreaseFromLeaveID': row.decrease_from_leave_id,
        'GetInHolidays': row.get_in_holidays,
        'AutoInHolidays': row.auto_in_holidays,
    }


def _posted_form_values(row, post):
    values = _form_values(row)
    values.update(post.dict())
    values['IsHourly'] = post.get('IsHourly', 'true').lower() in ('true', '1', 'on')
    for name in _CHECKBOXES:
        values[name] = post.get(name) in ('true', 'on', '1')
    for name in ('ParameterID', 'DecreaseFromLeaveID'):
        raw = post.get(name, values[name])
        values[name] = int(raw) if str(raw or '').isdigit() else None
    return values


def _has_dependents(row):
    return (row.pre_cards.exists() or row.balances.exists()
            or row.requests.exists())


@require_perm('attendance', 'manage')
def leave_types(request):
    selected_id = request.GET.get('leave_type_id')
    row = get_object_or_404(LeaveType, pk=selected_id) if selected_id else None
    values = _form_values(row)
    show_panel = bool(row)

    if request.method == 'POST':
        action = request.POST.get('action') or ('delete' if request.POST.get('delete_id') else 'save')
        if action == 'delete_selected':
            try:
                raw_ids = request.POST.getlist('selected_ids')
                if not raw_ids or len(raw_ids) > 500 or any(not _text(v).isdigit() for v in raw_ids):
                    raise ValueError('برای حذف گروهی، یک تا ۵۰۰ نوع معتبر انتخاب کنید.')
                ids = {int(_text(v)) for v in raw_ids}
                with transaction.atomic():
                    selected_rows = list(LeaveType.objects.select_for_update().filter(
                        pk__in=ids).order_by('pk'))
                    if len(selected_rows) != len(ids):
                        raise ValueError('بعضی از انواع انتخاب‌شده دیگر وجود ندارند.')
                    if any(_has_dependents(item) for item in selected_rows):
                        raise ValueError('یک یا چند نوع انتخاب‌شده در داده‌های سامانه استفاده می‌شود؛ هیچ‌کدام حذف نشد.')
                    for item in selected_rows:
                        audit(request.user, 'delete', 'LeaveType', item.pk,
                              detail=item.name)
                        item.delete()
            except ValueError as exc:
                messages.error(request, str(exc) or 'حذف گروهی انجام نشد.')
            except (ProtectedError, IntegrityError):
                messages.error(request, 'به دلیل وابستگی داده‌ها، حذف گروهی انجام نشد.')
            else:
                messages.success(request, f'{len(selected_rows)} نوع مرخصی حذف شد.')
            return redirect('attendance:leave_types')

        raw_id = _text(request.POST.get('delete_id', request.POST.get('leave_type_id', '')))
        if raw_id and raw_id != '-1' and not raw_id.isdigit():
            messages.error(request, 'شناسهٔ نوع مرخصی معتبر نیست.')
            return redirect('attendance:leave_types')
        target = (get_object_or_404(LeaveType, pk=int(raw_id))
                  if raw_id.isdigit() and raw_id != '-1' else None)

        if action == 'delete':
            if target is None:
                messages.error(request, 'نوع مرخصی برای حذف انتخاب نشده است.')
            elif _has_dependents(target):
                messages.error(request, 'این نوع مرخصی در داده‌های ثبت‌شده استفاده می‌شود و حذف‌پذیر نیست.')
            else:
                label, pk = target.name, target.pk
                try:
                    with transaction.atomic():
                        audit(request.user, 'delete', 'LeaveType', pk, detail=label)
                        target.delete()
                except ProtectedError:
                    messages.error(request, 'این نوع مرخصی در پیش‌کارت‌ها استفاده می‌شود و حذف‌پذیر نیست.')
                except IntegrityError:
                    messages.error(request, 'این نوع مرخصی در داده‌های سامانه استفاده می‌شود و حذف‌پذیر نیست.')
                else:
                    messages.success(request, 'نوع مرخصی حذف شد.')
            return redirect('attendance:leave_types')

        try:
            name = _text(request.POST.get('LeaveName'))
            symbol = _text(request.POST.get('Symbol'))
            leave_kind = _text(request.POST.get('LeaveType1'))
            if not name or len(name) > 100 or not symbol or len(symbol) > 24:
                raise ValueError('نام و نماد الزامی هستند و باید در محدودهٔ طول مجاز باشند.')
            if leave_kind not in LeaveType.LeaveKind.values:
                raise ValueError('نوع مرخصی/ماموریت معتبر نیست.')
            color = _text(request.POST.get('Color', '#000000'))
            if not _COLOR.fullmatch(color):
                raise ValueError('رنگ باید با قالب #RRGGBB وارد شود.')
            state_key_default = target.state_key if target else 4
            state_key = int(_text(request.POST.get('StateKey', state_key_default)) or state_key_default)
            if not 0 <= state_key <= 2147483647:
                raise ValueError('کلید وضعیت خارج از محدوده است.')
            hourly_day_default = _time_text(target.max_hours_in_a_day_seconds) if target else '6:00'
            hourly_month_default = _time_text(target.max_hours_in_a_month_seconds) if target else '12:00'
            each_x_default = _time_text(target.each_x_hours_a_day_seconds) if target else '8:00'
            hourly_day = _duration_seconds(request.POST.get('MaxHoursInADay', hourly_day_default))
            hourly_month = _duration_seconds(request.POST.get('MaxHoursInAMonth', hourly_month_default))
            each_x_hours = _duration_seconds(request.POST.get('EachXHoursADay', each_x_default))
            max_daily_year_default = target.max_daily_in_year if target else '30'
            max_daily_month_default = target.max_daily_in_month if target else '2.5'
            max_daily_year = _decimal(request.POST.get('MaxDailyInYear'), 'حداکثر روزانه سال', max_daily_year_default)
            max_daily_month = _decimal(request.POST.get('MaxDailyInMonth'), 'حداکثر روزانه ماه', max_daily_month_default)
            for_next_year = _decimal(request.POST.get('ForNextYear'), 'انتقال به سال بعد', target.for_next_year if target else '9')
            max_minus = _decimal(request.POST.get('MaxMinus'), 'حداکثر منفی', target.max_minus if target else '0')
            get_in_type = _text(request.POST.get('GetInType', LeaveType.GetInType.MONTH_FIRST))
            if get_in_type not in LeaveType.GetInType.values:
                raise ValueError('روش استحقاق معتبر نیست.')
            parameter_id = _text(request.POST.get('ParameterID'))
            parameter = None
            if parameter_id:
                if not parameter_id.isdigit():
                    raise ValueError('پارامتر انتخاب‌شده معتبر نیست.')
                parameter = CalcParameter.objects.filter(pk=int(parameter_id), is_active=True).first()
                if parameter is None:
                    raise ValueError('پارامتر انتخاب‌شده فعال نیست.')
            decrease_id = _text(request.POST.get('DecreaseFromLeaveID'))
            decrease_from = None
            if decrease_id:
                if not decrease_id.isdigit():
                    raise ValueError('نوع مرخصیِ مبنای کسر معتبر نیست.')
                decrease_from = LeaveType.objects.filter(pk=int(decrease_id)).first()
                if decrease_from is None or (target and decrease_from.pk == target.pk):
                    raise ValueError('نوع مرخصیِ مبنای کسر معتبر نیست.')
        except (TypeError, ValueError, OverflowError) as exc:
            messages.error(request, str(exc) or 'مقادیر فرم معتبر نیستند.')
            values = _posted_form_values(target, request.POST)
            row = target
            show_panel = True
        else:
            is_new = target is None
            target = target or LeaveType()
            target.name = name
            target.symbol = symbol
            target.leave_kind = leave_kind
            target.is_hourly = request.POST.get('IsHourly', 'true').lower() in ('true', '1', 'on')
            target.color = color.lower()
            target.state_key = state_key
            target.max_hours_in_a_day_seconds = hourly_day
            target.max_hours_in_a_month_seconds = hourly_month
            target.max_daily_in_year = max_daily_year
            target.max_daily_in_month = max_daily_month
            target.each_x_hours_a_day_seconds = each_x_hours
            target.get_in_type = get_in_type
            target.parameter = parameter
            target.for_next_year = for_next_year
            target.for_next_year_sum = request.POST.get('ForNextYearSum') in ('true', 'on', '1')
            target.max_minus = max_minus
            target.payment = request.POST.get('Payment') in ('true', 'on', '1')
            target.decrease_from_leave = decrease_from
            target.get_in_holidays = request.POST.get('GetInHolidays') in ('true', 'on', '1')
            target.auto_in_holidays = request.POST.get('AutoInHolidays') in ('true', 'on', '1')
            if is_new:
                target.code = symbol
            try:
                target.full_clean()
                with transaction.atomic():
                    target.save()
                    audit(request.user, 'add' if is_new else 'change',
                          'LeaveType', target.pk, detail=target.name)
            except (IntegrityError, ValidationError, ValueError):
                messages.error(request, 'نماد/کد تکراری است یا نوع مرخصی معتبر نیست.')
                values = _form_values(target)
                row = target if target.pk else None
                show_panel = True
            else:
                messages.success(request, 'نوع مرخصی ذخیره شد.')
                return redirect('attendance:leave_types')

    return render(request, 'attendance/leave_types.html', {
        'rows': LeaveType.objects.select_related('parameter', 'decrease_from_leave').order_by('name'),
        'values': values,
        'row': row,
        'show_panel': show_panel,
        'parameters': CalcParameter.objects.filter(is_active=True).order_by('name'),
        'leave_types': LeaveType.objects.all().order_by('name'),
        'kind_choices': LeaveType.LeaveKind.choices,
        'get_in_type_choices': LeaveType.GetInType.choices,
    })
