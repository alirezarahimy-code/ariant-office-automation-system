"""صفحه نخست — نمای متنی ساده فارسی/راست‌به‌چپ."""
from datetime import date

import jdatetime
from django.contrib.auth.decorators import login_required
from django.http import HttpResponse
from django.views.decorators.http import require_POST
from rest_framework.permissions import IsAuthenticated
from rest_framework.response import Response
from rest_framework.views import APIView

from apps.core.persian import to_persian_digits


def home(request):
    """ورودی سامانه: کاربر وارد شده به کارتابل، ناشناس به ورود می‌رود."""
    from django.shortcuts import redirect
    if request.user.is_authenticated:
        return redirect('secretariat:kartable')
    return redirect('accounts:login')


def persian_weekday_index(jdate) -> int:
    """شماره روز هفته ایرانی از روی jdatetime (شنبه=۰ برای j_weekdays)."""
    from datetime import date as gdate
    from apps.core.persian import persian_weekday_number
    g = jdate.togregorian()
    return persian_weekday_number(gdate(g.year, g.month, g.day)) - 1


class AuthMeView(APIView):
    """هویت کاربر جاری — قرارداد سازگار با /api/v1/auth/me (docs/04)."""

    permission_classes = [IsAuthenticated]

    def get(self, request):
        user = request.user
        return Response({
            'id': user.id,
            'username': user.username,
            'full_name': user.get_full_name() or user.username,
            'email': user.email,
            'is_staff': user.is_staff,
        })


@login_required
def notifications_list(request):
    """فهرست اعلان‌های کاربر (۱۰۰ مورد آخر)."""
    from django.shortcuts import render
    notifications = request.user.notifications.all()[:100]
    unread = request.user.notifications.filter(is_read=False).count()
    return render(request, 'core/notifications.html', {
        'notifications': notifications, 'unread': unread})


@login_required
@require_POST
def notification_read(request, pk):
    from django.shortcuts import get_object_or_404, redirect
    notification = get_object_or_404(request.user.notifications, pk=pk)
    notification.is_read = True
    notification.save(update_fields=['is_read', 'updated_at'])
    return redirect('accounts:notifications')


@login_required
@require_POST
def notifications_read_all(request):
    from django.shortcuts import redirect
    request.user.notifications.filter(is_read=False).update(is_read=True)
    return redirect('accounts:notifications')


# ---------------------------------------------------------------------------
# پروفایل، تنظیمات سامانه و مدیریت کاربران (فاز ۱ — تکمیل)
# ---------------------------------------------------------------------------

@login_required
def profile_view(request):
    """پروفایل کاربر: نام، تماس و امضای الکترونیک."""
    from django.contrib import messages
    from django.shortcuts import redirect, render
    from apps.core.models import UserProfile

    profile = UserProfile.for_user(request.user)
    if request.method == 'POST':
        request.user.first_name = request.POST.get('first_name', '').strip()[:150]
        request.user.last_name = request.POST.get('last_name', '').strip()[:150]
        request.user.email = request.POST.get('email', '').strip()[:254]
        request.user.save(update_fields=['first_name', 'last_name', 'email'])
        profile.phone = request.POST.get('phone', '').strip()[:20]
        if request.FILES.get('signature'):
            profile.signature = request.FILES['signature']
        profile.save()
        messages.success(request, 'پروفایل به‌روزرسانی شد.')
        return redirect('accounts:profile')
    return render(request, 'accounts/profile.html', {'profile': profile})


def _is_platform_admin(user) -> bool:
    return user.is_superuser or user.has_perm('core.manage_platform_settings')


