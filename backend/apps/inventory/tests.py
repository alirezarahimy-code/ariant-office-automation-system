"""آزمون‌های انبار: رسید/حواله، موجودی، کارت کالا و انبارگردانی."""
from decimal import Decimal

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from .models import Item, StockCount, StockCountLine, StockMove, Warehouse
from .services import (apply_count, current_stock, issue, item_card,
                       low_stock_items, receive)

User = get_user_model()


class StockMathTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item = Item.objects.create(code='K1', name='کاغذ A4', unit='بسته',
                                        min_stock=Decimal('10'))
        self.warehouse = Warehouse.objects.create(name='انبار مرکزی')
        self.d = jdatetime.date(1404, 7, 23)

    def test_receive_and_issue(self):
        receive(item=self.item, warehouse=self.warehouse, quantity=20, date=self.d)
        issue(item=self.item, warehouse=self.warehouse, quantity=5, date=self.d)
        self.assertEqual(current_stock(self.item, self.warehouse), Decimal('15'))
        self.assertEqual(current_stock(self.item), Decimal('15'))

    def test_issue_over_stock_raises(self):
        receive(item=self.item, warehouse=self.warehouse, quantity=2, date=self.d)
        with self.assertRaises(ValueError):
            issue(item=self.item, warehouse=self.warehouse, quantity=3, date=self.d)

    def test_receive_zero_raises(self):
        with self.assertRaises(ValueError):
            receive(item=self.item, warehouse=self.warehouse, quantity=0, date=self.d)

    def test_item_card_running_balance(self):
        receive(item=self.item, warehouse=self.warehouse, quantity=20, date=self.d)
        issue(item=self.item, warehouse=self.warehouse, quantity=5, date=self.d)
        rows = item_card(self.item)
        self.assertEqual(len(rows), 2)
        self.assertEqual(rows[0]['balance'], Decimal('20'))
        self.assertEqual(rows[1]['balance'], Decimal('15'))

    def test_low_stock(self):
        receive(item=self.item, warehouse=self.warehouse, quantity=3, date=self.d)
        low = low_stock_items()
        self.assertEqual(len(low), 1)
        self.assertEqual(low[0]['item'], self.item)

    def test_apply_count_creates_adjustment(self):
        receive(item=self.item, warehouse=self.warehouse, quantity=20, date=self.d)
        count = StockCount.objects.create(
            warehouse=self.warehouse, date=self.d, created_by=self.user)
        StockCountLine.objects.create(
            count=count, item=self.item, counted_quantity=Decimal('18'))
        apply_count(count)
        self.assertEqual(current_stock(self.item, self.warehouse), Decimal('18'))
        count.refresh_from_db()
        self.assertEqual(count.status, StockCount.Status.APPLIED)
        adjust = StockMove.objects.get(kind=StockMove.Kind.ADJUST)
        self.assertEqual(adjust.quantity, Decimal('-2'))

    def test_apply_count_twice_raises(self):
        count = StockCount.objects.create(warehouse=self.warehouse, date=self.d)
        apply_count(count)
        with self.assertRaises(ValueError):
            apply_count(count)


class InventoryViewTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item = Item.objects.create(code='K1', name='کاغذ A4', unit='بسته')
        self.warehouse = Warehouse.objects.create(name='انبار مرکزی')
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))

    def test_list_view(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('inventory:list'))
        self.assertEqual(resp.status_code, 200)

    def test_detail_view(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('inventory:detail', args=[self.item.pk]))
        self.assertEqual(resp.status_code, 200)

    def test_move_post_receive(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('inventory:move_new'), {
            'item_id': self.item.pk, 'warehouse_id': self.warehouse.pk,
            'kind': 'receive', 'quantity': '10', 'date': '1404/07/23'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(current_stock(self.item), Decimal('10'))

    def test_move_post_issue_over_stock_fails(self):
        self.client.force_login(self.manager)
        self.client.post(reverse('inventory:move_new'), {
            'item_id': self.item.pk, 'warehouse_id': self.warehouse.pk,
            'kind': 'issue', 'quantity': '10', 'date': '1404/07/23'})
        self.assertEqual(current_stock(self.item), Decimal('0'))

    def test_count_apply(self):
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('inventory:count_apply'), {
            'warehouse_id': self.warehouse.pk, 'date': '1404/07/23',
            f'count_{self.item.pk}': '7'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(current_stock(self.item), Decimal('7'))
