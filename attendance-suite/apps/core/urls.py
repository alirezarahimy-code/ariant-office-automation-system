"""نشانی‌های هسته: ورود/خروج امن + گزارش دسترسی."""
from django.urls import path

from . import views

app_name = 'core'

urlpatterns = [
    path('', views.home, name='home'),
    path('login/', views.login_view, name='login'),
    path('logout/', views.logout_view, name='logout'),
    path('audit/', views.audit_list, name='audit'),
    path('notifications/', views.notifications, name='notifications'),
]
