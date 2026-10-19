from django.urls import path
from . import views

app_name = 'org'

urlpatterns = [
    path('', views.index, name='index'),
    path('employees/new/', views.employee_new, name='employee_new'),
    path('employees/<int:pk>/edit/', views.employee_edit, name='employee_edit'),
    path('departments/new/', views.department_new, name='department_new'),
    path('positions/new/', views.position_new, name='position_new'),
]
