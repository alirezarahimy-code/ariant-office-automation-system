"""آداپتور دستگاه‌های ZKTeco / سازگار — نظرسنجی مستقیم با pyzk (پایتون خالص).

بدون هیچ سرویس ویندوزی؛ روی لینوکس/ویندوز/هرجا که Django اجرا شود کار می‌کند.
"""
from __future__ import annotations

from .unis_adapter import BridgeError


def fetch_records(device) -> list[dict]:
    """خواندن لاگ تردد دستگاه ZK → [{'pin', 'time', 'verify_mode', 'payload'}]."""
    if not device.ip:
        raise BridgeError(f'دستگاه {device.serial} آی‌پی ندارد.')
    try:
        from zk import ZK  # pyzk
    except ImportError as exc:
        raise BridgeError(
            'کتابخانه pyzk نصب نیست — برای اتصال مستقیم ZK: pip install pyzk') from exc

    conn = None
    try:
        zk = ZK(device.ip, port=int(device.port or 437), timeout=8,
                force_udp=(device.conn_type == 'udp'), password=0)
        conn = zk.connect()
        if conn is None:
            raise BridgeError(f'اتصال به {device.ip}:{device.port} برقرار نشد.')
        rows = conn.get_attendance()
        out = []
        for row in rows:
            ts = getattr(row, 'timestamp', None)
            if ts is None:
                continue
            out.append({
                'pin': str(getattr(row, 'user_id', '')).strip(),
                'time': ts.strftime('%Y-%m-%d %H:%M:%S'),
                'verify_mode': int(getattr(row, 'punch', 0) or 0),
                'payload': f'zk:{getattr(row, "status", "")}',
            })
        return out
    except BridgeError:
        raise
    except Exception as exc:  # خطای پروتکل/شبکه — پیام روشن، بدون توقف کل دروازه
        raise BridgeError(f'خطای خواندن دستگاه {device.serial}: {exc}') from exc
    finally:
        if conn is not None:
            try:
                conn.disconnect()
            except Exception:
                pass
