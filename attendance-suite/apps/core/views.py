"""ویوهای هسته: ورود امن (محدودسازی نرخ + گزارش دسترسی) و خانه."""
from django.contrib.auth import authenticate, login, logout
from django.contrib.auth.decorators import login_required
from django.shortcuts import redirect, render

from .services import (audit, clear_login_failures, login_throttled,
                       record_login_failure, _client_ip)


def login_view(request):
    error = ''
    if request.method == 'POST':
        username = (request.POST.get('username') or '').strip()
        password = request.POST.get('password') or ''
        ip = _client_ip(request)
        if login_throttled(username, ip):
            error = 'تعداد تلاش‌های ناموفق زیاد است. چند دقیقه دیگر دوباره تلاش کنید.'
            audit(None, 'login_blocked', detail=f'{username} @ {ip}', ip=ip)
        else:
            user = authenticate(request, username=username, password=password)
            if user is not None and user.is_active:
                login(request, user)
                clear_login_failures(username, ip)
                audit(user, 'login', detail='ورود موفق', ip=ip)
                next_url = request.POST.get('next') or request.GET.get('next')
                return redirect(next_url if next_url and next_url.startswith('/')
                                else 'attendance:dashboard')
            record_login_failure(username, ip)
            audit(None, 'login_failed', detail=f'{username} @ {ip}', ip=ip)
            error = 'نام کاربری یا گذرواژه نادرست است.'
    return render(request, 'core/login.html', {
        'error': error, 'next': request.GET.get('next', '')})


@login_required
def logout_view(request):
    audit(request.user, 'logout', ip=_client_ip(request))
    logout(request)
    return redirect('core:login')


@login_required
def home(request):
    return redirect('attendance:dashboard')


@login_required
def audit_list(request):
    from .services import has_perm
    from .models import AuditLog
    if not (has_perm(request.user, 'core', 'manage')):
        return render(request, 'core/forbidden.html')
    return render(request, 'core/audit.html', {
        'logs': AuditLog.objects.select_related('user')[:300],
    })
