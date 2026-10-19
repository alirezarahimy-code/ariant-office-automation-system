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
import os
import re

from django.utils import timezone

_IDENT = re.compile(r'^[A-Za-z_][A-Za-z0-9_]{0,62}(\.[A-Za-z_][A-Za-z0-9_]{0,62})?$')


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


def sync_bridge(bridge) -> dict:
    """همگام‌سازی یک پل یونیس → ثبت رکورد خام + تبدیل به تردد."""
    if bridge.mode == bridge.Mode.DIR:
        raw = _pull_dir(bridge)
    else:
        raw = _pull_db(bridge)
    from ..services import convert_raw_to_punches, ingest_records
    ing = ingest_records(bridge.device, raw['records'])
    conv = convert_raw_to_punches(bridge.device)
    bridge.last_sync_at = timezone.now()
    bridge.last_watermark = raw.get('watermark', bridge.last_watermark)
    bridge.last_result = (
        f"created={ing['created']} skipped={ing['skipped']} "
        f"converted={conv.get('converted', 0)} errors={conv.get('errors', 0)}")
    bridge.save(update_fields=['last_sync_at', 'last_watermark', 'last_result'])
    return {'bridge': bridge.name, 'records': len(raw['records']), **ing,
            'converted': conv.get('converted', 0), 'errors': conv.get('errors', 0)}


# ---------- حالت بانک یونیس ----------

def _pull_db(bridge) -> dict:
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
    limit = int(bridge.batch_limit or 5000)
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
        item = {'pin': pin, 'time': tseen, 'payload': f'unis:{bridge.name}'}
        if col_dir and len(row) > 2 and row[2] is not None:
            item['payload'] += f'|dir={row[2]}'
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
    records = []
    for fname in sorted(os.listdir(folder)):
        if not fname.lower().endswith(('.csv', '.txt')) or fname.endswith('.imported'):
            continue
        path = os.path.join(folder, fname)
        try:
            with open(path, newline='', encoding='utf-8-sig') as fh:
                for row in csv.reader(fh):
                    row = [c.strip() for c in row]
                    if not row or not row[0] or row[0].lower() in ('pin', 'user_id', 'کد'):
                        continue  # سربرگ/خالی
                    if len(row) >= 3 and ':' in row[2]:
                        day, tm = _j2g(row[1]), row[2]
                    elif len(row) >= 2:
                        day, tm = _j2g(row[1][:10]), row[1][11:19] if len(row[1]) > 10 else '00:00:00'
                    else:
                        continue
                    item = {'pin': row[0],
                            'time': f'{day} {tm[:8] if len(tm) >= 8 else tm + ":00"}',
                            'payload': f'unis:{bridge.name}|file={fname}'}
                    if len(row) >= 4 and row[3]:
                        item['payload'] += f'|dir={row[3]}'
                    records.append(item)
            os.rename(path, path + '.imported')
        except BridgeError:
            raise
        except Exception as exc:
            raise BridgeError(f'خطای خواندن فایل {fname}: {exc}') from exc
    return {'records': records, 'watermark': bridge.last_watermark}
