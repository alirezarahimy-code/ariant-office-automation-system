"""ویوهای هسته: ورود امن (محدودسازی نرخ + گزارش دسترسی) و خانه."""
import hmac
import io
import secrets
import time

from django.conf import settings
from django.contrib import messages
from django.contrib.auth import authenticate, login, logout, update_session_auth_hash
from django.contrib.auth.decorators import login_required
from django.http import HttpResponse
from django.shortcuts import redirect, render
from django.urls import reverse
from django.utils.crypto import salted_hmac
from django.utils.http import url_has_allowed_host_and_scheme
from django.views.decorators.http import require_GET

from .services import (audit, clear_login_failures, has_program_access,
                       login_throttled, record_login_failure, require_perm,
                       _client_ip)


_CAPTCHA_SESSION_DIGEST = 'core_login_captcha_digest'
_CAPTCHA_SESSION_CREATED = 'core_login_captcha_created'
_CAPTCHA_TTL_SECONDS = 300
_CAPTCHA_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'


def _new_captcha_answer() -> str:
    return ''.join(secrets.choice(_CAPTCHA_ALPHABET) for _ in range(6))


def _captcha_digest(answer: str) -> str:
    return salted_hmac('core.login.captcha', answer, secret=settings.SECRET_KEY).hexdigest()


def _consume_captcha(request, answer: str) -> bool:
    """Verify and consume the one-time server-issued challenge."""
    digest = request.session.pop(_CAPTCHA_SESSION_DIGEST, '')
    created = request.session.pop(_CAPTCHA_SESSION_CREATED, 0)
    try:
        fresh = 0 <= int(time.time()) - int(created) <= _CAPTCHA_TTL_SECONDS
    except (TypeError, ValueError):
        fresh = False
    submitted = _captcha_digest((answer or '').strip().upper())
    return bool(digest and fresh and hmac.compare_digest(digest, submitted))


