"""آداپتور پل یونیس (UNIS Manager — Virdi / Union Community).

دستگاه‌های Virdi به نرم‌افزار یونیس وصل‌اند؛ لاگ تردد در بانک یونیس
(MS SQL / MySQL / Oracle / Access) یا خروجی فایل آن می‌نشیند. این آداپتور
آن لاگ‌ها را به خط لوله واحد سامانه می‌آورد — بدون هیچ سرویس ویندوزی اضافه:

- حالت `db`: خواندن از بانک یونیس (اتصال `unis` از تنظیمات — ر.ک. راهنما)
- حالت `dir`: خواندن فایل‌های CSV خروجی یونیس/USB از یک پوشه مشترک
"""
from __future__ import annotations

import csv
import datetime as gregorian
import io
import os
import re

from django.utils import timezone

_IDENT = re.compile(r'^[A-Za-z_][A-Za-z0-9_]{0,62}(\.[A-Za-z_][A-Za-z0-9_]{0,62})?$')
MAX_UNIS_IMPORT_ROWS = 10_000
MAX_UNIS_FILE_SIZE = 10 * 1024 * 1024
MAX_UNIS_BATCH = 50_000
_DIGIT_TRANSLATION = str.maketrans(
    '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')


def _ascii_digits(value) -> str:
    return str(value or '').translate(_DIGIT_TRANSLATION).strip()


class BridgeError(Exception):
    """خطای پل/آداپتور دستگاه (پیام فارسی برای نمایش به کاربر)."""


def _ident(name: str, label: str) -> str:
    """نام جدول/ستون باید شناسه SQL معتبر باشد (ضد تزریق)."""
    text = (name or '').strip()
    if not _IDENT.match(text):
        raise BridgeError(f'{label} نامعتبر است: {text!r}')
    return text


def _norm_time(value) -> str:
    """نرمال‌سازی زمان رشته/datetime به 'YYYY-MM-DD HH:MM:SS' (به وقت محلی دستگاه)."""
    if isinstance(value, gregorian.datetime):
        return value.strftime('%Y-%m-%d %H:%M:%S')
    text = str(value or '').strip().replace('T', ' ')
    return text[:19]


def _payload_component(value, limit=100) -> str:
    """جلوگیری از تزریق جداکننده‌های metadata در مقدارهای خام payload."""
    return ''.join(char for char in str(value or '')
                   if char not in '|\r\n')[:limit]


def _batch_limit(bridge) -> int:
    try:
        limit = int(bridge.batch_limit or 5000)
    except (TypeError, ValueError) as exc:
        raise BridgeError('سقف دستهٔ پل UNIS معتبر نیست.') from exc
    if not 1 <= limit <= MAX_UNIS_BATCH:
        raise BridgeError(f'سقف دسته باید بین ۱ و {MAX_UNIS_BATCH} باشد.')
    return limit


def decode_unis_export(content: bytes) -> str:
    """CSV خروجی UNIS را با encodingهای رایج فارسی باز می‌کند."""
    if content.startswith((b'\xff\xfe', b'\xfe\xff')):
        try:
            return content.decode('utf-16')
        except UnicodeDecodeError as exc:
            raise BridgeError('کدگذاری فایل خروجی یونیس قابل خواندن نیست.') from exc
    try:
        return content.decode('utf-8-sig')
    except UnicodeDecodeError:
        try:
            return content.decode('cp1256')
        except UnicodeDecodeError as exc:
            raise BridgeError('کدگذاری فایل خروجی یونیس قابل خواندن نیست.') from exc


