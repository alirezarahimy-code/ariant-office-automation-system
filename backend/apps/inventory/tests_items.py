"""آزمون‌های کارت کالا: گروه درختی، واحدها، مشخصات، بارکد، سریال و محل استقرار."""
from decimal import Decimal

import jdatetime
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.core.files.uploadedfile import SimpleUploadedFile
from django.test import TestCase
from django.urls import reverse

from apps.core.services import clear_feature_cache

from .barcode import code39_svg
from .models import (Item, ItemGroup, ItemSpec, ItemUnit, SerialNumber,
                     StockDocument, Warehouse, WarehouseLocation)
from .services import confirm_document, create_document, current_stock

User = get_user_model()


class ItemGroupTreeTests(TestCase):
    def test_tree_paths_and_descendants(self):
        root = ItemGroup.objects.create(name='لوازم التحریر')
        child = ItemGroup.objects.create(name='کاغذ', parent=root)
        grand = ItemGroup.objects.create(name='کاغذ A4', parent=child)
        root.refresh_from_db()
        child.refresh_from_db()
        grand.refresh_from_db()
        self.assertEqual(root.path, f'/{root.pk}/')
        self.assertEqual(child.path, f'/{root.pk}/{child.pk}/')
        self.assertEqual(grand.level, 2)
        self.assertIn(child, root.get_descendants())
        self.assertIn(grand, root.get_descendants())
        self.assertEqual(sorted(root.get_descendant_ids()),
                         sorted([root.pk, child.pk, grand.pk]))
        self.assertEqual(grand.full_name, 'لوازم التحریر / کاغذ / کاغذ A4')


class MultiUnitTests(TestCase):
    def setUp(self):
        self.item = Item.objects.create(code='K1', name='کاغذ', unit='بسته')
        self.wh = Warehouse.objects.create(name='انبار')
        self.d = jdatetime.date(1404, 7, 30)
        ItemUnit.objects.create(item=self.item, name='کارتن', ratio=12)

    def test_line_unit_ratio_converts_to_base(self):
        doc = create_document(
            doc_type='receive', warehouse=self.wh, date=self.d, finalize=True,
            lines=[{'item': self.item, 'quantity': 2, 'unit_name': 'کارتن'}])
        move = doc.moves.get()
        self.assertEqual(move.quantity, Decimal('24'))  # 2 کارتن × 12 بسته

    def test_unknown_unit_raises(self):
        with self.assertRaises(ValueError):
            create_document(
                doc_type='receive', warehouse=self.wh, date=self.d, finalize=True,
                lines=[{'item': self.item, 'quantity': 1, 'unit_name': 'پالت'}])


class ItemSpecAndImageTests(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user('mgr', password='x')
        self.manager.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))
        self.group = ItemGroup.objects.create(name='اداری')

    def test_item_new_with_specs_units_image(self):
        self.client.force_login(self.manager)
        img = SimpleUploadedFile('kala.png', b'\x89PNG\r\n\x1a\n' + b'\x00' * 20,
                                 content_type='image/png')
        resp = self.client.post(reverse('inventory:item_new'), {
            'name': 'کاغذ A4', 'unit': 'بسته', 'group': self.group.pk,
            'barcode': '123456', 'min_stock': '3', 'reorder_point': '10',
            'max_stock': '100', 'track_serial': 'on', 'has_expiry': 'on',
            'costing_method': 'fifo',
            'unit_name_0': 'کارتن', 'unit_ratio_0': '12',
            'spec_title_0': 'وزن', 'spec_value_0': '80 گرم',
            'image': img,
        })
        self.assertEqual(resp.status_code, 302)
        item = Item.objects.get(name='کاغذ A4')
        self.assertTrue(item.code.startswith('K-'))  # کدگذاری خودکار
        self.assertEqual(item.barcode, '123456')
        self.assertEqual(item.reorder_point, Decimal('10'))
        self.assertTrue(item.track_serial)
        self.assertEqual(item.costing_method, 'fifo')
        self.assertEqual(item.units.get().ratio, Decimal('12'))
        self.assertEqual(item.specs.get().title, 'وزن')
        self.assertEqual(item.group_id, self.group.pk)

    def test_item_edit_view(self):
        item = Item.objects.create(code='K9', name='قلم', unit='عدد')
        self.client.force_login(self.manager)
        resp = self.client.post(reverse('inventory:item_edit', args=[item.pk]), {
            'name': 'قلم قرمز', 'unit': 'عدد', 'is_active': 'on',
        })
        self.assertEqual(resp.status_code, 302)
        item.refresh_from_db()
        self.assertEqual(item.name, 'قلم قرمز')

    def test_auto_code_sequence(self):
        self.assertEqual(Item.next_code(), 'K-0001')
        Item.objects.create(code='K-0001', name='اول')
        self.assertEqual(Item.next_code(), 'K-0002')


