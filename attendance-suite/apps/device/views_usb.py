"""درون‌ریزی CSV خروجی UNIS از مسیر USB، بدون پروتکل دستگاه مستقیم."""
from __future__ import annotations

import csv
import os

from django.contrib import messages
from django.db import transaction
from django.shortcuts import redirect, render
from django.utils import timezone

from apps.core.services import audit, require_perm, scoped_employees
from apps.org.models import Employee

from .gateway import unis_adapter
from .models import Device, UnisBridge
from .services import convert_raw_to_punches, ingest_records

SESSION_KEY = 't5_unis_usb_preview'
PREVIEW_TTL_SECONDS = 30 * 60
MAX_UPLOAD_SIZE = 10 * 1024 * 1024


def _page_context(request, bridge_id=None, *, preview=None, result=None):
    bridges = UnisBridge.objects.filter(
        is_active=True, device__is_active=True,
        device__brand=Device.Brand.VIRDI,
    ).select_related('device').order_by('name')
    return {
        'bridges': bridges,
        'selected_bridge_id': bridge_id,
        'preview': preview,
        'result': result,
    }


@require_perm('attendance', 'manage')
@require_perm('device', 'manage')
def usb_import(request):
    """Preview/commit only UNIS CSV/TXT exports associated with a Virdi bridge."""
    if request.method == 'POST' and request.POST.get('action') == 'cancel':
        request.session.pop(SESSION_KEY, None)
        request.session.modified = True
        messages.info(request, 'پیش‌نمایش لغو شد؛ هیچ رکوردی ثبت نشد.')
        return redirect('device:usb_import')

    if request.method == 'POST' and request.POST.get('action') == 'commit':
        saved = request.session.get(SESSION_KEY)
        if (not saved or saved.get('user_id') != request.user.pk
                or timezone.now().timestamp() - saved.get('created_at', 0)
                > PREVIEW_TTL_SECONDS):
            request.session.pop(SESSION_KEY, None)
            request.session.modified = True
            messages.error(request, 'پیش‌نمایش منقضی شده است؛ فایل را دوباره انتخاب کنید.')
            return redirect('device:usb_import')
        bridge = UnisBridge.objects.filter(
            pk=saved.get('bridge_id'), is_active=True,
            device__is_active=True, device__brand=Device.Brand.VIRDI,
        ).select_related('device').first()
        if bridge is None:
            request.session.pop(SESSION_KEY, None)
            request.session.modified = True
            messages.error(request, 'پل یونیس انتخاب‌شده دیگر فعال نیست.')
            return redirect('device:usb_import')

        permitted_badges = set(scoped_employees(
            request.user, 'attendance', Employee.objects.all()
        ).values_list('badge_no', flat=True))
        records = [item for item in saved['records']
                   if item['pin'] in permitted_badges]
        if len(records) != len(saved['records']):
            request.session.pop(SESSION_KEY, None)
            request.session.modified = True
            messages.error(request, 'دامنهٔ پرسنل تغییر کرده است؛ فایل را دوباره پیش‌نمایش کنید.')
            return redirect('device:usb_import')

        with transaction.atomic():
            ingested = ingest_records(bridge.device, records)
            converted = convert_raw_to_punches(
                bridge.device, allowed_pins=permitted_badges)
            bridge.last_sync_at = timezone.now()
            bridge.last_result = (
                f"usb={saved['file_name']}; created={ingested['created']} "
                f"skipped={ingested['skipped']} converted={converted['converted']} "
                f"errors={converted['errors']}")[:200]
            bridge.save(update_fields=['last_sync_at', 'last_result'])
            audit(
                request.user, 'unis_usb_import', 'RawRecord',
                detail=(f'bridge={bridge.pk}; file={saved["file_name"]}; '
                        f'rows={len(records)}; created={ingested["created"]}; '
                        f'converted={converted["converted"]}; errors={converted["errors"]}'),
            )
        request.session.pop(SESSION_KEY, None)
        request.session.modified = True
        return render(request, 'device/usb_import.html', _page_context(
            request, result={
                'file_name': saved['file_name'],
                'rows': len(records),
                'created': ingested['created'],
                'skipped': ingested['skipped'],
                'converted': converted['converted'],
                'errors': converted['errors'],
            }))

    if request.method == 'POST' and request.POST.get('action') == 'preview':
        request.session.pop(SESSION_KEY, None)
        bridge = UnisBridge.objects.filter(
            pk=request.POST.get('bridge_id'), is_active=True,
            device__is_active=True, device__brand=Device.Brand.VIRDI,
        ).select_related('device').first()
        upload = request.FILES.get('file')
        if bridge is None:
            messages.error(request, 'پل فعال UNIS برای دستگاه Virdi را انتخاب کنید.')
            return redirect('device:usb_import')
        if upload is None:
            messages.error(request, 'فایل خروجی UNIS را انتخاب کنید.')
            return redirect('device:usb_import')
        file_name = os.path.basename(upload.name)[:255]
        if (upload.size > MAX_UPLOAD_SIZE
                or os.path.splitext(file_name)[1].lower() not in {'.csv', '.txt'}):
            messages.error(request, 'فقط فایل CSV/TXT خروجی UNIS تا ۱۰ مگابایت پذیرفته می‌شود.')
            return redirect('device:usb_import')
        try:
            text = unis_adapter.decode_unis_export(upload.read())
            parsed = unis_adapter.parse_unis_export(
                text, bridge_name=bridge.name, file_name=file_name)
        except (unis_adapter.BridgeError, UnicodeError, csv.Error) as exc:
            messages.error(request, str(exc) or 'خواندن فایل ممکن نشد.')
            return redirect('device:usb_import')
        if not parsed:
            messages.error(request, 'در فایل، رکورد قابل پردازشی پیدا نشد.')
            return redirect('device:usb_import')

        allowed = scoped_employees(
            request.user, 'attendance', Employee.objects.all())
        permitted_badges = set(allowed.values_list('badge_no', flat=True))
        preview_rows = []
        records = []
        outside_scope = 0
        for item in parsed:
            eligible = item['pin'] in permitted_badges
            if eligible:
                records.append({key: value for key, value in item.items()
                                if key in {'pin', 'time', 'payload'}})
            else:
                outside_scope += 1
            if len(preview_rows) < 40:
                preview_rows.append({
                    'line': item['line'],
                    'pin': item['pin'],
                    'time': item['time'],
                    'importable': eligible,
                    'status': 'قابل ورود' if eligible else 'پرسنل ناشناخته یا خارج از دامنه',
                })

        saved = {
            'user_id': request.user.pk,
            'created_at': timezone.now().timestamp(),
            'bridge_id': bridge.pk,
            'file_name': file_name,
            'records': records,
            'preview_rows': preview_rows,
            'total_rows': len(parsed),
            'outside_scope': outside_scope,
        }
        request.session[SESSION_KEY] = saved
        request.session.modified = True
        return render(request, 'device/usb_import.html', _page_context(
            request, bridge.pk, preview={
                'rows': preview_rows,
                'total': len(parsed),
                'valid': len(records),
                'outside_scope': outside_scope,
                'file_name': file_name,
                'can_commit': bool(records),
            }))

    saved = request.session.get(SESSION_KEY)
    if saved and (saved.get('user_id') != request.user.pk
                  or timezone.now().timestamp() - saved.get('created_at', 0)
                  > PREVIEW_TTL_SECONDS):
        request.session.pop(SESSION_KEY, None)
        request.session.modified = True
        saved = None
    if saved:
        preview = {
            'rows': saved.get('preview_rows', []),
            'total': saved.get('total_rows', len(saved['records'])),
            'valid': len(saved['records']),
            'outside_scope': saved.get('outside_scope', 0),
            'file_name': saved['file_name'],
            'can_commit': bool(saved['records']),
        }
        bridge_id = saved['bridge_id']
    else:
        preview = None
        bridge_id = request.GET.get('bridge_id')
    return render(request, 'device/usb_import.html',
                  _page_context(request, bridge_id, preview=preview))
