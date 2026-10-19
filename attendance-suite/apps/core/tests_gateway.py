"""آزمون‌های دروازه چندبرند: آداپتور ZK + پل یونیس (بانک/فایل) — بدون سرویس ویندوزی."""
import os
import shutil
import sqlite3
import sys
import tempfile

from django.db import connections
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import Punch
from apps.device.gateway import run_gateway
from apps.device.gateway.unis_adapter import BridgeError, sync_bridge, _ident
from apps.device.models import Device, RawRecord, UnisBridge
from apps.org.models import Employee


class UnisDirModeTests(TestCase):
    """حالت پوشه خروجی یونیس/USB."""

    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, self.dir, ignore_errors=True)
        self.employee = Employee.objects.create(
            badge_no='1001', first_name='م', last_name='ت')
        self.device = Device.objects.create(
            name='Virdi-UNIS', serial='UNIS-V1', brand=Device.Brand.VIRDI)
        self.bridge = UnisBridge.objects.create(
            name='یونیس دفتر مرکزی', device=self.device,
            mode=UnisBridge.Mode.DIR, dir_path=self.dir)

    def _write(self, name, text):
        with open(os.path.join(self.dir, name), 'w', encoding='utf-8') as fh:
            fh.write(text)

    def test_pull_creates_records_and_punches(self):
        self._write('log1.csv',
                    'pin,date,time\n'
                    '1001,2026-10-20,08:00:00\n'
                    '1001,2026-10-20,17:05:00\n')
        result = sync_bridge(self.bridge)
        self.assertEqual(result['created'], 2)
        self.assertEqual(RawRecord.objects.count(), 2)
        self.assertEqual(Punch.objects.count(), 2)
        # فایل مصرف‌شده علامت می‌خورد
        self.assertTrue(os.path.exists(
            os.path.join(self.dir, 'log1.csv.imported')))

    def test_pull_is_idempotent(self):
        self._write('log1.csv', '1001,2026-10-20,08:00:00\n')
        first = sync_bridge(self.bridge)
        self.assertEqual(first['created'], 1)
        # همان فایل دوباره (پس از مصرف نشدن — دستی بنویسیم)
        self._write('log2.csv', '1001,2026-10-20,08:00:00\n')
        second = sync_bridge(self.bridge)
        self.assertEqual(second['created'], 0)  # ضدتکرار
        self.assertEqual(second['skipped'], 1)

    def test_jalali_date_supported(self):
        import jdatetime
        self._write('j.csv', '1001,1405-07-28,08:00:00\n')
        sync_bridge(self.bridge)
        expected = jdatetime.date(1405, 7, 28).togregorian()
        self.assertEqual(Punch.objects.get().date, expected)

    def test_missing_dir_rejected(self):
        self.bridge.dir_path = '/nonexistent-unis-dir'
        self.bridge.save()
        with self.assertRaises(BridgeError):
            sync_bridge(self.bridge)