def parse_unis_export(text: str, *, bridge_name: str, file_name: str) -> list[dict]:
    """تبدیل CSV/TXT خروجی UNIS به رکوردهای خام بدون تماس با دستگاه.

    پشتیبانی از ستون‌های pin,date,time,direction و pin,datetime؛
    تاریخ می‌تواند میلادی یا جلالی باشد. فایل مبهم/خراب پیش از نوشتن رد می‌شود.
    """
    records = []
    data_rows = 0
    reader = csv.reader(io.StringIO(text, newline=''))
    for line_number, row in enumerate(reader, start=1):
        row = [cell.strip() for cell in row]
        if not row or not any(row):
            continue
        first = row[0].strip().casefold()
        if first in {'pin', 'user_id', 'user id', 'badge', 'badge_no', 'کد', 'کد پرسنلی'}:
            continue
        data_rows += 1
        if data_rows > MAX_UNIS_IMPORT_ROWS:
            raise BridgeError(f'حداکثر {MAX_UNIS_IMPORT_ROWS} ردیف قابل بررسی است.')
        if len(row) < 2 or not row[0]:
            raise BridgeError(f'ردیف {line_number}: کد پرسنلی یا زمان وجود ندارد.')
        pin = _ascii_digits(row[0])
        if len(pin) > 24:
            raise BridgeError(f'ردیف {line_number}: کد پرسنلی بیش از حد مجاز است.')
        if len(row) >= 3 and ':' in row[2]:
            date_part = row[1][:10]
            time_part = row[2]
            direction = row[3] if len(row) >= 4 else ''
        else:
            stamp = _ascii_digits(row[1]).replace('T', ' ')
            date_part = stamp[:10]
            time_part = stamp[11:19] if len(stamp) > 10 else '00:00:00'
            direction = row[2] if len(row) >= 3 else ''
        try:
            day = _j2g(_ascii_digits(date_part))
            time_part = _ascii_digits(time_part)
            if len(time_part) == 5:
                time_part += ':00'
            parsed = gregorian.datetime.strptime(
                f'{day} {time_part[:8]}', '%Y-%m-%d %H:%M:%S')
        except (ValueError, TypeError, OverflowError) as exc:
            raise BridgeError(f'ردیف {line_number}: تاریخ یا ساعت معتبر نیست.') from exc
        safe_name = os.path.basename(str(file_name).replace('\\', '/'))
        payload = (f'unis:{_payload_component(bridge_name)}|'
                   f'file={_payload_component(safe_name)}')
        if direction:
            payload += f'|dir={_payload_component(direction, 32)}'
        records.append({
            'pin': pin,
            'time': parsed.strftime('%Y-%m-%d %H:%M:%S'),
            'payload': payload,
            'line': line_number,
        })
    return records


def sync_bridge(bridge, *, allowed_pins: set[str] | None = None) -> dict:
    """همگام‌سازی پل یونیس؛ امکان محدودکردن تبدیل تردد به کدهای مجاز."""
    if bridge.mode == bridge.Mode.DIR:
        raw = _pull_dir(bridge)
    else:
        raw = _pull_db(bridge)
    from django.db import transaction
    from ..services import convert_raw_to_punches, ingest_records
    try:
        with transaction.atomic():
            ing = ingest_records(bridge.device, raw['records'])
            conv = convert_raw_to_punches(
                bridge.device, allowed_pins=allowed_pins)
            bridge.last_sync_at = timezone.now()
            bridge.last_watermark = raw.get('watermark', bridge.last_watermark)
            bridge.last_result = (
                f"created={ing['created']} skipped={ing['skipped']} "
                f"converted={conv.get('converted', 0)} errors={conv.get('errors', 0)}")
            bridge.save(update_fields=['last_sync_at', 'last_watermark', 'last_result'])
    except (TypeError, ValueError) as exc:
        raise BridgeError(f'دادهٔ خروجی UNIS معتبر نیست: {exc}') from exc
    # Archive source files only after DB changes commit. If archiving fails,
    # the next run is idempotent and can retry without losing the source file.
    for path in raw.get('source_paths', []):
        target = path + '.imported'
        suffix = 1
        while os.path.exists(target):
            target = f'{path}.{suffix}.imported'
            suffix += 1
        try:
            os.rename(path, target)
        except OSError as exc:
            raise BridgeError(f'رکوردها ثبت شدند، اما بایگانی فایل {os.path.basename(path)} ناموفق بود.') from exc
    return {'bridge': bridge.name, 'records': len(raw['records']), **ing,
            'converted': conv.get('converted', 0), 'errors': conv.get('errors', 0)}


