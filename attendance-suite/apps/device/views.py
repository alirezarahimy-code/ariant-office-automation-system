"""دستگاه‌ها: فهرست، ثبت، رکوردهای خام، پل API ورود، همگام‌سازی."""
import json
import secrets

from django.contrib import messages
from django.core.exceptions import ValidationError
from django.db import IntegrityError, transaction
from django.http import Http404, HttpResponseForbidden, JsonResponse
from django.shortcuts import redirect, render
from django.views.decorators.csrf import csrf_exempt

from apps.core.services import audit, data_scope, has_perm, require_perm, scoped_employees

from .models import Device, RawRecord
from .services import convert_raw_to_punches, ingest_records


def _employees_in_both_scopes(user, queryset=None):
    from apps.org.models import Employee
    people = queryset if queryset is not None else Employee.objects.all()
    people = scoped_employees(user, 'device', people)
    return scoped_employees(user, 'attendance', people)


def _sync_badges_in_scope(user):
    return set(_employees_in_both_scopes(user).values_list('badge_no', flat=True))


def _has_global_sync_scope(user):
    return (data_scope(user, 'attendance') == 'all'
            and data_scope(user, 'device') == 'all')


def _deny_scoped_global_sync(request):
    if _has_global_sync_scope(request.user):
        return None
    audit(request.user, 'denied', model='device.global_sync',
          detail=request.path)
    return HttpResponseForbidden(
        '<div dir="rtl" style="font-family:Tahoma;padding:40px">'
        'برای همگام‌سازی سراسری، دامنهٔ دادهٔ حضور و دستگاه باید «همه» باشد.</div>')


@require_perm('device', 'view')
def index(request):
    return render(request, 'device/index.html', {
        'devices': Device.objects.filter(is_active=True),
        'can_add': has_perm(request.user, 'device', 'add'),
        'can_manage': has_perm(request.user, 'device', 'manage'),
        'can_sync': (has_perm(request.user, 'device', 'manage')
                     and has_perm(request.user, 'attendance', 'manage')
                     and _has_global_sync_scope(request.user)),
    })


@require_perm('device', 'add')
def device_add(request):
    if request.method == 'POST':
        try:
            port = int(request.POST.get('port') or 437)
            if not 1 <= port <= 65535:
                raise ValueError('port out of range')
            device = Device(
                name=request.POST.get('name', '').strip(),
                serial=request.POST.get('serial', '').strip(),
                model=request.POST.get('model', '').strip(),
                ip=request.POST.get('ip') or None,
                port=port,
                conn_type=request.POST.get('conn_type') or Device.ConnType.TCP,
                brand=request.POST.get('brand') or Device.Brand.ZK)
            device.full_clean()
            with transaction.atomic():
                device.save()
                audit(request.user, 'add', 'Device', device.pk)
            messages.success(request, 'دستگاه ثبت شد.')
        except (ValueError, ValidationError, IntegrityError):
            messages.error(request, 'مشخصات دستگاه نامعتبر یا سریال تکراری است.')
    return redirect('device:index')


@require_perm('device', 'view')
def operations(request):
    """T5 device operations center; Virdi data remains routed through UNIS."""
    from .models import UnisBridge
    from apps.org.models import Employee
    allowed_people = _employees_in_both_scopes(
        request.user, Employee.objects.filter(is_active=True))
    return render(request, 'device/operations.html', {
        'devices': Device.objects.filter(is_active=True).order_by('name'),
        'bridges': UnisBridge.objects.select_related('device').order_by('name'),
        'recent_records': RawRecord.objects.filter(
            pin__in=allowed_people.values_list('badge_no', flat=True)
        ).select_related('device').order_by('-punch_time')[:30],
        'can_manage': has_perm(request.user, 'device', 'manage'),
        'can_sync': (has_perm(request.user, 'device', 'manage')
                     and has_perm(request.user, 'attendance', 'manage')
                     and _has_global_sync_scope(request.user)),
        'can_convert': (has_perm(request.user, 'device', 'manage')
                        and has_perm(request.user, 'attendance', 'manage')),
        'can_usb_import': (has_perm(request.user, 'device', 'manage')
                           and has_perm(request.user, 'attendance', 'manage')),
    })


@require_perm('device', 'view')
def raw_records(request):
    from apps.org.models import Employee
    allowed_people = _employees_in_both_scopes(
        request.user, Employee.objects.filter(is_active=True))
    records = RawRecord.objects.filter(
        pin__in=allowed_people.values_list('badge_no', flat=True)
    ).select_related('device')
    return render(request, 'device/raw.html', {
        'records': records[:300],
        'can_convert': (has_perm(request.user, 'device', 'manage')
                        and has_perm(request.user, 'attendance', 'manage')),
    })