class UnisDbModeTests(TestCase):
    """حالت خواندن از بانک یونیس (اینجا: SQLite test-alias به‌عنوان نمایندهٔ بانک)."""

    databases = {'default', 'unis'}

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls._fill_db()

    @classmethod
    def _fill_db(cls, rows=(('2001', '2026-10-20 08:00:00', 'I'),
                            ('2001', '2026-10-20 17:05:00', 'O'))):
        with connections['unis'].cursor() as cur:
            cur.execute('DROP TABLE IF EXISTS T_LOG')
            cur.execute(
                'CREATE TABLE T_LOG (USER_ID TEXT, ATT_TIME TEXT, IN_OUT TEXT)')
            for row in rows:
                cur.execute('INSERT INTO T_LOG VALUES (?,?,?)', row)

    def _add_rows(self, rows):
        self._fill_db(rows=rows)

    def setUp(self):
        self.employee = Employee.objects.create(
            badge_no='2001', first_name='الف', last_name='ب')
        self.device = Device.objects.create(
            name='Virdi-UNIS-DB', serial='UNIS-DB1', brand=Device.Brand.VIRDI)
        self.bridge = UnisBridge.objects.create(
            name='بانک یونیس', device=self.device, mode=UnisBridge.Mode.DB,
            table_name='T_LOG', col_pin='USER_ID', col_time='ATT_TIME',
            col_direction='IN_OUT')

    def test_pull_reads_and_advances_watermark(self):
        result = sync_bridge(self.bridge)
        self.assertEqual(result['created'], 2)
        self.assertEqual(Punch.objects.count(), 2)
        self.assertEqual(self.bridge.last_watermark, '2026-10-20 17:05:00')
        result2 = sync_bridge(self.bridge)
        self.assertEqual(result2['created'], 0)

    def test_new_rows_picked_up(self):
        sync_bridge(self.bridge)
        self._add_rows(rows=(('2001', '2026-10-20 08:00:00', 'I'),
                             ('2001', '2026-10-20 17:05:00', 'O'),
                             ('2001', '2026-10-21 08:10:00', 'I')))
        result = sync_bridge(self.bridge)
        self.assertEqual(result['created'], 1)

    def test_missing_table_rejected(self):
        # منبع بدون جدول (پیکربندی نشده/خراب) → خطای فارسی، نه کرش
        self.bridge.table_name = 'T_MISSING'
        self.bridge.save()
        with self.assertRaises(BridgeError) as ctx:
            sync_bridge(self.bridge)
        self.assertIn('یونیس', str(ctx.exception))

    def test_sql_identifiers_validated(self):
        self.bridge.table_name = 'T_LOG; DROP TABLE users'
        self.bridge.save()
        with self.assertRaises(BridgeError):
            sync_bridge(self.bridge)
        with self.assertRaises(BridgeError):
            _ident('col--name', 'ستون')
        self.assertEqual(_ident('schema.T_LOG', 'جدول'), 'schema.T_LOG')


class ZkGatewayTests(TestCase):
    """آداپتور ZK + رفتار دروازه در خطا (بدون توقف بقیه)."""

    def test_zk_device_without_ip_reports_error(self):
        tmpdir = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, tmpdir, ignore_errors=True)
        Device.objects.create(name='ZK1', serial='ZK-1', brand=Device.Brand.ZK)
        UnisBridge.objects.create(
            name='پل فعال', mode=UnisBridge.Mode.DIR,
            device=Device.objects.create(name='V', serial='V-1',
                                         brand=Device.Brand.VIRDI),
            dir_path=tmpdir)
        result = run_gateway()
        self.assertEqual(len(result['zk']), 1)
        self.assertIn('error', result['zk'][0])
        self.assertIn('آی‌پی', result['zk'][0]['error'])

    def test_brand_default_is_zk(self):
        d = Device.objects.create(name='X', serial='X-1')
        self.assertEqual(d.brand, Device.Brand.ZK)


class UnisViewTests(TestCase):
    def setUp(self):
        from apps.core.models import User
        self.admin = User.objects.create_superuser('admin', password='a')
        self.client.force_login(self.admin)

    def test_page_loads_and_creates_bridge(self):
        device = Device.objects.create(name='V', serial='V-2',
                                       brand=Device.Brand.VIRDI)
        resp = self.client.get(reverse('device:unis_bridge'))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.post(reverse('device:unis_bridge'), {
            'name': 'پل جدید', 'device_id': device.pk, 'mode': 'dir',
            'dir_path': '/tmp/x', 'is_active': 'on'})
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(UnisBridge.objects.filter(name='پل جدید').exists())

    def test_sync_button_runs(self):
        device = Device.objects.create(name='V', serial='V-3',
                                       brand=Device.Brand.VIRDI)
        bridge = UnisBridge.objects.create(
            name='p', device=device, mode=UnisBridge.Mode.DIR,
            dir_path=tempfile.mkdtemp())
        resp = self.client.post(reverse('device:unis_sync', args=[bridge.pk]))
        self.assertEqual(resp.status_code, 302)

    def test_requires_permission(self):
        from apps.core.models import User
        user = User.objects.create_user('u2', password='x')
        self.client.force_login(user)
        resp = self.client.get(reverse('device:unis_bridge'))
        self.assertIn(resp.status_code, (302, 403))


