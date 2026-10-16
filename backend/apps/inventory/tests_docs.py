"""آزمون‌های اسناد چندردیفی: شماره‌گذاری، موقت→قطعی، ابطال، برگشتی و کنترل‌ها."""
from decimal import Decimal

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from apps.core.services import clear_feature_cache

from .models import (InventoryPeriod, Item, StockDocument, StockDocumentLine,
                     StockMove, Warehouse)
from .services import (cancel_document, confirm_document, create_document,
                       create_return_document, current_stock, issue, receive,
                       transfer)

User = get_user_model()


class DocumentNumberingTests(TestCase):
    def setUp(self):
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار مرکزی')
        self.d = jdatetime.date(1404, 7, 30)

    def test_number_is_sequential_per_type_and_year(self):
        d1 = create_document(doc_type='receive', warehouse=self.wh, date=self.d,
                             lines=[{'item': self.item, 'quantity': 5}])
        d2 = create_document(doc_type='receive', warehouse=self.wh, date=self.d,
                             lines=[{'item': self.item, 'quantity': 3}])
        d3 = create_document(doc_type='issue', warehouse=self.wh, date=self.d,
                             lines=[{'item': self.item, 'quantity': 1}])
        self.assertEqual(d1.number, 'رس-1404-0001')
        self.assertEqual(d2.number, 'رس-1404-0002')
        self.assertEqual(d3.number, 'ح-1404-0001')

    def test_number_resets_next_year(self):
        d1 = create_document(doc_type='receive', warehouse=self.wh,
                             date=jdatetime.date(1404, 7, 30),
                             lines=[{'item': self.item, 'quantity': 5}])
        d2 = create_document(doc_type='receive', warehouse=self.wh,
                             date=jdatetime.date(1405, 1, 1),
                             lines=[{'item': self.item, 'quantity': 3}])
        self.assertEqual(d1.number, 'رس-1404-0001')
        self.assertEqual(d2.number, 'رس-1405-0001')


class MultiLineDocumentTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item1 = Item.objects.create(code='K1', name='کاغذ', unit='بسته')
        self.item2 = Item.objects.create(code='K2', name='خودکار', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار مرکزی')
        self.d = jdatetime.date(1404, 7, 30)

    def test_multiline_receive_confirm_creates_moves(self):
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d,
            created_by=self.user, category='purchase', finalize=False,
            lines=[{'item': self.item1, 'quantity': 10, 'unit_price': 1000},
                   {'item': self.item2, 'quantity': 20, 'unit_price': 500}])
        self.assertEqual(doc.status, StockDocument.Status.TEMPORARY)
        self.assertEqual(current_stock(self.item1), Decimal('0'))  # موقت اثر ندارد
        confirm_document(doc, confirmed_by=self.user)
        doc.refresh_from_db()
        self.assertEqual(doc.status, StockDocument.Status.FINAL)
        self.assertEqual(current_stock(self.item1), Decimal('10'))
        self.assertEqual(current_stock(self.item2), Decimal('20'))
        self.assertEqual(doc.moves.count(), 2)
        move = doc.moves.filter(item=self.item1).get()
        self.assertEqual(move.category, 'purchase')
        self.assertEqual(move.document_id, doc.pk)

    def test_temporary_doc_does_not_affect_stock(self):
        create_document(
            doc_type='issue', warehouse=self.wh, date=self.d,
            lines=[{'item': self.item1, 'quantity': 5}])
        self.assertEqual(current_stock(self.item1), Decimal('0'))

    def test_issue_over_stock_raises_on_confirm(self):
        receive(item=self.item1, warehouse=self.wh, quantity=2, date=self.d)
        doc = create_document(
            doc_type='issue', warehouse=self.wh, date=self.d,
            lines=[{'item': self.item1, 'quantity': 5}])
        # موقت ساخته می‌شود اما قطعی کردن باید خطا بدهد
        doc.status = StockDocument.Status.TEMPORARY
        doc.save()
        doc.moves.all().delete()
        with self.assertRaises(ValueError):
            confirm_document(doc)

    def test_transfer_document_two_moves(self):
        wh_b = Warehouse.objects.create(name='انبار دوم')
        receive(item=self.item1, warehouse=self.wh, quantity=10, date=self.d)
        doc = create_document(
            doc_type='transfer', warehouse=self.wh, to_warehouse=wh_b,
            date=self.d, category='transfer', finalize=True,
            lines=[{'item': self.item1, 'quantity': 4}])
        self.assertEqual(current_stock(self.item1, self.wh), Decimal('6'))
        self.assertEqual(current_stock(self.item1, wh_b), Decimal('4'))
        self.assertEqual(doc.moves.count(), 2)
        self.assertEqual(doc.moves.filter(warehouse=self.wh).get().kind, 'issue')
        self.assertEqual(doc.moves.filter(warehouse=wh_b).get().kind, 'receive')

    def test_adjust_document_signed_quantity(self):
        doc = create_document(
            doc_type='adjust', warehouse=self.wh, date=self.d, finalize=True,
            lines=[{'item': self.item1, 'quantity': -3}])
        self.assertEqual(current_stock(self.item1), Decimal('-3'))
        move = doc.moves.get()
        self.assertEqual(move.signed_quantity, Decimal('-3'))


class CancelAndReturnTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار مرکزی')
        self.d = jdatetime.date(1404, 7, 30)

    def test_cancel_final_doc_removes_stock_effect(self):
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d,
            created_by=self.user, finalize=True,
            lines=[{'item': self.item, 'quantity': 10}])
        self.assertEqual(current_stock(self.item), Decimal('10'))
        cancel_document(doc, reason='ثبت اشتباه', cancelled_by=self.user)
        doc.refresh_from_db()
        self.assertEqual(doc.status, StockDocument.Status.CANCELLED)
        self.assertEqual(current_stock(self.item), Decimal('0'))
        self.assertEqual(doc.moves.count(), 0)

    def test_cancel_temporary_raises(self):
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d,
            lines=[{'item': self.item, 'quantity': 1}])
        with self.assertRaises(ValueError):
            cancel_document(doc)

    def test_cancel_twice_raises(self):
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d, finalize=True,
            lines=[{'item': self.item, 'quantity': 1}])
        cancel_document(doc)
        with self.assertRaises(ValueError):
            cancel_document(doc)

    def test_return_document_mirrors_receive(self):
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d,
            category='purchase', finalize=True,
            lines=[{'item': self.item, 'quantity': 10, 'unit_price': 100}])
        ret = create_return_document(doc, created_by=self.user)
        self.assertEqual(ret.doc_type, StockDocument.DocType.ISSUE)
        self.assertEqual(ret.category, 'return_out')
        self.assertEqual(ret.reverses_id, doc.pk)
        self.assertEqual(current_stock(self.item), Decimal('0'))

    def test_return_document_mirrors_issue(self):
        receive(item=self.item, warehouse=self.wh, quantity=10, date=self.d)
        doc = create_document(
            doc_type='issue', warehouse=self.wh, date=self.d,
            category='sale', finalize=True,
            lines=[{'item': self.item, 'quantity': 6}])
        ret = create_return_document(doc, created_by=self.user)
        self.assertEqual(ret.doc_type, StockDocument.DocType.RECEIVE)
        self.assertEqual(ret.category, 'return_in')
        self.assertEqual(current_stock(self.item), Decimal('10'))

    def test_return_only_from_final(self):
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d,
            lines=[{'item': self.item, 'quantity': 1}])
        with self.assertRaises(ValueError):
            create_return_document(doc)


class CompatServiceTests(TestCase):
    """توابع کلاسیک receive/issue/transfer باید مثل قبل رفتار کنند."""

    def setUp(self):
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار مرکزی')
        self.d = jdatetime.date(1404, 7, 30)

    def test_receive_returns_move_with_document(self):
        move = receive(item=self.item, warehouse=self.wh, quantity=7,
                       date=self.d, category='purchase', unit_price=50)
        self.assertIsInstance(move, StockMove)
        self.assertIsNotNone(move.document)
        self.assertEqual(move.document.lines.count(), 1)
        self.assertEqual(current_stock(self.item), Decimal('7'))

    def test_issue_and_transfer_compat(self):
        wh_b = Warehouse.objects.create(name='انبار دوم')
        receive(item=self.item, warehouse=self.wh, quantity=10, date=self.d)
        issue(item=self.item, warehouse=self.wh, quantity=3, date=self.d)
        out_move, in_move = transfer(item=self.item, from_warehouse=self.wh,
                                     to_warehouse=wh_b, quantity=2, date=self.d)
        self.assertEqual(out_move.reference, in_move.reference)
        self.assertEqual(out_move.category, 'transfer')
        self.assertEqual(current_stock(self.item, self.wh), Decimal('5'))
        self.assertEqual(current_stock(self.item, wh_b), Decimal('2'))


class PeriodAndAlertTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.user.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد',
                                        min_stock=Decimal('5'),
                                        reorder_point=Decimal('8'))
        self.wh = Warehouse.objects.create(name='انبار مرکزی')
        self.d = jdatetime.date(1404, 7, 30)

    def tearDown(self):
        clear_feature_cache()

    def test_closed_period_blocks_confirm(self):
        InventoryPeriod.objects.create(year=1404, is_open=False)
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d,
            lines=[{'item': self.item, 'quantity': 1}])
        with self.assertRaises(ValueError):
            confirm_document(doc)

    def test_open_period_allows_confirm(self):
        InventoryPeriod.objects.create(year=1404, is_open=True)
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d,
            lines=[{'item': self.item, 'quantity': 1}], finalize=True)
        self.assertEqual(doc.status, StockDocument.Status.FINAL)

    def test_low_stock_notification_sent(self):
        from apps.core.models import Notification
        receive(item=self.item, warehouse=self.wh, quantity=2, date=self.d)
        self.assertTrue(Notification.objects.filter(
            user=self.user, title__contains='هشدار موجودی').exists())


class DocumentViewTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار مرکزی')

    def test_doc_new_get_and_post(self):
        self.client.force_login(self.manager)
        resp = self.client.get(reverse('inventory:doc_new'))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.post(reverse('inventory:doc_new'), {
            'doc_type': 'receive', 'category': 'purchase',
            'warehouse_id': self.wh.pk, 'date': '1404/07/30',
            'line_item_0': self.item.pk, 'line_quantity_0': '12',
            'line_price_0': '1000',
            'line_item_1': self.item.pk, 'line_quantity_1': '3',
            'finalize': '1',
        })
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(current_stock(self.item), Decimal('15'))

    def test_doc_list_and_detail_and_print(self):
        self.client.force_login(self.user)
        doc = create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 30), created_by=self.user, finalize=True,
            lines=[{'item': self.item, 'quantity': 2}])
        for name, args in [('inventory:doc_list', None),
                           ('inventory:doc_detail', [doc.pk]),
                           ('inventory:doc_print', [doc.pk])]:
            resp = self.client.get(reverse(name, args=args) if args else reverse(name))
            self.assertEqual(resp.status_code, 200)

    def test_doc_confirm_and_cancel_post(self):
        self.client.force_login(self.manager)
        doc = create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 30),
            lines=[{'item': self.item, 'quantity': 2}])
        resp = self.client.post(reverse('inventory:doc_confirm', args=[doc.pk]))
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(current_stock(self.item), Decimal('2'))
        resp = self.client.post(reverse('inventory:doc_cancel', args=[doc.pk]),
                                {'reason': 'اشتباه'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(current_stock(self.item), Decimal('0'))

    def test_doc_return_post(self):
        self.client.force_login(self.manager)
        doc = create_document(
            doc_type='receive', warehouse=self.wh,
            date=jdatetime.date(1404, 7, 30), created_by=self.manager, finalize=True,
            lines=[{'item': self.item, 'quantity': 4}])
        resp = self.client.post(reverse('inventory:doc_return', args=[doc.pk]))
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(current_stock(self.item), Decimal('0'))

    def test_doc_temp_toggle_off_auto_finalizes(self):
        from apps.core.models import FeatureToggle
        FeatureToggle.objects.create(
            code='inventory.doc_temp', title='موقت', group='انبار', is_enabled=False)
        clear_feature_cache()
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('inventory:doc_new'), {
            'doc_type': 'receive', 'warehouse_id': self.wh.pk,
            'date': '1404/07/30',
            'line_item_0': self.item.pk, 'line_quantity_0': '5',
        })
        self.assertEqual(resp.status_code, 302)
        doc = StockDocument.objects.latest('id')
        self.assertEqual(doc.status, StockDocument.Status.FINAL)
        self.assertEqual(current_stock(self.item), Decimal('5'))
