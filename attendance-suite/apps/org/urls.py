from django.urls import path
from . import views, views_import_excel

app_name = 'org'

urlpatterns = [
    path('', views.index, name='index'),
    path('employees/new/', views.employee_new, name='employee_new'),
    path('employees/<int:pk>/edit/', views.employee_edit, name='employee_edit'),
    path('departments/new/', views.department_new, name='department_new'),
    path('departments/', views.department_admin, name='department_admin'),
    path('departments/<int:pk>/', views.department_admin, name='department_edit'),
    path('positions/new/', views.position_new, name='position_new'),
    path('chart/', views.org_chart, name='org_chart'),
    path('titles/', views.titles, name='titles'),
    path('emp-types/', views.emp_types, name='emp_types'),
    path('certificates/', views.certificates, name='certificates'),
    path('custom-fields/', views.custom_fields, name='custom_fields'),
    path('userinfo/', views.userinfo, name='userinfo'),
    path('userinfo/<int:emp_id>/', views.userinfo, name='userinfo_edit'),
    path('import-excel/', views_import_excel.import_excel, name='import_excel'),
]
