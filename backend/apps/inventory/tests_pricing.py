"""آزمون‌های قیمت‌گذاری: لیست قیمت، قیمت‌گذاری دسته‌ای و FIFO."""
from decimal import Decimal

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from apps.core.services import clear_feature_cache

from .models import Item, PriceList, PriceListItem, StockLayer, Warehouse
from .services import (apply_batch_pricing, create_document, current_stock,
                       fifo_value, inventory_value, set_item_price,
                       weighted_avg_price)

User = get_user_model()


class PriceListTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.pl = PriceList.objects.create(name='قیمت فروش')

    def tearDown(self):
        clear_feature_cache()

    def test_set_item_price(self):
        set_item_price(self.pl, self.item, 1500)
        set_item_price(self.pl, self.item, 1800)  # به‌روزرسانی
        self.assertEqual(self.pl.entries.count(), 1)
        self.assertEqual(self.pl.entries.get().price, Decimal('1800'))

    def test_batch_pricing_percent(self):
        set_item_price(self.pl, self.item, 1000)
        item2 = Item.objects.create(code='K2', name='کالای دوم', unit='عدد')
        set_item_price(self.pl, item2, 2000)
        count = apply_batch_pricing(self.pl, 10)  # افزایش ۱۰٪
        self.assertEqual(count, 2)
        self.assertEqual(self.pl.entries.get(item=self.item).price, Decimal('1100'))
        self.assertEqual(self.pl.entries.get(item=item2).price, Decimal('2200'))
        apply_batch_pricing(self.pl, -10)  # کاهش ۱۰٪
        self.assertEqual(self.pl.entries.get(item=self.item).price, Decimal('990'))

    def test_price_list_views(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('inventory:price_lists'))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.get(reverse('inventory:price_list_detail', args=[self.pl.pk]))
        self.assertEqual(resp.status_code, 200)
        manager = User.objects.create_user('mgr', password='x')
        manager.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))
        self.client.force_login(manager)
        resp = self.client.post(
            reverse('inventory:price_list_detail', args=[self.pl.pk]),
            {'action': 'set', 'item_id': self.item.pk, 'price': '5000'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(self.pl.entries.get().price, Decimal('5000'))

    def test_batch_toggle_disabled(self):
        from apps.core.models import FeatureToggle
        FeatureToggle.objects.create(
            code='inventory.batch_pricing', title='دسته‌ای', group='انبار',
            is_enabled=False)
        clear_feature_cache()
        with self.assertRaises(ValueError):
            apply_batch_pricing(self.pl, 5)


class FifoTests(TestCase):
    def setUp(self):
        self.item = Item.objects.create(code='F1', name='کالای FIFO', unit='عدد',
                                        costing_method='fifo')
        self.wh = Warehouse.objects.create(name='انبار')
        self.d = jdatetime.date(1404, 7, 30)

    def tearDown(self):
        clear_feature_cache()

    def test_fifo_layers_created_on_receive(self):
        create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 1), finalize=True,
            lines=[{'item': self.item, 'quantity': 10, 'unit_price': 1000}])
        create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 15), finalize=True,
            lines=[{'item': self.item, 'quantity': 5, 'unit_price': 2000}])
        self.assertEqual(StockLayer.objects.filter(item=self.item).count(), 2)
        self.assertEqual(fifo_value(self.item), Decimal('20000'))

    def test_fifo_issue_consumes_oldest_first(self):
        create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 1), finalize=True,
            lines=[{'item': self.item, 'quantity': 10, 'unit_price': 1000}])
        create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 15), finalize=True,
            lines=[{'item': self.item, 'quantity': 5, 'unit_price': 2000}])
        doc = create_document(
            doc_type='issue', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 20), finalize=True,
            lines=[{'item': self.item, 'quantity': 12}])
        move = doc.moves.get()
        # بهای خروج = (10×1000 + 2×2000)/12 = 1167
        self.assertEqual(move.unit_price, Decimal('1167'))
        self.assertEqual(current_stock(self.item), Decimal('3'))
        self.assertEqual(fifo_value(self.item), Decimal('6000'))  # 3 × 2000

    def test_fifo_value_in_inventory_value(self):
        create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 1), finalize=True,
            lines=[{'item': self.item, 'quantity': 4, 'unit_price': 2500}])
        data = inventory_value()
        self.assertEqual(data['total'], Decimal('10000'))

    def test_weighted_avg_still_works_for_average_items(self):
        item2 = Item.objects.create(code='A1', name='میانگین', unit='عدد')
        create_document(
            doc_type='receive', warehouse=self.wh,
            date=self.d, finalize=True,
            lines=[{'item': item2, 'quantity': 10, 'unit_price': 1000},
                   {'item': item2, 'quantity': 5, 'unit_price': 2000}])
        self.assertEqual(weighted_avg_price(item2), Decimal('1333'))
        self.assertEqual(StockLayer.objects.count(), 0)

    def test_cancel_receive_removes_layers(self):
        from .services import cancel_document
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d, finalize=True,
            lines=[{'item': self.item, 'quantity': 10, 'unit_price': 1000}])
        self.assertEqual(StockLayer.objects.count(), 1)
        cancel_document(doc)
        self.assertEqual(StockLayer.objects.count(), 0)


class LifoAndValuePermissionTests(TestCase):
    def setUp(self):
        self.item = Item.objects.create(code='L1', name='کالای LIFO', unit='عدد',
                                        costing_method='lifo')
        self.wh = Warehouse.objects.create(name='انبار')

    def tearDown(self):
        clear_feature_cache()

    def test_lifo_consumes_newest_first(self):
        create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 1), finalize=True,
            lines=[{'item': self.item, 'quantity': 10, 'unit_price': 1000}])
        create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 15), finalize=True,
            lines=[{'item': self.item, 'quantity': 5, 'unit_price': 2000}])
        doc = create_document(
            doc_type='issue', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 20), finalize=True,
            lines=[{'item': self.item, 'quantity': 3}])
        move = doc.moves.get()
        self.assertEqual(move.unit_price, Decimal('2000'))  # تازه‌ترین لایه اول
        self.assertEqual(fifo_value(self.item), Decimal('14000'))  # 10×1000 + 2×2000

    def test_value_view_hidden_without_permission(self):
        from django.contrib.auth.models import Permission
        user = User.objects.create_user('u1', password='x')
        self.client.force_login(user)
        resp = self.client.get(reverse('inventory:value'))
        self.assertEqual(resp.status_code, 200)
        self.assertFalse(resp.context['show_value'])
        user.user_permissions.add(
            Permission.objects.get(codename='view_inventory_value'))
        user = User.objects.get(pk=user.pk)  # پاک‌سازی کش مجوز
        self.client.force_login(user)
        resp = self.client.get(reverse('inventory:value'))
        self.assertTrue(resp.context['show_value'])

    def test_consignment_category_allowed(self):
        doc = create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 30), category='consignment_in',
            finalize=True, lines=[{'item': self.item, 'quantity': 1}])
        self.assertEqual(doc.category, 'consignment_in')
        self.assertEqual(doc.moves.get().category, 'consignment_in')
