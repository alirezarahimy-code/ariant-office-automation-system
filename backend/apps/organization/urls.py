"""نشانی‌های وب‌سرویس نسخه ۱."""
from rest_framework.routers import DefaultRouter

from .views import DepartmentViewSet, EmployeeViewSet

router = DefaultRouter(trailing_slash=False)  # سازگار با قرارداد /api/v1 (docs/04)
router.register('departments', DepartmentViewSet, basename='department')
router.register('employees', EmployeeViewSet, basename='employee')

urlpatterns = router.urls
