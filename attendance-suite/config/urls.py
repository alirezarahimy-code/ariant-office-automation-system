"""نشانی‌های کلی."""
from django.contrib import admin
from django.urls import include, path

urlpatterns = [
    path('admin/', admin.site.urls),
    path('', include('apps.core.urls')),
    path('org/', include('apps.org.urls')),
    path('device/', include('apps.device.urls')),
    path('attendance/', include('apps.attendance.urls')),
    path('reports/', include('apps.reports.urls')),
    path('t5/', include('apps.t5web.urls')),
]
