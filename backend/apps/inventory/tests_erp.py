"""آزمون‌های ارتقای انبار: انتقال، ارزش موجودی، ریزگردش و چاپ سند."""
from decimal import Decimal

import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from .models import Item, StockMove, Warehouse
from .services import (current_stock, inventory_value, movement_report,
                       receive, transfer, weighted_avg_price)

User = get_user_model()


class TransferTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item = Item.objects.create(code='T1', name='کالای انتقالی', unit='عدد')
        self.wh_a = Warehouse.objects.create(name='انبار الف')
        self.wh_b = Warehouse.objects.create(name='انبار ب')
        self.d = jdatetime.date(1404, 7, 24)
        receive(item=self.item, warehouse=self.wh_a, quantity=20, date=self.d,
                category=StockMove.Category.PURCHASE, unit_price=1000)

    def test_transfer_moves_stock(self):
        out_move, in_move = transfer(
            item=self.item, from_warehouse=self.wh_a, to_warehouse=self.wh_b,
            quantity=5, date=self.d)
        self.assertEqual(current_stock(self.item, self.wh_a), Decimal('15'))
        self.assertEqual(current_stock(self.item, self.wh_b), Decimal('5'))
        self.assertEqual(out_move.reference, in_move.reference)
        self.assertEqual(out_move.category, StockMove.Category.TRANSFER)

    def test_transfer_same_warehouse_raises(self):
        with self.assertRaises(ValueError):
            transfer(item=self.item, from_warehouse=self.wh_a,
                     to_warehouse=self.wh_a, quantity=1, date=self.d)

    def test_transfer_over_stock_raises(self):
        with self.assertRaises(ValueError):
            transfer(item=self.item, from_warehouse=self.wh_b,
                     to_warehouse=self.wh_a, quantity=1, date=self.d)

    def test_transfer_view(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('inventory:transfer'), {
            'item_id': self.item.pk, 'from_warehouse_id': self.wh_a.pk,
            'to_warehouse_id': self.wh_b.pk, 'quantity': '3',
            'date': '1404/07/24'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(current_stock(self.item, self.wh_b), Decimal('3'))


class ValuationTests(TestCase):
    def setUp(self):
        self.item = Item.objects.create(code='V1', name='کالای ارزشی', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار مرکزی')
        self.d = jdatetime.date(1404, 7, 24)

    def test_weighted_avg_price(self):
        receive(item=self.item, warehouse=self.wh, quantity=10, date=self.d,
                unit_price=1000, category=StockMove.Category.PURCHASE)
        receive(item=self.item, warehouse=self.wh, quantity=5, date=self.d,
                unit_price=2000, category=StockMove.Category.PURCHASE)
        self.assertEqual(weighted_avg_price(self.item), Decimal('1333'))

    def test_inventory_value(self):
        receive(item=self.item, warehouse=self.wh, quantity=10, date=self.d,
                unit_price=1000, category=StockMove.Category.PURCHASE)
        data = inventory_value()
        self.assertEqual(data['total'], Decimal('10000'))

    def test_value_view(self):
        from django.contrib.auth.models import Permission
        user = User.objects.create_user('u1', password='x')
        self.client.force_login(user)
        resp = self.client.get(reverse('inventory:value'))
        self.assertEqual(resp.status_code, 200)


class MovementTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item = Item.objects.create(code='M1', name='کالای گردش', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار مرکزی')

    def test_movement_opening_balance(self):
        receive(item=self.item, warehouse=self.wh, quantity=10,
                date=jdatetime.date(1404, 7, 1))
        receive(item=self.item, warehouse=self.wh, quantity=5,
                date=jdatetime.date(1404, 7, 20))
        report = movement_report(self.item,
                                 jdatetime.date(1404, 7, 15),
                                 jdatetime.date(1404, 7, 30))
        self.assertEqual(report['opening'], Decimal('10'))
        self.assertEqual(len(report['rows']), 1)
        self.assertEqual(report['rows'][0]['balance'], Decimal('15'))

    def test_movement_view_and_print(self):
        move = receive(item=self.item, warehouse=self.wh, quantity=2,
                       date=jdatetime.date(1404, 7, 24))
        self.client.force_login(self.user)
        resp = self.client.get(
            reverse('inventory:movement', args=[self.item.pk]))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.get(
            reverse('inventory:move_print', args=[move.pk]))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'سند')
