"""صفحه نخست — نمای متنی ساده فارسی/راست‌به‌چپ."""
from datetime import date

import jdatetime
from django.http import HttpResponse
from rest_framework.permissions import IsAuthenticated
from rest_framework.response import Response
from rest_framework.views import APIView

from apps.core.persian import to_persian_digits


def home(request):
    today_j = jdatetime.date.fromgregorian(date=date.today())
    body = f"""<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><title>سامانه اتوماسیون اداری آرینت</title></head>
<body style="font-family: Tahoma, sans-serif; padding: 2rem;">
<h1>سامانه اتوماسیون اداری آرینت</h1>
<p>تاریخ امروز: {to_persian_digits(today_j.strftime('%Y/%m/%d'))} — {today_j.j_weekdays[persian_weekday_index(today_j)]}</p>
<p>پنل مدیریت: <a href="/admin/">/admin/</a></p>
<p>وب‌سرویس سلامت: <a href="/api/v1/health">/api/v1/health</a></p>
</body></html>"""
    return HttpResponse(body)


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
