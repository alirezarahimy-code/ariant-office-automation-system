"""وب‌سرویس‌های سازمانی و سلامت سامانه."""
from rest_framework import permissions, viewsets
from rest_framework.response import Response
from rest_framework.views import APIView

from .models import Department, Employee
from .serializers import DepartmentSerializer, EmployeeSerializer


class HealthView(APIView):
    """بررسی سلامت سامانه — بدون نیاز به احراز هویت."""

    permission_classes = [permissions.AllowAny]
    authentication_classes: list = []

    def get(self, request):
        return Response({
            'ok': True,
            'title': 'سامانه اتوماسیون اداری آرینت',
            'status': 'سالم',
        })


class DepartmentViewSet(viewsets.ModelViewSet):
    """مدیریت واحدهای سازمانی — API نسخه ۱."""

    serializer_class = DepartmentSerializer
    queryset = Department.objects.select_related('parent').all()
    filterset_fields = ['parent', 'is_active']
    search_fields = ['title', 'code']
    ordering_fields = ['order', 'title', 'code']
    ordering = ['tree_id', 'lft']


class EmployeeViewSet(viewsets.ModelViewSet):
    """مدیریت کارمندان — API نسخه ۱."""

    serializer_class = EmployeeSerializer
    queryset = Employee.objects.select_related('department').all()
    filterset_fields = ['department', 'is_active']
    search_fields = ['first_name', 'last_name', 'personnel_code']
    ordering_fields = ['last_name', 'personnel_code']
    ordering = ['last_name', 'first_name']
