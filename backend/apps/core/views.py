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
