"""دروازه چندبرند دستگاه — بدون هیچ سرویس/سرویس ویندوزی.

- ZK/ZKTeco ← آداپتور مستقیم (pyzk) از خود دستگاه
- Virdi ← پل نرم‌افزار یونیس (UNIS): خواندن از بانک یونیس یا پوشه خروجی
هر دو مسیر به یک خط لوله واحد (ingest → تردد → رکورد روزانه) می‌ریزند.
"""
from __future__ import annotations

from .unis_adapter import BridgeError, sync_bridge
from .zk_adapter import fetch_records as zk_fetch_records

__all__ = ['BridgeError', 'sync_bridge', 'zk_fetch_records', 'run_gateway']


def run_gateway() -> dict:
    """یک نوبت کامل: نظرسنجی همه دستگاه‌های ZK فعال + همه پل‌های یونیس فعال."""
    from apps.device.models import Device, UnisBridge
    from apps.device.services import convert_raw_to_punches, ingest_records

    result = {'zk': [], 'unis': []}
    for device in Device.objects.filter(is_active=True, brand=Device.Brand.ZK):
        try:
            records = zk_fetch_records(device)
            ing = ingest_records(device, records)
            result['zk'].append({'device': device.serial, **ing})
        except BridgeError as exc:
            result['zk'].append({'device': device.serial, 'error': str(exc)})
    for bridge in UnisBridge.objects.filter(is_active=True).select_related('device'):
        try:
            result['unis'].append(sync_bridge(bridge))
        except BridgeError as exc:
            result['unis'].append({'bridge': bridge.name, 'error': str(exc)})
    converted = convert_raw_to_punches()
    result['converted'] = converted
    return result
