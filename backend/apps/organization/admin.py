"""پنل مدیریت فارسی — با تاریخچه ممیزی کامل."""
from django.contrib import admin
from simple_history.admin import SimpleHistoryAdmin

from .models import Department, Employee


@admin.register(Department)
class DepartmentAdmin(SimpleHistoryAdmin):
    list_display = ('title', 'code', 'parent', 'order', 'is_active', 'is_deleted')
    list_filter = ('is_active', 'is_deleted')
    search_fields = ('title', 'code')
    list_editable = ('order', 'is_active')
    autocomplete_fields = ('parent',)

    def get_queryset(self, request):
        return Department.all_objects.select_related('parent')


@admin.register(Employee)
class EmployeeAdmin(SimpleHistoryAdmin):
    list_display = (
        'first_name', 'last_name', 'personnel_code',
        'department', 'job_title', 'hire_date', 'is_active',
    )
    list_filter = ('is_active', 'department')
    search_fields = ('first_name', 'last_name', 'personnel_code')
    autocomplete_fields = ('department',)

    def get_queryset(self, request):
        return Employee.all_objects.select_related('department')