# ---------- حالت بانک یونیس ----------

def _pull_db(bridge) -> dict:
    from django.conf import settings
    access_cfg = getattr(settings, 'UNIS_ACCESS', None)
    if access_cfg:
        return _pull_access(bridge, access_cfg)

    from django.db import connections

    if 'unis' not in connections.databases:
        raise BridgeError(
            'اتصال بانک یونیس تنظیم نشده — متغیرهای محیطی UNIS_DB_* را در راهنما '
            '(05-unis-bridge) ببینید.')
    table = _ident(bridge.table_name, 'نام جدول')
    col_pin = _ident(bridge.col_pin, 'ستون کد پرسنلی')
    col_time = _ident(bridge.col_time, 'ستون زمان')
    col_dir = _ident(bridge.col_direction, 'ستون جهت') if bridge.col_direction else ''

    conn = connections['unis']
    vendor = getattr(conn, 'vendor', '')
    limit = _batch_limit(bridge)
    if vendor == 'oracle':
        tail = f'FETCH FIRST {limit} ROWS ONLY'
    elif vendor in ('mssql', 'microsoft'):
        tail = ''  # TOP در SELECT
    else:
        tail = f'LIMIT {limit}'

    cols = f'{col_pin} AS pin, {col_time} AS tseen' + (
        f', {col_dir} AS dseen' if col_dir else '')
    if vendor in ('mssql', 'microsoft'):
        cols = f'TOP ({limit}) ' + cols
    watermark = bridge.last_watermark or '1970-01-01 00:00:00'
    sql = (f'SELECT {cols} FROM {table} WHERE {col_time} >= %s '
           f'ORDER BY {col_time} ASC {tail}')

    try:
        with conn.cursor() as cur:
            cur.execute(sql, [watermark])
            rows = cur.fetchall()
    except Exception as exc:
        raise BridgeError(f'خطای خواندن بانک یونیس ({table}): {exc}') from exc

    records, max_seen = [], watermark
    for row in rows:
        pin, tseen = str(row[0]).strip(), _norm_time(row[1])
        if not pin:
            continue
        item = {'pin': pin, 'time': tseen,
                'payload': f'unis:{_payload_component(bridge.name)}'}
        if col_dir and len(row) > 2 and row[2] is not None:
            item['payload'] += f'|dir={_payload_component(row[2], 32)}'
        records.append(item)
        max_seen = max(max_seen, tseen)
    return {'records': records, 'watermark': max_seen}


# ---------- حالت پوشه خروجی ----------

def _j2g(date_text: str) -> str:
    """تاریخ جلالی/میلادی → میلادی 'YYYY-MM-DD'."""
    text = date_text.strip()
    try:
        y, m, d = [int(x) for x in re.split(r'[-/]', text)[:3]]
    except ValueError as exc:
        raise BridgeError(f'تاریخ نامعتبر: {date_text!r}') from exc
    if y > 1700:  # میلادی
        return f'{y:04d}-{m:02d}-{d:02d}'
    import jdatetime
    return jdatetime.date(y, m, d).togregorian().isoformat()


