"""آزمون‌های دروازه چندبرند: آداپتور ZK + پل یونیس (بانک/فایل) — بدون سرویس ویندوزی."""
import os
import shutil
import sqlite3
import sys
import tempfile

from django.core.files.uploadedfile import SimpleUploadedFile
from django.db import connections
from django.test import TestCase
from django.urls import reverse

from apps.attendance.models import Punch
from apps.device.gateway import run_gateway
from apps.device.gateway.unis_adapter import (
    BridgeError, decode_unis_export, parse_unis_export, sync_bridge, _ident,
)
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

    def test_cp1256_export_uses_explicit_persian_direction(self):
        path = os.path.join(self.dir, 'persian.csv')
        with open(path, 'wb') as fh:
            fh.write('1001,2026-10-20,08:00:00,خروج\n'.encode('cp1256'))
        sync_bridge(self.bridge)
        self.assertEqual(Punch.objects.get().direction, Punch.Direction.OUT)
        self.assertTrue(os.path.exists(path + '.imported'))

    def test_bad_file_keeps_all_source_exports_unarchived(self):
        self._write('a-good.csv', '1001,2026-10-20,08:00:00\n')
        self._write('b-bad.csv', '1001,not-a-date,08:00:00\n')
        with self.assertRaises(BridgeError):
            sync_bridge(self.bridge)
        self.assertTrue(os.path.exists(os.path.join(self.dir, 'a-good.csv')))
        self.assertTrue(os.path.exists(os.path.join(self.dir, 'b-bad.csv')))
        self.assertEqual(RawRecord.objects.count(), 0)

    def test_directory_symlinks_are_rejected(self):
        source = os.path.join(self.dir, 'source.csv')
        with open(source, 'w', encoding='utf-8') as fh:
            fh.write('1001,2026-10-20,08:00:00\n')
        linked = os.path.join(self.dir, 'linked.csv')
        try:
            os.symlink(source, linked)
        except (OSError, NotImplementedError):
            self.skipTest('symlink creation is not available')
        with self.assertRaises(BridgeError):
            sync_bridge(self.bridge)
        self.assertEqual(RawRecord.objects.count(), 0)
        self.assertTrue(os.path.exists(source))

    def test_dir_batch_limit_rejects_without_archiving(self):
        self.bridge.batch_limit = 1
        self.bridge.save(update_fields=['batch_limit'])
        self._write('two.csv',
                    '1001,2026-10-20,08:00:00\n'
                    '1001,2026-10-20,17:00:00\n')
        with self.assertRaises(BridgeError):
            sync_bridge(self.bridge)
        self.assertTrue(os.path.exists(os.path.join(self.dir, 'two.csv')))
        self.assertEqual(RawRecord.objects.count(), 0)


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


