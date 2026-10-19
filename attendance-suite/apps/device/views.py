"""دستگاه‌ها: فهرست، ثبت، رکوردهای خام، پل API ورود، همگام‌سازی."""
import json

from django.contrib import messages
from django.http import JsonResponse
from django.shortcuts import redirect, render
from django.views.decorators.csrf import csrf_exempt

from apps.core.services import audit, has_perm, require_perm

from .models import Device, RawRecord
from .services import convert_raw_to_punches, ingest_records


@require_perm('device', 'view')
def index(request):
    return render(request, 'device/index.html', {
        'devices': Device.objects.filter(is_active=True),
        'can_manage': has_perm(request.user, 'device', 'manage'),
    })


@require_perm('device', 'add')
def device_add(request):
    if request.method == 'POST':
        try:
            Device.objects.create(
                name=request.POST.get('name', '').strip(),
                serial=request.POST.get('serial', '').strip(),
                model=request.POST.get('model', '').strip(),
                ip=request.POST.get('ip') or None,
                port=int(request.POST.get('port') or 437),
                conn_type=request.POST.get('conn_type', 'tcp'))
            audit(request.user, 'add', 'Device')
            messages.success(request, 'دستگاه ثبت شد.')
        except Exception as exc:
            messages.error(request, f'ورودی نامعتبر: {exc}')
    return redirect('device:index')


@require_perm('device', 'view')
def raw_records(request):
    return render(request, 'device/raw.html', {
        'records': RawRecord.objects.select_related('device')[:300],
        'can_manage': has_perm(request.user, 'device', 'manage'),
    })


@require_perm('device', 'manage')
def sync_punches(request):
    if request.method == 'POST':
        result = convert_raw_to_punches()
        audit(request.user, 'sync', 'RawRecord', detail=str(result))
        messages.success(
            request,
            f"همگام‌سازی: {result['converted']} تبدیل شد، "
            f"{result['errors']} خطا (کد ناشناس).")
    return redirect('device:raw_records')


def api_ingest(request):
    """API ورود رکورد دستگاه — سازگار با قرارداد Link5.RecordTransfer.

    POST /device/api/records/ با هدر `X-Device-Token` (توکن ثبت‌شده در AppSetting)
    بدنه: {"serial": "...", "records": [{"pin": "1002", "time": "2026-10-19 08:00:00"}]}
    """
    from apps.core.models import AppSetting
    if request.method != 'POST':
        return JsonResponse({'error': 'فقط POST'}, status=405)
    token = request.META.get('HTTP_X_DEVICE_TOKEN', '')
    expected = AppSetting.objects.filter(key='device_api_token').first()
    if not expected or not expected.value or token != expected.value:
        audit(None, 'device_api_denied', ip=request.META.get('REMOTE_ADDR'))
        return JsonResponse({'error': 'توکن نامعتبر'}, status=401)
    try:
        body = json.loads(request.body.decode('utf-8'))
        device = Device.objects.get(serial=body.get('serial'), is_active=True)
        result = ingest_records(device, body.get('records') or [])
        if body.get('convert'):
            result.update(convert_raw_to_punches(device))
        audit(None, 'device_api_ingest', 'Device', device.pk, detail=str(result))
        return JsonResponse({'ok': True, **result})
    except (ValueError, KeyError, Device.DoesNotExist) as exc:
        return JsonResponse({'error': str(exc)}, status=400)


@require_perm('device', 'view')
def visitors(request):
    """بازدیدکنندگان (Visitor/VisitReason)."""
    from .models import VisitReason, Visitor
    if request.method == 'POST' and has_perm(request.user, 'device', 'add'):
        from django.utils import timezone
        Visitor.objects.create(
            name=request.POST.get('name', '').strip(),
            card_no=request.POST.get('card_no', '').strip(),
            reason_id=request.POST.get('reason_id') or None,
            host_id=request.POST.get('host_id') or None,
            enter_time=timezone.now(),
            description=request.POST.get('description', ''))
        audit(request.user, 'add', 'Visitor')
        messages.success(request, 'بازدیدکننده ثبت شد.')
        return redirect('device:visitors')
    from apps.org.models import Employee
    return render(request, 'device/visitors.html', {
        'visitors': Visitor.objects.select_related('host', 'reason')[:200],
        'reasons': VisitReason.objects.all(),
        'employees': Employee.objects.filter(is_active=True),
        'can_add': has_perm(request.user, 'device', 'add'),
    })


@require_perm('device', 'view')
def cards(request):
    """کارت‌ها (Card)."""
    from .models import Card
    if request.method == 'POST' and has_perm(request.user, 'device', 'add'):
        Card.objects.get_or_create(
            card_no=request.POST.get('card_no', '').strip(),
            defaults={'employee_id': request.POST.get('employee_id') or None})
        audit(request.user, 'add', 'Card')
        messages.success(request, 'کارت ثبت شد.')
        return redirect('device:cards')
    from apps.org.models import Employee
    return render(request, 'device/cards.html', {
        'cards': Card.objects.select_related('employee')[:200],
        'employees': Employee.objects.filter(is_active=True),
        'can_add': has_perm(request.user, 'device', 'add'),
    })
