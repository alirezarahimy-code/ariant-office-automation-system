"""سازنده‌های وب‌سرویس — قرارداد سازگار با /api/v1 (docs/04)."""
from rest_framework import serializers

from .models import Department, Employee


class DepartmentSerializer(serializers.ModelSerializer):
    parent_title = serializers.CharField(
        source='parent.title', read_only=True, default=None)

    class Meta:
        model = Department
        fields = [
            'id', 'title', 'code', 'parent', 'parent_title',
            'order', 'is_active',
        ]
        read_only_fields = ['id']


class EmployeeSerializer(serializers.ModelSerializer):
    department_title = serializers.CharField(
        source='department.title', read_only=True, default=None)
    full_name = serializers.CharField(read_only=True)

    class Meta:
        model = Employee
        fields = [
            'id', 'first_name', 'last_name', 'full_name', 'personnel_code',
            'department', 'department_title', 'job_title', 'hire_date',
            'is_active',
        ]
        read_only_fields = ['id']
