from django.urls import path

from . import views

app_name = 'reports'

urlpatterns = [
    path('', views.dashboard, name='dashboard'),
    path('builder/', views.builder, name='builder'),
    path('export/', views.export, name='export'),
]