class UnisAccessMdbTests(TestCase):
    """بانک Access یونیس (unis.mdb) — با pyodbc ساختگی (بدون درایور واقعی)."""

    def setUp(self):
        self.employee = Employee.objects.create(
            badge_no='3001', first_name='و', last_name='ی')
        self.device = Device.objects.create(
            name='Virdi-MDB', serial='UNIS-MDB', brand=Device.Brand.VIRDI)
        self.bridge = UnisBridge.objects.create(
            name='unis.mdb', device=self.device, mode=UnisBridge.Mode.DB,
            table_name='T_LOG', col_pin='USER_ID', col_time='ATT_TIME')

    def _fake_pyodbc(self, rows):
        """ماژول pyodbc ساختگی که رشته اتصال و SQL را ضبط می‌کند."""
        import sys
        from types import ModuleType, SimpleNamespace
        captured = {}
        mod = ModuleType('pyodbc')

        def connect(conn_str, autocommit=False):
            captured['conn_str'] = conn_str
            captured['autocommit'] = autocommit

            class Cursor:
                def execute(self, sql, params=None):
                    captured['sql'] = sql
                    captured['params'] = params

                def fetchall(self):
                    return rows

            class Conn:
                def cursor(self):
                    return Cursor()

                def close(self):
                    captured['closed'] = True
            return Conn()
        mod.connect = connect
        return mod, captured

    def test_access_pull_uses_password_and_top(self):
        from unittest import mock
        from django.conf import settings
        mod, captured = self._fake_pyodbc(
            [('3001', '2026-10-20 08:00:00'), ('3001', '2026-10-20 17:05:00')])
        cfg = {'name': r'C:\UNIS\unis.mdb', 'password': 'env-only-secret'}
        with mock.patch.dict(sys.modules, {'pyodbc': mod}), \
                mock.patch.object(settings, 'UNIS_ACCESS', cfg, create=True):
            from apps.device.gateway.unis_adapter import sync_bridge
            result = sync_bridge(self.bridge)
        self.assertEqual(result['created'], 2)
        self.assertIn('PWD=env-only-secret', captured['conn_str'])
        self.assertIn('unis.mdb', captured['conn_str'])
        self.assertIn('TOP 5000', captured['sql'])
        self.assertIn('WHERE ATT_TIME >= ?', captured['sql'])
        self.assertTrue(captured['closed'])
        # رمز هرگز در نتیجه/نشانگر ثبت نمی‌شود
        self.assertNotIn('secret', self.bridge.last_result)
        self.assertNotIn('secret', self.bridge.last_watermark)

    def test_access_requires_path(self):
        from unittest import mock
        from django.conf import settings
        mod, _ = self._fake_pyodbc([])
        with mock.patch.dict(sys.modules, {'pyodbc': mod}), \
                mock.patch.object(settings, 'UNIS_ACCESS', {'name': ''},
                                  create=True):
            from apps.device.gateway.unis_adapter import sync_bridge
            with self.assertRaises(BridgeError) as ctx:
                sync_bridge(self.bridge)
        self.assertIn('UNIS_DB_NAME', str(ctx.exception))

    def test_access_missing_pyodbc_graceful(self):
        import builtins
        from unittest import mock
        from django.conf import settings
        real_import = builtins.__import__

        def fake_import(name, *args, **kwargs):
            if name == 'pyodbc':
                raise ImportError('no pyodbc')
            return real_import(name, *args, **kwargs)
        with mock.patch.object(builtins, '__import__', fake_import), \
                mock.patch.object(
                    settings, 'UNIS_ACCESS',
                    {'name': 'x.mdb', 'password': ''}, create=True):
            from apps.device.gateway.unis_adapter import sync_bridge
            with self.assertRaises(BridgeError) as ctx:
                sync_bridge(self.bridge)
        self.assertIn('pyodbc', str(ctx.exception))

    def test_access_rejects_bad_identifiers_before_connect(self):
        from unittest import mock
        from django.conf import settings
        mod = mock.MagicMock()
        self.bridge.table_name = 'T_LOG); DROP'
        self.bridge.save()
        with mock.patch.dict(sys.modules, {'pyodbc': mod}), \
                mock.patch.object(
                    settings, 'UNIS_ACCESS',
                    {'name': 'x.mdb', 'password': ''}, create=True):
            from apps.device.gateway.unis_adapter import sync_bridge
            with self.assertRaises(BridgeError):
                sync_bridge(self.bridge)
        mod.connect.assert_not_called()
