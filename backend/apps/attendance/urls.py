from django.urls import path

from . import views

app_name = 'attendance'

urlpatterns = [
    path('', views.attendance_list, name='list'),
    path('new/', views.attendance_new, name='new'),
    path('month/', views.attendance_month, name='month'),
    path('month/<int:user_pk>/', views.attendance_month, name='user_month'),

    # لایه حرفه‌ای
    path('dashboard/', views.dashboard, name='dashboard'),
    path('requests/', views.request_list, name='request_list'),
    path('requests/new/', views.request_new, name='request_new'),
    path('requests/<int:pk>/', views.request_detail, name='request_detail'),
    path('requests/<int:pk>/decide/', views.request_decide, name='request_decide'),
    path('punches/', views.punches, name='punches'),
    path('punches/add/', views.punch_add, name='punch_add'),
    path('leaves/', views.leaves, name='leaves'),
    path('overtime/', views.overtime_view, name='overtime'),
    path('overtime/constants/', views.overtime_constant_add, name='overtime_constant_add'),
    path('rewards/', views.rewards, name='rewards'),
    path('rewards/add/', views.reward_add, name='reward_add'),
    path('geo/', views.geo_list, name='geo'),
    path('geo/add/', views.geo_add, name='geo_add'),
    path('location/', views.location, name='location'),
    path('policy/', views.policy_view, name='policy'),
]
