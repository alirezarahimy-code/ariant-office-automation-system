from django.urls import path
from . import views

app_name = 'device'

urlpatterns = [
    path('', views.index, name='index'),
    path('add/', views.device_add, name='device_add'),
    path('raw/', views.raw_records, name='raw_records'),
]
