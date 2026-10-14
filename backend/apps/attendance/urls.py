from django.urls import path

from . import views

app_name = 'attendance'

urlpatterns = [
    path('', views.attendance_list, name='list'),
    path('new/', views.attendance_new, name='new'),
    path('month/', views.attendance_month, name='month'),
    path('month/<int:user_pk>/', views.attendance_month, name='user_month'),
]
