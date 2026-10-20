from django.urls import path
from . import views

app_name = 'reports'

urlpatterns = [
    path('', views.index, name='index'),
    path('employees/search/', views.employee_search, name='employee_search'),
    path('run/<str:code>/', views.run_standard, name='run_standard'),
    path('builder/', views.builder, name='builder'),
    path('def/<str:code>/', views.run_def, name='run_def'),
]
