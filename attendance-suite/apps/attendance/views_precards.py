"""T5Web پیش‌کارت و اعمال بازه‌ای CheckType بر رکوردهای دستگاه."""
from datetime import timedelta

from django.contrib import messages
from django.core.exceptions import ValidationError
from django.db import IntegrityError, transaction
from django.http import Http404, HttpResponseForbidden
from django.shortcuts import get_object_or_404, redirect, render
from django.utils import timezone

import jdatetime

from apps.core.services import audit, data_scope, has_perm, require_perm
from apps.device.models import Device

from .models import LeaveType, PreCard
from .services import parse_jalali, to_jdate
from .services_precards import apply_pre_cards as apply_pre_cards_service


def _has_full_attendance_device_scope(user):
    return (data_scope(user, 'attendance') == 'all'
            and data_scope(user, 'device') == 'all')


def _deny_without_full_scope(request):
    if _has_full_attendance_device_scope(request.user):
        return None
    audit(request.user, 'denied', model='attendance.pre_cards',
          detail=request.path)
    return HttpResponseForbidden(
        '<div dir="rtl" style="font-family:Tahoma;padding:40px">'
        'برای مدیریت پیش‌کارت، دامنهٔ دادهٔ حضور و دستگاه باید «همه» باشد.</div>')


@require_perm('attendance', 'manage')
@require_perm('device', 'manage')
def pre_cards(request):
    denied = _deny_without_full_scope(request)
    if denied:
        return denied

    selected_id = (request.POST.get('precard_id') if request.method == 'POST'
                   else request.GET.get('precard_id'))
    if selected_id and not str(selected_id).isdigit():
        raise Http404('شناسهٔ پیش‌کارت نامعتبر است')
    selected = get_object_or_404(PreCard, pk=selected_id) if selected_id else None

    if request.method == 'POST':
        action = request.POST.get('action', 'save')
        if action == 'delete':
            if not selected_id:
                raise Http404('پیش‌کارت پیدا نشد')
            target = get_object_or_404(PreCard, pk=selected_id)
            card_number = target.card_badge_number
            target.delete()
            audit(request.user, 'delete', 'PreCard', card_number)
            messages.success(request, 'پیش‌کارت حذف شد.')
            return redirect('attendance:pre_cards')

        try:
            card_number = int(request.POST.get('CardBadgeNumber', ''))
            valid_secs = int(request.POST.get('ValidSecs', ''))
            leave_type_id = int(request.POST.get('LeaveTypeID', ''))
            device_id = int(request.POST.get('MachineNumber', ''))
        except (TypeError, ValueError):
            messages.error(request, 'شماره کارت، مدت اعتبار، وضعیت و دستگاه را معتبر وارد کنید.')
            return redirect('attendance:pre_cards')

        leave_type = LeaveType.objects.filter(pk=leave_type_id, is_active=True).first()
        device = Device.objects.filter(pk=device_id, is_active=True).first()
        if card_number <= 0 or valid_secs < 0 or leave_type is None or device is None:
            messages.error(request, 'مقادیر پیش‌کارت معتبر نیست یا وضعیت/دستگاه فعال نیست.')
            return redirect('attendance:pre_cards')

        target = selected
        creating = target is None
        if target is None:
            target = PreCard()
        target.card_badge_number = card_number
        target.valid_secs = valid_secs
        target.leave_type = leave_type
        target.device = device
        target.only_first = request.POST.get('OnlyFirst') in ('true', 'on', '1')
        try:
            target.full_clean()
            with transaction.atomic():
                target.save()
                audit(request.user, 'add' if creating else 'change',
                      'PreCard', target.card_badge_number)
        except (ValidationError, IntegrityError):
            messages.error(request, 'شماره کارت تکراری است یا مشخصات پیش‌کارت معتبر نیست.')
        else:
            messages.success(request, 'پیش‌کارت ذخیره شد.')
        return redirect('attendance:pre_cards')

    return render(request, 'attendance/pre_cards.html', {
        'pre_cards': PreCard.objects.select_related('leave_type', 'device').all(),
        'selected': selected,
        'leave_types': LeaveType.objects.filter(is_active=True).order_by('name'),
        'devices': Device.objects.filter(is_active=True).order_by('name'),
        'can_manage': (has_perm(request.user, 'attendance', 'manage')
                       and has_perm(request.user, 'device', 'manage')),
    })


@require_perm('attendance', 'manage')
@require_perm('device', 'manage')
def apply_pre_cards(request):
    denied = _deny_without_full_scope(request)
    if denied:
        return denied
    if request.method == 'POST':
        try:
            if request.POST.get('isMonthly') == 'true':
                year = int(request.POST.get('year', ''))
                month = int(request.POST.get('month', ''))
                if not 1 <= month <= 12 or not 1300 <= year <= 1600:
                    raise ValueError('سال یا ماه جلالی نامعتبر است.')
                start_j = jdatetime.date(year, month, 1)
                if month == 12:
                    next_month = jdatetime.date(year + 1, 1, 1)
                else:
                    next_month = jdatetime.date(year, month + 1, 1)
                end_j = next_month - timedelta(days=1)
                date_from = start_j.togregorian()
                date_to = end_j.togregorian()
            else:
                date_from = parse_jalali(request.POST.get('FromDateHijri', ''))
                date_to = parse_jalali(request.POST.get('ToDateHijri', ''))
            if date_from > date_to or (date_to - date_from).days > 366:
                raise ValueError('بازه باید معتبر و حداکثر یک سال باشد.')
            result = apply_pre_cards_service(date_from, date_to)
        except (TypeError, ValueError, OverflowError) as exc:
            messages.error(request, str(exc) or 'بازهٔ تاریخ معتبر نیست.')
        else:
            audit(request.user, 'apply', 'PreCard.ApplyInterval',
                  detail=f'{date_from}..{date_to}; {result}')
            messages.success(
                request,
                f"اعمال پیش‌کارت انجام شد: {result['records_changed']} رکورد تغییر کرد "
                f"(از {result['records_matched']} رکورد منطبق در "
                f"{result['cards_examined']} پیکربندی).")
        return redirect('attendance:pre_cards_apply')

    today_j = to_jdate(timezone.localdate())
    return render(request, 'attendance/apply_pre_cards.html', {
        'today': today_j.strftime('%Y/%m/%d'),
        'year': today_j.year,
        'month': today_j.month,
        'leave_types': LeaveType.objects.filter(is_active=True).order_by('name'),
        'can_apply': (has_perm(request.user, 'attendance', 'manage')
                      and has_perm(request.user, 'device', 'manage')),
    })