@require_perm('attendance', 'manage')
@require_perm('device', 'manage')
def sync_punches(request):
    if request.method == 'POST':
        result = convert_raw_to_punches(
            allowed_pins=_sync_badges_in_scope(request.user))
        audit(request.user, 'sync', 'RawRecord', detail=str(result))
        messages.success(
            request,
            f"همگام‌سازی: {result['converted']} تبدیل شد، "
            f"{result['errors']} خطا (کد ناشناس).")
    return redirect('device:raw_records')


@csrf_exempt
def api_ingest(request):
    """API ورود رکورد دستگاه — سازگار با قرارداد Link5.RecordTransfer.

    احراز هویت با هدر `X-Device-Token` (نه کوکی) → بنابراین CSRF معاف است.
    """
    from apps.core.models import AppSetting
    if request.method != 'POST':
        return JsonResponse({'error': 'فقط POST'}, status=405)
    if len(request.body) > 2 * 1024 * 1024:
        return JsonResponse({'error': 'حجم درخواست بیش از حد مجاز است.'}, status=413)
    token = request.META.get('HTTP_X_DEVICE_TOKEN', '')
    expected = AppSetting.objects.filter(key='device_api_token').first()
    if not expected or not expected.value or not secrets.compare_digest(
            token.encode('utf-8'), expected.value.encode('utf-8')):
        audit(None, 'device_api_denied', ip=request.META.get('REMOTE_ADDR'))
        return JsonResponse({'error': 'توکن نامعتبر'}, status=401)
    try:
        body = json.loads(request.body.decode('utf-8'))
        if not isinstance(body, dict):
            raise ValueError('invalid payload')
        serial = body.get('serial')
        records = body.get('records', [])
        should_convert = body.get('convert', False)
        if (not isinstance(serial, str) or not serial.strip() or len(serial) > 60
                or not isinstance(records, list) or len(records) > 10000
                or not isinstance(should_convert, bool)):
            raise ValueError('invalid payload')
        for item in records:
            if not isinstance(item, dict):
                raise ValueError('invalid record')
            pin, punch_time = item.get('pin'), item.get('time')
            verify_mode = item.get('verify_mode', 0)
            if (isinstance(pin, bool) or not isinstance(pin, (str, int))
                    or not 1 <= len(str(pin).strip()) <= 24
                    or not isinstance(punch_time, str) or not punch_time.strip()
                    or len(punch_time) > 40
                    or isinstance(verify_mode, bool)
                    or not str(verify_mode).isdigit()
                    or not 0 <= int(verify_mode) <= 32767
                    or len(str(item.get('work_code') or '')) > 12):
                raise ValueError('invalid record')
        device = Device.objects.get(serial=serial.strip(), is_active=True)
        result = ingest_records(device, records)
        if should_convert:
            result.update(convert_raw_to_punches(device))
        audit(None, 'device_api_ingest', 'Device', device.pk, detail=str(result))
        return JsonResponse({'ok': True, **result})
    except (ValueError, TypeError, OverflowError, RecursionError,
            Device.DoesNotExist):
        return JsonResponse({'error': 'ساختار درخواست یا اطلاعات دستگاه نامعتبر است.'},
                            status=400)


