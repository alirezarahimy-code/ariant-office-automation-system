"""آزمون وب‌سرویس نسخه ۱ — قرارداد پل تیکتینگ (docs/04)."""
from django.contrib.auth import get_user_model
from rest_framework import status
from rest_framework.test import APITestCase

from apps.organization.models import Department


class HealthApiTests(APITestCase):
    def test_health_is_public(self):
        response = self.client.get('/api/v1/health')
        self.assertEqual(response.status_code, status.HTTP_200_OK)
        self.assertTrue(response.data['ok'])
        self.assertEqual(response.data['title'], 'سامانه اتوماسیون اداری آرینت')


class AuthTokenApiTests(APITestCase):
    def test_token_flow_and_me(self):
        """قرارداد پل تیکتینگ: دریافت توکن و استعلام هویت (docs/04)."""
        get_user_model().objects.create_user(
            username='bridge.user', password='Bridge@1404')
        response = self.client.post('/api/v1/auth/token', {
            'username': 'bridge.user', 'password': 'Bridge@1404',
        }, format='json')
        self.assertEqual(response.status_code, status.HTTP_200_OK)
        self.assertIn('access', response.data)

        self.client.credentials(HTTP_AUTHORIZATION=f"Bearer {response.data['access']}")
        me = self.client.get('/api/v1/auth/me')
        self.assertEqual(me.status_code, status.HTTP_200_OK)
        self.assertEqual(me.data['username'], 'bridge.user')

    def test_token_rejects_wrong_password(self):
        get_user_model().objects.create_user(
            username='bridge.user2', password='Bridge@1404')
        response = self.client.post('/api/v1/auth/token', {
            'username': 'bridge.user2', 'password': 'wrong',
        }, format='json')
        self.assertIn(response.status_code, [
            status.HTTP_400_BAD_REQUEST,
            status.HTTP_401_UNAUTHORIZED,
        ])


class DepartmentApiTests(APITestCase):
    def setUp(self):
        user = get_user_model().objects.create_user(
            username='api.user', password='Api@1404')
        self.client.force_authenticate(user=user)

    def test_create_and_list_departments_with_persian(self):
        response = self.client.post('/api/v1/departments', {
            'title': 'دبیرخانه مرکزی',
            'code': '101',
        }, format='json')
        self.assertEqual(response.status_code, status.HTTP_201_CREATED)

        response = self.client.get('/api/v1/departments')
        self.assertEqual(response.status_code, status.HTTP_200_OK)
        results = response.data['results']
        self.assertEqual(len(results), 1)
        self.assertEqual(results[0]['title'], 'دبیرخانه مرکزی')

    def test_search_by_title(self):
        Department.objects.create(title='معاونت مالی', code='102')
        Department.objects.create(title='فناوری اطلاعات', code='103')
        response = self.client.get('/api/v1/departments?search=مالی')
        self.assertEqual(response.status_code, status.HTTP_200_OK)
        titles = [row['title'] for row in response.data['results']]
        self.assertIn('معاونت مالی', titles)

    def test_anonymous_cannot_write(self):
        self.client.force_authenticate(user=None)
        response = self.client.post('/api/v1/departments', {
            'title': 'بدون مجوز', 'code': '999',
        }, format='json')
        self.assertIn(response.status_code, [
            status.HTTP_401_UNAUTHORIZED, status.HTTP_403_FORBIDDEN])

    def test_soft_delete_not_in_api(self):
        d = Department.objects.create(title='واحد حذف‌شده', code='104')
        d.delete()
        response = self.client.get('/api/v1/departments')
        codes = [row['code'] for row in response.data['results']]
        self.assertNotIn('104', codes)