@login_required
def settings_view(request):
    """تنظیمات سامانه: نام سازمان، شعار و سال مالی."""
    from django.contrib import messages
    from django.shortcuts import redirect, render
    from apps.core.models import OrgSettings

    if not _is_platform_admin(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('secretariat:kartable')
    settings_obj = OrgSettings.load()
    if request.method == 'POST':
        settings_obj.org_name = request.POST.get(
            'org_name', '').strip()[:200] or settings_obj.org_name
        settings_obj.motto = request.POST.get('motto', '').strip()[:200]
        settings_obj.fiscal_year = request.POST.get('fiscal_year', '').strip()[:20]
        settings_obj.save()
        messages.success(request, 'تنظیمات سامانه ذخیره شد.')
        return redirect('accounts:settings')
    return render(request, 'accounts/settings.html', {'obj': settings_obj})


@login_required
def users_admin(request):
    """مدیریت کاربران: فهرست، ویرایش هویت و فعال/غیرفعال."""
    from django.contrib import messages
    from django.contrib.auth import get_user_model
    from django.shortcuts import get_object_or_404, redirect, render

    if not _is_platform_admin(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('secretariat:kartable')
    User = get_user_model()
    q = request.GET.get('q', '').strip()
    users = User.objects.all().order_by('username')
    if q:
        from django.db.models import Q
        users = users.filter(Q(username__icontains=q)
                             | Q(first_name__icontains=q)
                             | Q(last_name__icontains=q))
    return render(request, 'accounts/users.html', {
        'users': users, 'q': q, 'edit_pk': int(request.GET.get('edit', 0) or 0)})


@login_required
@require_POST
def users_admin_edit(request, pk):
    """ویرایش هویت/وضعیت کاربر توسط مدیر سامانه."""
    from django.contrib import messages
    from django.contrib.auth import get_user_model
    from django.shortcuts import get_object_or_404, redirect

    if not _is_platform_admin(request.user):
        messages.error(request, 'دسترسی ندارید.')
        return redirect('secretariat:kartable')
    User = get_user_model()
    user = get_object_or_404(User, pk=pk)
    user.first_name = request.POST.get('first_name', '').strip()[:150]
    user.last_name = request.POST.get('last_name', '').strip()[:150]
    user.email = request.POST.get('email', '').strip()[:254]
    user.is_active = request.POST.get('is_active') == 'on'
    user.save(update_fields=['first_name', 'last_name', 'email', 'is_active'])
    messages.success(request, f'کاربر {user.username} به‌روزرسانی شد.')
    return redirect('accounts:users')


@login_required
def features_admin(request):
    """مدیریت کلیدهای قابلیت — اجزا همیشه هستند؛ سازمان روشن/خاموش می‌کند."""
    from django.contrib import messages
    from django.shortcuts import redirect, render

    from apps.core.models import FEATURE_REGISTRY, FeatureToggle
    from apps.core.services import clear_feature_cache

    if not request.user.is_superuser:
        messages.error(request, 'مدیریت قابلیت‌ها فقط برای مدیر ارشد است.')
        return redirect('accounts:settings')

    if request.method == 'POST':
        code = request.POST.get('code', '')
        if code in FEATURE_REGISTRY:
            obj, _ = FeatureToggle.objects.get_or_create(
                code=code,
                defaults={'title': FEATURE_REGISTRY[code][0],
                          'group': FEATURE_REGISTRY[code][1]})
            obj.is_enabled = request.POST.get('is_enabled') == '1'
            obj.save()
            clear_feature_cache()
            messages.success(
                request,
                'قابلیت «{}» روی {} قرار گرفت.'.format(
                    obj.title, 'فعال' if obj.is_enabled else 'غیرفعال'))
        return redirect('accounts:features')

    rows = []
    for code, (title, group) in sorted(FEATURE_REGISTRY.items(), key=lambda kv: kv[1][1]):
        obj = FeatureToggle.objects.filter(code=code).first()
        rows.append({
            'code': code, 'title': title, 'group': group,
            'is_enabled': True if obj is None else obj.is_enabled,
            'description': '' if obj is None else obj.description,
        })
    return render(request, 'core/features.html', {'rows': rows})