def _pull_dir(bridge) -> dict:
    folder = (bridge.dir_path or '').strip()
    if not folder or not os.path.isdir(folder):
        raise BridgeError(f'پوشه خروجی یونیس معتبر نیست: {folder!r}')
    records, source_paths = [], []
    batch_limit = _batch_limit(bridge)
    for fname in sorted(os.listdir(folder)):
        if not fname.lower().endswith(('.csv', '.txt')) or fname.lower().endswith('.imported'):
            continue
        path = os.path.join(folder, fname)
        if os.path.islink(path):
            raise BridgeError(f'فایل پیوندی در پوشهٔ خروجی مجاز نیست: {fname}')
        if not os.path.isfile(path):
            continue
        try:
            size = os.path.getsize(path)
            if size > MAX_UNIS_FILE_SIZE:
                raise BridgeError(f'فایل {fname} از سقف ۱۰ مگابایت بزرگ‌تر است.')
            with open(path, 'rb') as fh:
                text = decode_unis_export(fh.read())
            parsed = parse_unis_export(
                text, bridge_name=bridge.name, file_name=fname)
            if len(records) + len(parsed) > batch_limit:
                raise BridgeError(f'تعداد رکوردها از سقف هر نوبت ({batch_limit}) بیشتر است.')
            records.extend([
                {key: value for key, value in item.items()
                 if key in {'pin', 'time', 'payload'}}
                for item in parsed
            ])
        except BridgeError:
            raise
        except (OSError, UnicodeError, csv.Error) as exc:
            raise BridgeError(f'خطای خواندن فایل {fname}: {exc}') from exc
        source_paths.append(path)
    return {'records': records, 'source_paths': source_paths,
            'watermark': bridge.last_watermark}


# ---------- بانک Access نرم‌افزار یونیس (unis.mdb) ----------

def _pull_access(bridge, cfg) -> dict:
    """خواندن مستقیم از unis.mdb (Access) با pyodbc — فقط‌خواندنی (SELECT)."""
    table = _ident(bridge.table_name, 'نام جدول')
    col_pin = _ident(bridge.col_pin, 'ستون کد پرسنلی')
    col_time = _ident(bridge.col_time, 'ستون زمان')
    col_dir = _ident(bridge.col_direction, 'ستون جهت') if bridge.col_direction else ''
    try:
        import pyodbc
    except ImportError as exc:
        raise BridgeError(
            'درایور pyodbc برای خواندن unis.mdb در دسترس نیست.') from exc

    name = (cfg.get('name') or '').strip()
    if not name:
        raise BridgeError(
            'مسیر بانک یونیس (unis.mdb) تنظیم نشده — UNIS_DB_NAME را ببینید.')
    password = cfg.get('password') or ''
    # رمز فقط در رشته اتصال ODBC؛ هرگز ثبت/چاپ نمی‌شود.
    conn_str = (f'DRIVER={{Microsoft Access Driver (*.mdb, *.accdb)}};DBQ={name};'
                f'PWD={password}')

    limit = _batch_limit(bridge)
    cols = (f'{col_pin} AS pin, {col_time} AS tseen'
            + (f', {col_dir} AS dseen' if col_dir else ''))
    watermark_text = bridge.last_watermark or '1970-01-01 00:00:00'
    try:
        watermark = gregorian.datetime.strptime(
            watermark_text[:19], '%Y-%m-%d %H:%M:%S')
    except ValueError:
        watermark = gregorian.datetime(1970, 1, 1)
    sql = (f'SELECT TOP {limit} {cols} FROM {table} '
           f'WHERE {col_time} >= ? ORDER BY {col_time} ASC')

    try:
        cn = pyodbc.connect(conn_str, autocommit=True)
        try:
            cur = cn.cursor()
            cur.execute(sql, [watermark])
            rows = cur.fetchall()
        finally:
            cn.close()
    except Exception as exc:
        raise BridgeError(f'خطای خواندن بانک یونیس ({table}): {exc}') from exc

    records, max_seen = [], watermark_text
    for row in rows:
        pin, tseen = str(row[0]).strip(), _norm_time(row[1])
        if not pin:
            continue
        item = {'pin': pin, 'time': tseen,
                'payload': f'unis:{_payload_component(bridge.name)}'}
        if col_dir and len(row) > 2 and row[2] is not None:
            item['payload'] += f'|dir={_payload_component(row[2], 32)}'
        records.append(item)
        max_seen = max(max_seen, tseen)
    return {'records': records, 'watermark': max_seen}
