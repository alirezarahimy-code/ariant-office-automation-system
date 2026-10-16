"""آزمون‌های گزارش‌ها، خروجی اکسل، داشبورد، انبارها و دوره مالی."""
from decimal import Decimal

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from apps.core.services import clear_feature_cache

from .models import InventoryPeriod, Item, Warehouse
from .services import (current_stock, export_accounting_rows,
                       no_movement_items, receive)

User = get_user_model()


class ReportTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item1 = Item.objects.create(code='K1', name='پرگردش', unit='عدد')
        self.item2 = Item.objects.create(code='K2', name='راکد', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار')
        receive(item=self.item1, warehouse=self.wh, quantity=5,
                date=jdatetime.date(1404, 7, 30))

    def tearDown(self):
        clear_feature_cache()

    def test_no_movement_report(self):
        rows = no_movement_items(
            jdatetime.date(1404, 7, 1), jdatetime.date(1404, 7, 30))
        codes = [r['item'].code for r in rows]
        self.assertIn('K2', codes)
        self.assertNotIn('K1', codes)

    def test_accounting_export_rows(self):
        rows = export_accounting_rows()
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['item_code'], 'K1')
        self.assertEqual(rows[0]['quantity'], Decimal('5'))

    def test_dashboard_view(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('inventory:dashboard'))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'داشبورد انبار')

    def test_no_movement_view(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('inventory:no_movement'))
        self.assertEqual(resp.status_code, 200)

    def test_balance_excel(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('inventory:balance_excel'))
        self.assertEqual(resp.status_code, 200)
        self.assertIn('spreadsheetml', resp['Content-Type'])

    def test_movement_excel(self):
        self.client.force_login(self.user)
        resp = self.client.get(
            reverse('inventory:movement_excel', args=[self.item1.pk]))
        self.assertEqual(resp.status_code, 200)

    def test_accounting_excel(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('inventory:accounting_excel'))
        self.assertEqual(resp.status_code, 200)

    def test_count_template_excel(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('inventory:count_template'))
        self.assertEqual(resp.status_code, 200)


class WarehouseAndPeriodViewTests(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))

    def tearDown(self):
        clear_feature_cache()

    def test_warehouse_create_and_assign_users(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('inventory:warehouses'), {
            'action': 'create', 'name': 'انبار جدید', 'description': 'تست'})
        self.assertEqual(resp.status_code, 302)
        wh = Warehouse.objects.get(name='انبار جدید')
        resp = self.client.post(reverse('inventory:warehouses'), {
            'action': 'users', 'warehouse_id': wh.pk, 'users': [self.manager.pk]})
        self.assertEqual(resp.status_code, 302)
        self.assertIn(self.manager, wh.users.all())
        resp = self.client.get(reverse('inventory:warehouses'))
        self.assertEqual(resp.status_code, 200)

    def test_period_create_and_toggle(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('inventory:periods'), {
            'action': 'create', 'year': '1404', 'title': 'سال مالی ۱۴۰۴'})
        self.assertEqual(resp.status_code, 302)
        period = InventoryPeriod.objects.get(year=1404)
        self.assertTrue(period.is_open)
        resp = self.client.post(reverse('inventory:periods'), {
            'action': 'toggle', 'period_id': period.pk})
        period.refresh_from_db()
        self.assertFalse(period.is_open)
        resp = self.client.get(reverse('inventory:periods'))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'بسته')


class LegacyViewTests(TestCase):
    """نماهای قدیمی باید مثل قبل کار کنند (سازگاری)."""

    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار')

    def test_balance_and_value_views(self):
        self.client.force_login(self.user)
        self.assertEqual(
            self.client.get(reverse('inventory:balance')).status_code, 200)
        self.assertEqual(
            self.client.get(reverse('inventory:value')).status_code, 200)

    def test_groups_view(self):
        manager = User.objects.create_user('mgr', password='x')
        manager.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))
        self.client.force_login(manager)
        resp = self.client.get(reverse('inventory:groups'))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.post(reverse('inventory:groups'), {'name': 'گروه جدید'})
        self.assertEqual(resp.status_code, 302)

    def test_expiry_and_serials_views(self):
        self.client.force_login(self.user)
        self.assertEqual(
            self.client.get(reverse('inventory:expiry')).status_code, 200)
        self.assertEqual(
            self.client.get(reverse('inventory:serials')).status_code, 200)