class UnisUsbImportTests(TestCase):
    def setUp(self):
        from apps.core.models import User
        self.admin = User.objects.create_superuser('usb-admin', password='x')
        self.employee = Employee.objects.create(
            badge_no='5001', first_name='سارا', last_name='آزمایش',
            program_user=True)
        self.device = Device.objects.create(
            name='Virdi USB', serial='V-USB', brand=Device.Brand.VIRDI)
        self.bridge = UnisBridge.objects.create(
            name='خروجی دفتر', device=self.device, mode=UnisBridge.Mode.DIR,
            dir_path='/not-used-for-upload')
        self.client.force_login(self.admin)

    def test_preview_commit_keeps_explicit_unis_direction(self):
        upload = SimpleUploadedFile(
            'unis.csv',
            'pin,date,time,direction\n'
            '5001,2026-10-20,08:00:00,O\n'
            '5001,2026-10-20,17:00:00,I\n'.encode('utf-8'),
            content_type='text/csv')
        response = self.client.post(reverse('device:usb_import'), {
            'action': 'preview', 'bridge_id': self.bridge.pk, 'file': upload,
        })
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['preview']['valid'], 2)
        self.assertEqual(RawRecord.objects.count(), 0)

        response = self.client.post(reverse('device:usb_import'), {'action': 'commit'})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['result']['converted'], 2)
        self.assertEqual(set(Punch.objects.values_list('direction', flat=True)),
                         {Punch.Direction.IN, Punch.Direction.OUT})
        self.assertEqual(RawRecord.objects.count(), 2)
        self.bridge.refresh_from_db()
        self.assertIn('usb=unis.csv', self.bridge.last_result)

    def test_scope_is_enforced_on_preview_and_commit_conversion(self):
        from apps.core.models import (
            Permission, Role, RoleDataScope, User, UserRole,
        )
        other = Employee.objects.create(
            badge_no='5002', first_name='مهدی', last_name='واحد دیگر')
        role = Role.objects.create(name='مدیر محدود', code='usb-limited')
        permissions = [
            Permission.objects.get_or_create(module=module, action='manage')[0]
            for module in ('attendance', 'device')
        ]
        role.permissions.set(permissions)
        RoleDataScope.objects.create(
            role=role, module='attendance', scope=RoleDataScope.Scope.SELF)
        user = User.objects.create_user(
            'usb-limited', password='x', employee=self.employee)
        UserRole.objects.create(user=user, role=role)
        import datetime as dt
        from django.utils import timezone
        outside_raw = RawRecord.objects.create(
            device=self.device, pin=other.badge_no,
            punch_time=timezone.make_aware(dt.datetime(2026, 10, 20, 9, 0)),
            payload='unis:older-record')
        self.client.force_login(user)
        upload = SimpleUploadedFile(
            'unis.csv',
            b'pin,date,time\n'
            b'5001,2026-10-20,08:00:00\n'
            b'5002,2026-10-20,09:00:00\n', content_type='text/csv')
        response = self.client.post(reverse('device:usb_import'), {
            'action': 'preview', 'bridge_id': self.bridge.pk, 'file': upload,
        })
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.context['preview']['valid'], 1)
        self.client.post(reverse('device:usb_import'), {'action': 'commit'})
        outside_raw.refresh_from_db()
        self.assertEqual(outside_raw.state, RawRecord.State.NEW)
        self.assertEqual(list(Punch.objects.values_list('employee__badge_no', flat=True)),
                         ['5001'])
        self.assertFalse(RawRecord.objects.filter(pin='5002').exclude(pk=outside_raw.pk).exists())

    def test_preview_persists_counts_after_refresh_and_can_be_cancelled(self):
        upload = SimpleUploadedFile(
            'unis.csv',
            b'pin,date,time\n5001,2026-10-20,08:00:00\n'
            b'9999,2026-10-20,09:00:00\n', content_type='text/csv')
        response = self.client.post(reverse('device:usb_import'), {
            'action': 'preview', 'bridge_id': self.bridge.pk, 'file': upload,
        })
        self.assertEqual(response.context['preview']['total'], 2)
        self.assertEqual(response.context['preview']['outside_scope'], 1)
        refreshed = self.client.get(reverse('device:usb_import'))
        self.assertEqual(refreshed.context['preview']['total'], 2)
        self.assertEqual(refreshed.context['preview']['outside_scope'], 1)
        cancelled = self.client.post(reverse('device:usb_import'), {'action': 'cancel'})
        self.assertEqual(cancelled.status_code, 302)
        self.assertIsNone(self.client.get(reverse('device:usb_import')).context['preview'])
        self.assertEqual(RawRecord.objects.count(), 0)

    def test_rejects_proprietary_or_unapproved_upload(self):
        upload = SimpleUploadedFile('device.dat', b'not a UNIS export')
        response = self.client.post(reverse('device:usb_import'), {
            'action': 'preview', 'bridge_id': self.bridge.pk, 'file': upload,
        })
        self.assertEqual(response.status_code, 302)
        self.assertEqual(RawRecord.objects.count(), 0)

    def test_csv_parser_supports_jalali_and_persian_encoding(self):
        parsed = parse_unis_export(
            '۵۰۰۱,۱۴۰۵/۰۷/۲۸,۰۸:۳۰:۰۰,ورود\n',
            bridge_name='پل', file_name='خروجی.csv')
        self.assertEqual(parsed[0]['pin'], '5001')
        self.assertIn('dir=ورود', parsed[0]['payload'])
        self.assertIn('ورود', decode_unis_export('ورود'.encode('cp1256')))
        utf16_export = '5001,1405/07/28,08:30:00,ورود\n'.encode('utf-16')
        self.assertIn('ورود', decode_unis_export(utf16_export))

    def test_requires_both_attendance_and_device_management(self):
        from apps.core.models import User
        user = User.objects.create_user('usb-reader', password='x')
        self.client.force_login(user)
        response = self.client.get(reverse('device:usb_import'))
        self.assertEqual(response.status_code, 403)


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
        zk = Device.objects.create(name='ZK', serial='ZK-UNIS-INVALID')
        self.client.post(reverse('device:unis_bridge'), {
            'name': 'پل نامعتبر', 'device_id': zk.pk, 'mode': 'dir',
            'dir_path': '/tmp/x', 'is_active': 'on'})
        self.assertFalse(UnisBridge.objects.filter(name='پل نامعتبر').exists())

    def test_sync_button_runs(self):
        device = Device.objects.create(name='V', serial='V-3',
                                       brand=Device.Brand.VIRDI)
        bridge = UnisBridge.objects.create(
            name='p', device=device, mode=UnisBridge.Mode.DIR,
            dir_path=tempfile.mkdtemp())
        resp = self.client.post(reverse('device:unis_sync', args=[bridge.pk]))
        self.assertEqual(resp.status_code, 302)
        bridge.is_active = False
        bridge.save(update_fields=['is_active'])
        resp = self.client.post(reverse('device:unis_sync', args=[bridge.pk]))
        self.assertEqual(resp.status_code, 404)

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
