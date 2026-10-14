"""پیکربندی نشانی‌های سامانه."""
from django.contrib import admin
from django.conf import settings
from django.conf.urls.static import static
from django.urls import include, path
from rest_framework_simplejwt.views import TokenObtainPairView, TokenRefreshView

from apps.core.views import AuthMeView, home
from apps.organization.views import HealthView

urlpatterns = [
    path('', home, name='home'),
    path('admin/', admin.site.urls),
    path('accounts/', include('apps.core.urls')),
    path('', include('apps.secretariat.urls')),
    path('bridge/', include('apps.ticket_bridge.urls')),
    # فاز ۲ — زیرسامانه‌های بستر
    path('workflow/', include('apps.workflow.urls')),
    path('archive/', include('apps.archive.urls')),
    path('meetings/', include('apps.meetings.urls')),
    path('tasks/', include('apps.tasks.urls')),
    path('reports/', include('apps.reports.urls')),
    path('tickets/', include('apps.tickets.urls')),
    path('platform/', include('apps.registry.urls')),
    # وب‌سرویس نسخه ۱ — قرارداد پل تیکتینگ (docs/04)
    path('api/v1/health', HealthView.as_view(), name='api-health'),
    path('api/v1/auth/token', TokenObtainPairView.as_view(), name='api-token'),
    path('api/v1/auth/token/refresh', TokenRefreshView.as_view(), name='api-token-refresh'),
    path('api/v1/auth/me', AuthMeView.as_view(), name='api-me'),
    path('api/v1/', include('apps.organization.urls')),
]

if settings.DEBUG:
    urlpatterns += static(settings.MEDIA_URL, document_root=settings.MEDIA_ROOT)

admin.site.site_header = 'سامانه اتوماسیون اداری آرینت'
admin.site.site_title = 'مدیریت سامانه آرینت'
admin.site.index_title = 'پنل مدیریت'
