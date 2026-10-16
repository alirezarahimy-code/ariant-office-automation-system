"""بارکد Code39 به SVG — برای برچسب کالا و تگ انبارگردانی (بدون وابستگی سنگین)."""
from __future__ import annotations

import re

_svg_cache: dict[str, str] = {}


def code39_svg(value: str, *, bar_width: float = 1.1, bar_height: float = 26,
               human_readable: bool = True) -> str:
    """SVG درون‌خطی بارکد Code39 برای مقدار داده‌شده (کش‌شده)."""
    key = f'{value}|{bar_width}|{bar_height}|{human_readable}'
    if key in _svg_cache:
        return _svg_cache[key]
    try:
        from reportlab.graphics.barcode import createBarcodeDrawing
        drawing = createBarcodeDrawing(
            'Standard39', value=str(value), humanReadable=human_readable,
            barWidth=bar_width, barHeight=bar_height)
        raw = drawing.asString('svg')
        match = re.search(r'<svg[\s\S]*?</svg>', raw)
        svg = match.group(0) if match else ''
        svg = re.sub(r'<\?xml[^>]*\?>', '', svg)
        svg = re.sub(r'<!DOCTYPE[^>]*>', '', svg)
        svg = svg.strip()
    except Exception:  # noqa: BLE001 — بارکد هرگز صفحه را نمی‌شکند
        svg = (f'<svg xmlns="http://www.w3.org/2000/svg" height="{bar_height + 14}">'
               f'<text x="4" y="{bar_height + 8}" font-size="10">{value}</text></svg>')
    _svg_cache[key] = svg
    return svg