class BarcodeTests(TestCase):
    def test_code39_svg_contains_barcode(self):
        svg = code39_svg('K-0001')
        self.assertIn('<svg', svg)
        self.assertIn('</svg>', svg)

    def test_labels_view(self):
        user = User.objects.create_user('u1', password='x')
        Item.objects.create(code='K1', name='کالا', barcode='998877')
        self.client.force_login(user)
        resp = self.client.get(reverse('inventory:barcode_labels'))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, '998877')


class SerialExpiryTests(TestCase):
    def setUp(self):
        self.item = Item.objects.create(code='S1', name='دوربین', unit='عدد',
                                        track_serial=True, has_expiry=True)
        self.wh = Warehouse.objects.create(name='انبار')
        self.d = jdatetime.date(1404, 7, 30)

    def test_serials_created_on_receive_marked_out_on_issue(self):
        create_document(
            doc_type='receive', warehouse=self.wh, date=self.d, finalize=True,
            lines=[{'item': self.item, 'quantity': 2,
                    'serials_raw': 'SN-001\nSN-002'}])
        self.assertEqual(SerialNumber.objects.filter(
            item=self.item, status='in_stock').count(), 2)
        create_document(
            doc_type='issue', warehouse=self.wh, date=self.d, finalize=True,
            lines=[{'item': self.item, 'quantity': 1, 'serials_raw': 'SN-001'}])
        self.assertEqual(SerialNumber.objects.filter(
            item=self.item, status='out').count(), 1)
        self.assertEqual(SerialNumber.objects.filter(
            item=self.item, status='in_stock').count(), 1)

    def test_expiry_on_line_and_report(self):
        import jdatetime as jd
        create_document(
            doc_type='receive', warehouse=self.wh, date=self.d, finalize=True,
            lines=[{'item': self.item, 'quantity': 3, 'batch_no': 'B-9',
                    'expiry_date': jd.date(1406, 1, 15)}])
        from .services import expiry_report
        rows = expiry_report()
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['batch_no'], 'B-9')
        self.assertGreater(rows[0]['days_left'], 0)


class WarehouseLocationTests(TestCase):
    def test_location_on_line_and_default(self):
        wh = Warehouse.objects.create(name='انبار')
        loc = WarehouseLocation.objects.create(warehouse=wh, code='A-1', name='قفسه اول')
        item = Item.objects.create(code='L1', name='کالا', default_location=loc)
        doc = create_document(
            doc_type='receive', warehouse=wh, date=jdatetime.date(1404, 7, 30),
            finalize=True, lines=[{'item': item, 'quantity': 1}])
        self.assertEqual(doc.lines.get().location_id, loc.pk)

    def test_warehouse_users_visibility(self):
        from .services import visible_warehouses
        user = User.objects.create_user('u1', password='x')
        wh1 = Warehouse.objects.create(name='انبار ۱')
        wh2 = Warehouse.objects.create(name='انبار ۲')
        wh1.users.add(user)
        visible = visible_warehouses(user)
        self.assertIn(wh1, visible)
        self.assertIn(wh2, visible)  # بدون انباردار = بدون محدودیت
        wh2.users.add(User.objects.create_user('u2', password='x'))
        visible = visible_warehouses(user)
        self.assertIn(wh1, visible)
        self.assertNotIn(wh2, visible)
        # مدیر کل همه را می‌بیند
        manager = User.objects.create_user('mgr', password='x')
        manager.user_permissions.add(
            Permission.objects.get(codename='manage_inventory'))
        self.assertEqual(len(visible_warehouses(manager)), 2)