@require_GET
def captcha_image(request):
    """Issue a short-lived, one-time login challenge as a non-cacheable PNG."""
    from PIL import Image, ImageDraw, ImageFont

    answer = _new_captcha_answer()
    request.session[_CAPTCHA_SESSION_DIGEST] = _captcha_digest(answer)
    request.session[_CAPTCHA_SESSION_CREATED] = int(time.time())

    image = Image.new('RGB', (196, 64), '#f1f5f9')
    draw = ImageDraw.Draw(image)
    try:
        font = ImageFont.truetype('DejaVuSans.ttf', 28)
    except OSError:
        font = ImageFont.load_default()
    for _ in range(7):
        draw.line((secrets.randbelow(196), secrets.randbelow(64),
                   secrets.randbelow(196), secrets.randbelow(64)),
                  fill='#94a3b8', width=1)
    x = 12
    for char in answer:
        glyph = Image.new('RGBA', (34, 42), (0, 0, 0, 0))
        glyph_draw = ImageDraw.Draw(glyph)
        glyph_draw.text((4, 2), char, font=font, fill='#172554')
        glyph = glyph.rotate(secrets.randbelow(31) - 15,
                             resample=Image.Resampling.BICUBIC)
        image.paste(glyph, (x, 10 + secrets.randbelow(9)), glyph)
        x += 29
    buffer = io.BytesIO()
    image.save(buffer, format='PNG')
    response = HttpResponse(buffer.getvalue(), content_type='image/png')
    response['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0'
    response['Pragma'] = 'no-cache'
    response['X-Content-Type-Options'] = 'nosniff'
    return response


def login_view(request):
    error = ''
    username = (request.POST.get('userName') or
                request.POST.get('username') or '').strip()
    return_url = (request.POST.get('ReturnURL') or
                  request.POST.get('ReturnUrl') or request.POST.get('next') or
                  request.GET.get('ReturnURL') or
                  request.GET.get('ReturnUrl') or request.GET.get('next') or '')
    if request.method == 'POST':
        password = request.POST.get('Password')
        if password is None:
            password = request.POST.get('password') or ''
        ip = _client_ip(request)
        if login_throttled(username, ip):
            error = 'تعداد تلاش‌های ناموفق زیاد است. چند دقیقه دیگر دوباره تلاش کنید.'
            audit(None, 'login_blocked', detail=f'{username} @ {ip}', ip=ip)
        elif (getattr(settings, 'LOGIN_CAPTCHA_ENABLED', True)
              and not _consume_captcha(request, request.POST.get('CaptchaAnswer', ''))):
            record_login_failure(username, ip)
            audit(None, 'login_failed', detail=f'captcha: {username} @ {ip}', ip=ip)
            error = 'عبارت امنیتی نادرست یا منقضی شده است؛ دوباره تلاش کنید.'
        else:
            user = authenticate(request, username=username, password=password)
            has_web_access = user is not None and has_program_access(user, 't5web')
            if user is not None and user.is_active and has_web_access:
                login(request, user)
                clear_login_failures(username, ip)
                audit(user, 'login', detail='ورود موفق', ip=ip)
                if user.must_change_password:
                    return redirect(f"{reverse('core:change_pass')}?Message=1")
                if return_url and url_has_allowed_host_and_scheme(
                        return_url, allowed_hosts={request.get_host()},
                        require_https=request.is_secure()):
                    return redirect(return_url)
                return redirect('attendance:dashboard')
            if user is not None and user.is_active and not has_web_access:
                audit(user, 'login_denied', model='T5Web',
                      detail='دسترسی T5Web برای پروندهٔ پرسنلی غیرفعال است.', ip=ip)
                error = 'دسترسی T5Web برای این پروندهٔ پرسنلی فعال نیست.'
            else:
                record_login_failure(username, ip)
                audit(None, 'login_failed', detail=f'{username} @ {ip}', ip=ip)
                error = 'نام کاربری یا گذرواژه نادرست است.'
    return render(request, 'core/login.html', {
        'error': error, 'next': return_url, 'return_url': return_url,
        'username': username,
        'captcha_enabled': getattr(settings, 'LOGIN_CAPTCHA_ENABLED', True),
        'captcha_nonce': secrets.token_urlsafe(8),
    })


@login_required
def logout_view(request):
    audit(request.user, 'logout', ip=_client_ip(request))
    logout(request)
    return redirect('core:login')


@login_required
def home(request):
    return redirect('attendance:dashboard')


@login_required
def about(request):
    """T5Web's reference About page, populated from the shipped resource text."""
    return render(request, 'core/about.html', {
        'company_name': 'شرکت سامانه های بعد پنجم',
        'description_1': 'طراح و تولید کننده سیستم های الکترونیکی و کامپیوتری',
        'description_2': 'ارائه کننده نسل جدید سیستم های حضور و غیاب و کنترل دسترسی',
        'phone': 'تلفن : 89350(021)',
        'fax': 'تلفکس : 44293581',
        'product_name': 'نرم افزار حضور و غیاب سازمانی T5Web v2.0',
    })


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


@login_required
def change_pass(request):
    """تغییر رمز (Home/ChangePass) برای هر کاربر واردشده."""
    force_password_change = (
        request.GET.get('Message') == '1' or request.POST.get('Message') == '1'
    )
    if request.method == 'POST':
        old = request.POST.get('OldPassword')
        if old is None:
            old = request.POST.get('old_password', '')
        new1 = request.POST.get('NewPassword')
        if new1 is None:
            new1 = request.POST.get('new_password1', '')
        new2 = request.POST.get('ConfirmPassword')
        if new2 is None:
            new2 = request.POST.get('new_password2', '')
        if not request.user.check_password(old):
            messages.error(request, 'رمز فعلی نادرست است.')
        elif len(new1) < 6:
            messages.error(request, 'رمز جدید حداقل ۶ کاراکتر.')
        elif new1 != new2:
            messages.error(request, 'تکرار رمز مطابقت ندارد.')
        else:
            request.user.set_password(new1)
            request.user.must_change_password = False
            request.user.save()
            update_session_auth_hash(request, request.user)
            audit(request.user, 'change', 'User', request.user.pk, detail='password')
            messages.success(request, 'رمز با موفقیت تغییر کرد.')
            return redirect('attendance:dashboard')
    return render(request, 'core/change_pass.html', {
        'force_password_change': force_password_change,
    })
