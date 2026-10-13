"""زمینه مشترک همه صفحه‌ها: هویت سازمان، تاریخ شمسی، اعلان‌های نخوانده."""
import jdatetime
from django.conf import settings

from apps.core.persian import to_persian_digits


def platform_context(request):
    today = jdatetime.date.today()
    ctx = {
        'ORG_NAME': settings.PLATFORM_ORG_NAME,
        'ORG_MOTTO': getattr(settings, 'PLATFORM_ORG_MOTTO', ''),
        'JALALI_TODAY': to_persian_digits(today.strftime('%Y/%m/%d')),
        'JALALI_WEEKDAY': today.strftime('%A'),
        'UNREAD_NOTIFICATIONS': 0,
    }
    if request.user.is_authenticated:
        ctx['UNREAD_NOTIFICATIONS'] = request.user.notifications.filter(is_read=False).count()
    return ctx
