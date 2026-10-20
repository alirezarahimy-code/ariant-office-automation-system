"""T5Web پیش‌کارت: نگهداری امن پیکربندی Card بدون اجرای ApplyInterval.

ApplyInterval در اسمبلی مرجع رکوردهای دستگاه را به‌صورت گروهی تغییر می‌دهد.
تا وقتی تطبیق کامل CheckType/StateKey با مدل تردد جدید تأیید نشده، عملیات
تغییردهنده پنهان می‌ماند؛ CRUD پیکربندی و فیلدهای خود فرم مرجع در دسترس است.
"""
from django.contrib import messages
from django.core.exceptions import ValidationError
from django.db import IntegrityError, transaction
from django.http import Http404, HttpResponseForbidden
from django.shortcuts import get_object_or_404, redirect, render

from apps.core.services import audit, data_scope, has_perm, require_perm
from apps.device.models import Device

from .models import LeaveType, PreCard


def _has_full_attendance_device_scope(user):
    return (data_scope(user, 'attendance') == 'all'
            and data_scope(user, 'device') == 'all')


@require_perm('attendance', 'manage')
@require_perm('device', 'manage')
def pre_cards(request):
    if not _has_full_attendance_device_scope(request.user):
        audit(request.user, 'denied', model='attendance.pre_cards',
              detail=request.path)
        return HttpResponseForbidden(
            '<div dir="rtl" style="font-family:Tahoma;padding:40px">'
            'برای مدیریت پیش‌کارت، دامنهٔ دادهٔ حضور و دستگاه باید «همه» باشد.</div>')

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
        'apply_interval_available': False,
    })
