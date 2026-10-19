"""دستگاه‌ها: فهرست، ثبت، رکوردهای خام با الزام مجوز."""
from django.contrib import messages
from django.shortcuts import redirect, render

from apps.core.services import audit, has_perm, require_perm

from .models import Device, RawRecord


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
