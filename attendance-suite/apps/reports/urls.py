from django.urls import path
from . import views

app_name = 'reports'

urlpatterns = [
    path('', views.index, name='index'),
    path('run/<str:code>/', views.run_standard, name='run_standard'),
    path('builder/', views.builder, name='builder'),
    path('def/<str:code>/', views.run_def, name='run_def'),
]
