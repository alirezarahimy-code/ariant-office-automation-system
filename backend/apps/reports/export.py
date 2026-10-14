"""خروجی‌های گزارش‌ساز: CSV (UTF-8 BOM)، XLSX و PDF فارسی.

PDF با فونت TTF فارسی (REPORTS_PDF_FONT) ساخته می‌شود؛ اگر فونت در
دسترس نباشد، پاسخ راهنما برمی‌گردد و نسخه چاپی مرورگر پیشنهاد می‌شود.
"""
from __future__ import annotations

import csv
import io
import logging

from django.http import HttpResponse

logger = logging.getLogger(__name__)


def csv_response(title: str, headers, rows) -> HttpResponse:
    """پاسخ CSV با BOM تا اکسل فارسی را درست بخواند."""
    buffer = io.StringIO()
    writer = csv.writer(buffer)
    writer.writerow(headers)
    writer.writerows(rows)
    response = HttpResponse('﻿' + buffer.getvalue(),
                            content_type='text/csv; charset=utf-8')
    response['Content-Disposition'] = \
        f'attachment; filename="{title}.csv"'
    return response


def xlsx_response(title: str, headers, rows) -> HttpResponse:
    """پاسخ Excel (openpyxl) — ستون‌ها با عرض خودکار."""
    from openpyxl import Workbook

    workbook = Workbook()
    sheet = workbook.active
    sheet.title = title[:31]
    sheet.append(list(headers))
    for row in rows:
        sheet.append(list(row))
    for idx, header in enumerate(headers, start=1):
        width = max(12, min(40, len(str(header)) * 2 + 8))
        sheet.column_dimensions[chr(64 + idx) if idx <= 26 else 'A'].width = width
    buffer = io.BytesIO()
    workbook.save(buffer)
    response = HttpResponse(
        buffer.getvalue(),
        content_type='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    response['Content-Disposition'] = \
        f'attachment; filename="{title}.xlsx"'
    return response


def pdf_response(title: str, headers, rows,
                 font_path: str = '') -> HttpResponse:
    """PDF فارسی با reportlab — نیازمند فونت TTF و arabic-reshaper."""
    from django.conf import settings
    from django.http import HttpResponse as HResponse

    font_path = font_path or getattr(settings, 'REPORTS_PDF_FONT', '')
    if not font_path:
        return HResponse(
            'فونت فارسی PDF تنظیم نشده است (REPORTS_PDF_FONT) — '
            'از نسخه چاپی مرورگر استفاده کنید.',
            status=501, content_type='text/plain; charset=utf-8')
    try:
        import arabic_reshaper
        from bidi.algorithm import get_display
        from reportlab.lib.pagesizes import A4, landscape
        from reportlab.lib.units import mm
        from reportlab.pdfbase import pdfmetrics
        from reportlab.pdfbase.ttfonts import TTFont
        from reportlab.pdfgen import canvas
    except ImportError:
        return HResponse(
            'کتابخانه‌های PDF نصب نیستند (reportlab/arabic-reshaper/python-bidi).',
            status=501, content_type='text/plain; charset=utf-8')

    def fa(text: str) -> str:
        return get_display(arabic_reshaper.reshape(str(text)))

    pdfmetrics.registerFont(TTFont('Vazirmatn', font_path))
    buffer = io.BytesIO()
    page = canvas.Canvas(buffer, pagesize=landscape(A4))
    width, height = landscape(A4)
    page.setFont('Vazirmatn', 12)
    page.drawRightString(width - 15 * mm, height - 15 * mm, fa(title))
    page.setFont('Vazirmatn', 9)
    y = height - 25 * mm
    cols = max(1, len(headers))
    col_width = (width - 30 * mm) / cols
    x_positions = [width - 15 * mm - col_width * i for i in range(cols)]
    for i, header in enumerate(headers):
        page.drawRightString(x_positions[i], y, fa(header))
    y -= 8 * mm
    for row in rows:
        if y < 15 * mm:
            page.showPage()
            page.setFont('Vazirmatn', 9)
            y = height - 15 * mm
        for i, cell in enumerate(row):
            page.drawRightString(x_positions[i], y, fa(str(cell))[:60])
        y -= 6 * mm
    page.save()
    response = HttpResponse(buffer.getvalue(), content_type='application/pdf')
    response['Content-Disposition'] = f'attachment; filename="{title}.pdf"'
    return response