@require_perm('device', 'view')
def visitors(request):
    """بازدیدکنندگان (Visitor/VisitReason)."""
    from .models import VisitReason, Visitor
    from apps.org.models import Employee
    allowed_employees = _employees_in_both_scopes(
        request.user, Employee.objects.filter(is_active=True))
    if request.method == 'POST' and has_perm(request.user, 'device', 'add'):
        from django.utils import timezone
        name = request.POST.get('name', '').strip()
        card_no = request.POST.get('card_no', '').strip()
        description = request.POST.get('description', '').strip()
        host_id = request.POST.get('host_id') or None
        reason_id = request.POST.get('reason_id') or None
        host = (allowed_employees.filter(pk=host_id).first()
                if host_id and str(host_id).isdigit() else None)
        reason = (VisitReason.objects.filter(pk=reason_id).first()
                  if reason_id and str(reason_id).isdigit() else None)
        if not name or len(name) > 160 or len(card_no) > 40 or len(description) > 300:
            messages.error(request, 'نام بازدیدکننده یا طول یکی از فیلدها معتبر نیست.')
        elif host_id and host is None:
            messages.error(request, 'میزبان انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif reason_id and reason is None:
            messages.error(request, 'علت مراجعهٔ انتخاب‌شده معتبر نیست.')
        else:
            visitor = Visitor(
                name=name, card_no=card_no, reason=reason, host=host,
                enter_time=timezone.now(), description=description)
            try:
                visitor.full_clean()
                visitor.save()
            except ValidationError:
                messages.error(request, 'اطلاعات بازدیدکننده معتبر نیست.')
            else:
                audit(request.user, 'add', 'Visitor', visitor.pk)
                messages.success(request, 'بازدیدکننده ثبت شد.')
        return redirect('device:visitors')
    return render(request, 'device/visitors.html', {
        'visitors': (Visitor.objects.all() if _has_global_sync_scope(request.user)
                     else Visitor.objects.filter(host__in=allowed_employees)
                     ).select_related('host', 'reason')[:200],
        'reasons': VisitReason.objects.all(),
        'employees': allowed_employees,
        'can_add': has_perm(request.user, 'device', 'add'),
    })


@require_perm('device', 'view')
def cards(request):
    """کارت‌ها (Card)."""
    from .models import Card
    from apps.org.models import Employee
    allowed_employees = _employees_in_both_scopes(
        request.user, Employee.objects.filter(is_active=True))
    if request.method == 'POST' and has_perm(request.user, 'device', 'add'):
        card_no = request.POST.get('card_no', '').strip()
        employee_id = request.POST.get('employee_id') or None
        employee = allowed_employees.filter(pk=employee_id).first() if employee_id else None
        if not card_no:
            messages.error(request, 'شماره کارت الزامی است.')
        elif len(card_no) > 40:
            messages.error(request, 'شماره کارت حداکثر ۴۰ نویسه است.')
        elif employee_id and employee is None:
            messages.error(request, 'پرسنل انتخاب‌شده خارج از دامنهٔ مجاز است.')
        elif not employee_id and not _has_global_sync_scope(request.user):
            messages.error(request, 'اتصال کارت به پرسنل مجاز الزامی است.')
        else:
            card, created = Card.objects.get_or_create(
                card_no=card_no, defaults={'employee': employee})
            if not created and card.employee_id != (employee.pk if employee else None):
                messages.error(request, 'این شماره کارت قبلاً به رکورد دیگری متصل شده است.')
            else:
                audit(request.user, 'add' if created else 'view', 'Card', card.pk)
                messages.success(request, 'کارت ثبت شد.')
        return redirect('device:cards')
    cards_qs = Card.objects.filter(employee__in=allowed_employees)
    if _has_global_sync_scope(request.user):
        cards_qs = Card.objects.all()
    return render(request, 'device/cards.html', {
        'cards': cards_qs.select_related('employee')[:200],
        'employees': allowed_employees,
        'can_add': has_perm(request.user, 'device', 'add'),
    })


@require_perm('device', 'manage')
def unis_bridge(request):
    """پل یونیس: تنظیم منبع لاگ دستگاه‌های Virdi (بانک یونیس یا پوشه خروجی)."""
    from .models import UnisBridge
    if request.method == 'POST':
        bridge_id = request.POST.get('bridge_id') or None
        defaults = dict(
            name=request.POST.get('name', '').strip(),
            device_id=request.POST.get('device_id') or None,
            mode=request.POST.get('mode') or 'db',
            table_name=request.POST.get('table_name', '').strip(),
            col_pin=request.POST.get('col_pin', '').strip(),
            col_time=request.POST.get('col_time', '').strip(),
            col_direction=request.POST.get('col_direction', '').strip(),
            dir_path=request.POST.get('dir_path', '').strip(),
            is_active=request.POST.get('is_active') == 'on')
        device_valid = (
            str(defaults['device_id'] or '').isdigit()
            and Device.objects.filter(
                pk=defaults['device_id'], is_active=True,
                brand=Device.Brand.VIRDI).exists())
        fields_valid = (
            bool(defaults['name']) and len(defaults['name']) <= 120
            and defaults['mode'] in UnisBridge.Mode.values
            and len(defaults['table_name']) <= 120
            and len(defaults['col_pin']) <= 64
            and len(defaults['col_time']) <= 64
            and len(defaults['col_direction']) <= 64
            and len(defaults['dir_path']) <= 255
        )
        if not fields_valid or not device_valid:
            messages.error(request, 'نام، دستگاه Virdi فعال یا مشخصات پل یونیس معتبر نیست.')
        elif bridge_id:
            if not str(bridge_id).isdigit():
                messages.error(request, 'شناسهٔ پل معتبر نیست.')
            else:
                changed = UnisBridge.objects.filter(pk=bridge_id).update(**defaults)
                if changed:
                    audit(request.user, 'change', 'UnisBridge', int(bridge_id))
                    messages.success(request, 'پل یونیس به‌روزرسانی شد.')
                else:
                    messages.error(request, 'پل یونیس پیدا نشد.')
        else:
            obj = UnisBridge.objects.create(**defaults)
            audit(request.user, 'add', 'UnisBridge', obj.pk)
            messages.success(request, 'پل یونیس ثبت شد.')
        return redirect('device:unis_bridge')
    return render(request, 'device/unis_bridge.html', {
        'bridges': UnisBridge.objects.select_related('device'),
        'devices': Device.objects.filter(
            is_active=True, brand=Device.Brand.VIRDI),
        'can_sync': (has_perm(request.user, 'device', 'manage')
                     and has_perm(request.user, 'attendance', 'manage')
                     and _has_global_sync_scope(request.user)),
    })


@require_perm('attendance', 'manage')
@require_perm('device', 'manage')
def unis_sync(request, bridge_id):
    """اجرای فوری یک نوبت همگام‌سازی پل یونیس."""
    denied = _deny_scoped_global_sync(request)
    if denied:
        return denied
    if request.method != 'POST':
        return redirect('device:unis_bridge')
    from .gateway import BridgeError, sync_bridge
    from .models import UnisBridge
    bridge = UnisBridge.objects.filter(
        pk=bridge_id, is_active=True, device__is_active=True,
        device__brand=Device.Brand.VIRDI).select_related('device').first()
    if not bridge:
        raise Http404('پل یافت نشد')
    try:
        result = sync_bridge(
            bridge, allowed_pins=_sync_badges_in_scope(request.user))
        audit(request.user, 'change', 'UnisBridge', bridge.pk, detail=str(result))
        messages.success(
            request,
            f"همگام‌سازی «{bridge.name}»: {result['created']} تازه، "
            f"{result['skipped']} تکراری، {result['converted']} تبدیل.")
    except BridgeError as exc:
        messages.error(request, str(exc))
    return redirect('device:unis_bridge')


@require_perm('attendance', 'manage')
@require_perm('device', 'manage')
def gateway_sync(request):
    """اجرای فوری یک نوبت دروازه چندبرند (ZK + یونیس)."""
    denied = _deny_scoped_global_sync(request)
    if denied:
        return denied
    if request.method != 'POST':
        return redirect('device:index')
    from .gateway import run_gateway
    result = run_gateway(allowed_pins=_sync_badges_in_scope(request.user))
    audit(request.user, 'change', 'Device', detail=str(result)[:500])
    zk_ok = sum(1 for x in result['zk'] if 'error' not in x)
    zk_err = len(result['zk']) - zk_ok
    messages.success(
        request,
        f"دروازه دستگاه: {zk_ok} دستگاه ZK همگام"
        + (f"، {zk_err} خطا" if zk_err else '')
        + f" | {len(result['unis'])} پل یونیس | "
        f"{result['converted'].get('converted', 0)} تبدیل تردد.")
    return redirect('device:index')


@require_perm('device', 'view')
def device_users(request):
    """کاربران دستگاه (ShowUsers/DownloadUsers/UploadUsers) — همگام‌سازی کارت/اثر."""
    from apps.org.models import Employee
    from .models import DeviceUserSync
    allowed_employees = _employees_in_both_scopes(
        request.user, Employee.objects.filter(is_active=True))
    if request.method == 'POST' and has_perm(request.user, 'device', 'manage'):
        device = Device.objects.filter(
            pk=request.POST.get('device_id'), is_active=True).first()
        action = request.POST.get('action')  # download | upload
        if not device or action not in ('download', 'upload'):
            messages.error(request, 'دستگاه یا عملیات انتخاب‌شده معتبر نیست.')
            return redirect('device:device_users')
        employees = allowed_employees.filter(device_enable=True)[:500]
        n = 0
        for employee in employees:
            DeviceUserSync.objects.update_or_create(
                device=device, employee=employee,
                defaults={'state': 'pending',
                          'direction': 'down' if action == 'download' else 'up'})
            n += 1
        audit(request.user, 'change', 'DeviceUserSync', detail=f'{action} {n}')
        messages.success(request, f'{n} کاربر برای همگام‌سازی ({action}) صف شد.')
        return redirect('device:device_users')
    return render(request, 'device/device_users.html', {
        'devices': Device.objects.filter(is_active=True),
        'syncs': DeviceUserSync.objects.filter(employee__in=allowed_employees).select_related(
            'device', 'employee')[:200],
        'employees': allowed_employees.filter(device_enable=True)[:400],
        'can_manage': has_perm(request.user, 'device', 'manage')})


@require_perm('device', 'view')
def gates(request):
    """گیت‌ها/درب‌ها (Gate)."""
    from .models import AccessPoint
    if request.method == 'POST' and has_perm(request.user, 'device', 'manage'):
        AccessPoint.objects.create(
            name=request.POST.get('name', '').strip(),
            device_id=request.POST.get('device_id') or None,
            description=request.POST.get('description', '').strip())
        audit(request.user, 'add', 'AccessPoint')
        return redirect('device:gates')
    return render(request, 'device/gates.html', {
        'gates': AccessPoint.objects.select_related('device'),
        'devices': Device.objects.filter(is_active=True),
        'can_manage': has_perm(request.user, 'device', 'manage')})
