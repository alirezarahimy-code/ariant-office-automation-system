"""ویوهای هسته: ورود امن (محدودسازی نرخ + گزارش دسترسی) و خانه."""
from django.contrib import messages
from django.contrib.auth import authenticate, login, logout
from django.contrib.auth.decorators import login_required
from django.shortcuts import redirect, render

from .services import (audit, clear_login_failures, login_throttled,
                       record_login_failure, require_perm, _client_ip)


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


@login_required
def notifications(request):
    """قالب‌های اعلان (NotificationTemplate)."""
    from .models import NotificationTemplate
    from .services import has_perm
    if not has_perm(request.user, 'core', 'manage'):
        return render(request, 'core/forbidden.html')
    if request.method == 'POST':
        NotificationTemplate.objects.update_or_create(
            kind=request.POST.get('kind', '').strip(),
            defaults={'title': request.POST.get('title', ''),
                      'body': request.POST.get('body', '')})
        audit(request.user, 'change', 'NotificationTemplate')
        from django.contrib import messages as dj_messages
        dj_messages.success(request, 'قالب ذخیره شد.')
        return redirect('core:notifications')
    return render(request, 'core/notifications.html', {
        'rows': NotificationTemplate.objects.all()})


@require_perm('core', 'view')
def system_logs(request):
    """لاگ سیستم (ViewSystemLogs)."""
    from .models import AuditLog
    logs = AuditLog.objects.order_by('-at')[:300]
    return render(request, 'core/system_logs.html', {'logs': logs})


@require_perm('core', 'view')
def change_pass(request):
    """تغییر رمز (Home/ChangePass)."""
    if request.method == 'POST':
        old = request.POST.get('old_password', '')
        new1 = request.POST.get('new_password1', '')
        new2 = request.POST.get('new_password2', '')
        if not request.user.check_password(old):
            messages.error(request, 'رمز فعلی نادرست است.')
        elif len(new1) < 6:
            messages.error(request, 'رمز جدید حداقل ۶ کاراکتر.')
        elif new1 != new2:
            messages.error(request, 'تکرار رمز مطابقت ندارد.')
        else:
            request.user.set_password(new1)
            request.user.save()
            audit(request.user, 'change', 'User', request.user.pk, detail='password')
            messages.success(request, 'رمز با موفقیت تغییر کرد.')
            return redirect('attendance:dashboard')
    return render(request, 'core/change_pass.html')
