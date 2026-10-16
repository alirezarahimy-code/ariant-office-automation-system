"""آزمون‌های انبارگردانی حرفه‌ای: تگ، چندمرحله، اکسل و سند اصلاحی."""
from decimal import Decimal
from io import BytesIO

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.core.files.uploadedfile import SimpleUploadedFile
from django.test import TestCase
from django.urls import reverse

from apps.core.services import clear_feature_cache

from .models import (CountTag, Item, StockCount, StockCountLine, StockDocument,
                     StockMove, Warehouse)
from .services import (apply_count, current_stock, generate_count_tags,
                       import_count_rows, receive)

User = get_user_model()


class CountProTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('u1', password='x')
        self.user.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))
        self.item = Item.objects.create(code='K1', name='کالا', unit='عدد')
        self.wh = Warehouse.objects.create(name='انبار')
        self.d = jdatetime.date(1404, 7, 30)
        receive(item=self.item, warehouse=self.wh, quantity=20, date=self.d)

    def tearDown(self):
        clear_feature_cache()

    def test_apply_creates_adjust_document_with_moves(self):
        count = StockCount.objects.create(
            warehouse=self.wh, date=self.d, created_by=self.user)
        StockCountLine.objects.create(
            count=count, item=self.item, counted_quantity=Decimal('18'))
        apply_count(count, created_by=self.user)
        count.refresh_from_db()
        self.assertEqual(count.status, StockCount.Status.APPLIED)
        doc = StockDocument.objects.get(doc_type='adjust')
        self.assertEqual(doc.category, 'adjustment')
        move = StockMove.objects.get(kind='adjust')
        self.assertEqual(move.quantity, Decimal('-2'))
        self.assertEqual(move.document_id, doc.pk)
        self.assertEqual(current_stock(self.item), Decimal('18'))
        line = count.lines.get()
        self.assertEqual(line.expected_quantity, Decimal('20'))

    def test_apply_twice_raises(self):
        count = StockCount.objects.create(warehouse=self.wh, date=self.d)
        apply_count(count)
        with self.assertRaises(ValueError):
            apply_count(count)

    def test_count_auto_docs_toggle_off(self):
        from apps.core.models import FeatureToggle
        FeatureToggle.objects.create(
            code='inventory.count_auto_docs', title='سند خودکار', group='انبار',
            is_enabled=False)
        clear_feature_cache()
        count = StockCount.objects.create(warehouse=self.wh, date=self.d)
        StockCountLine.objects.create(
            count=count, item=self.item, counted_quantity=Decimal('15'))
        apply_count(count)
        self.assertFalse(StockMove.objects.filter(kind='adjust').exists())
        self.assertEqual(current_stock(self.item), Decimal('20'))  # بدون اثر

    def test_generate_count_tags(self):
        count = StockCount.objects.create(warehouse=self.wh, date=self.d)
        tags = generate_count_tags(count, quantity=5)
        self.assertEqual(len(tags), 5)
        self.assertEqual(tags[0].code, f'T{count.pk}-0001')
        self.assertEqual(CountTag.objects.filter(count=count).count(), 5)

    def test_import_count_rows_two_stages(self):
        count = StockCount.objects.create(warehouse=self.wh, date=self.d)
        updated = import_count_rows(count, [(self.item, 17)], stage=1)
        self.assertEqual(updated, 1)
        import_count_rows(count, [(self.item, 18)], stage=2)
        line = count.lines.get()
        self.assertEqual(line.stage1_quantity, Decimal('17'))
        self.assertEqual(line.stage2_quantity, Decimal('18'))
        self.assertEqual(line.counted_quantity, Decimal('18'))

    def test_count_new_view_with_tags(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('inventory:count_new'), {
            'warehouse_id': self.wh.pk, 'date': '1404/07/30',
            'title': 'شمارش پایان ماه', 'tag_count': '4'})
        self.assertEqual(resp.status_code, 302)
        count = StockCount.objects.get(title='شمارش پایان ماه')
        self.assertEqual(count.status, 'counting')
        self.assertEqual(count.tags.count(), 4)
        resp = self.client.get(reverse('inventory:count_detail', args=[count.pk]))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.get(reverse('inventory:count_tags_print', args=[count.pk]))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, '<svg')

    def test_count_line_save_stages(self):
        count = StockCount.objects.create(warehouse=self.wh, date=self.d)
        self.client.force_login(self.user)
        self.client.post(reverse('inventory:count_line_save', args=[count.pk]), {
            'item_id': self.item.pk, 'quantity': '19', 'stage': '1'})
        self.client.post(reverse('inventory:count_line_save', args=[count.pk]), {
            'item_id': self.item.pk, 'quantity': '18', 'stage': '2'})
        line = count.lines.get()
        self.assertEqual(line.stage1_quantity, Decimal('19'))
        self.assertEqual(line.stage2_quantity, Decimal('18'))
        self.assertEqual(line.counted_quantity, Decimal('18'))

    def test_count_import_excel(self):
        from openpyxl import Workbook
        count = StockCount.objects.create(warehouse=self.wh, date=self.d)
        workbook = Workbook()
        sheet = workbook.active
        sheet.append(['کد کالا', 'تعداد'])
        sheet.append([self.item.code, 16])
        buffer = BytesIO()
        workbook.save(buffer)
        buffer.seek(0)
        self.client.force_login(self.user)
        resp = self.client.post(
            reverse('inventory:count_import', args=[count.pk]),
            {'file': SimpleUploadedFile('count.xlsx', buffer.read(),
                                        content_type='application/vnd.ms-excel'),
             'stage': '1'})
        self.assertEqual(resp.status_code, 302)
        line = count.lines.get()
        self.assertEqual(line.counted_quantity, Decimal('16'))

    def test_count_apply_view_creates_adjust_doc(self):
        count = StockCount.objects.create(warehouse=self.wh, date=self.d)
        StockCountLine.objects.create(
            count=count, item=self.item, counted_quantity=Decimal('12'))
        self.client.force_login(self.user)
        resp = self.client.post(
            reverse('inventory:count_apply_view', args=[count.pk]))
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(current_stock(self.item), Decimal('12'))
        self.assertTrue(StockDocument.objects.filter(doc_type='adjust').exists())
