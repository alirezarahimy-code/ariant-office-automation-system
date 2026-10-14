"""مسیرهای احراز هویت وب."""
from django.contrib.auth import views as auth_views
from django.urls import path

from apps.core import views as core_views

app_name = 'accounts'

urlpatterns = [
    path('login/', auth_views.LoginView.as_view(
        template_name='accounts/login.html', redirect_authenticated_user=True), name='login'),
    path('logout/', auth_views.LogoutView.as_view(), name='logout'),
    path('notifications/', core_views.notifications_list, name='notifications'),
    path('notifications/<int:pk>/read/', core_views.notification_read, name='notification_read'),
    path('notifications/read-all/', core_views.notifications_read_all, name='notifications_read_all'),
    path('profile/', core_views.profile_view, name='profile'),
    path('settings/', core_views.settings_view, name='settings'),
    path('users/', core_views.users_admin, name='users'),
    path('users/<int:pk>/edit/', core_views.users_admin_edit, name='users_edit'),
]
