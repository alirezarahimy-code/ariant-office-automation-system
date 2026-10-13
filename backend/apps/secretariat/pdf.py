"""تولید PDF سربرگ‌دار A4 برای نامه — reportlab + شکل‌دهی فارسی (reshaper + bidi).

سربرگ مطابق طراحی مصوب «آرام و شفاف»: خط سرمه‌ای یکدست بالا، نام سازمان وسط، «بسمه تعالی».
فونت: Vazirmatn TTF از tools/fonts (یا static/fonts).
"""
from __future__ import annotations

from io import BytesIO

import arabic_reshaper
import jdatetime
from bidi.algorithm import get_display
from django.conf import settings
from reportlab.lib.colors import HexColor
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import cm
from reportlab.lib.utils import simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas as pdf_canvas

from apps.core.persian import to_persian_digits

NAVY = HexColor('#16365f')
T1 = HexColor('#1a2333')
T2 = HexColor('#5c6b80')
LINE = HexColor('#e4e7ee')

_fonts_ready = False


def _register_fonts() -> None:
    global _fonts_ready
    if _fonts_ready:
        return
    candidates = [
        settings.BASE_DIR.parent / 'tools' / 'fonts',
        settings.BASE_DIR / 'static' / 'fonts',
    ]
    for folder in candidates:
        regular = folder / 'Vazirmatn-Regular.ttf'
        bold = folder / 'Vazirmatn-Bold.ttf'
        if regular.exists() and bold.exists():
            pdfmetrics.registerFont(TTFont('Vazirmatn', str(regular)))
            pdfmetrics.registerFont(TTFont('Vazirmatn-Bold', str(bold)))
            _fonts_ready = True
            return
    raise RuntimeError('فونت Vazirmatn (TTF) برای تولید PDF یافت نشد.')


def fa(text) -> str:
    """شکل‌دهی + ترتیب نمایشی برای متن فارسی."""
    return get_display(arabic_reshaper.reshape(str(text or '')))


def _wrap_lines(text: str, font: str, size: float, max_width: float) -> list[str]:
    lines: list[str] = []
    for paragraph in str(text or '').split('\n'):
        reshaped = arabic_reshaper.reshape(paragraph)
        if not reshaped.strip():
            lines.append('')
            continue
        lines.extend(simpleSplit(reshaped, font, size, max_width))
    return lines


def build_letter_pdf(letter) -> bytes:
    """ساخت PDF کامل نامه با سربرگ سازمانی."""
    _register_fonts()
    buffer = BytesIO()
    c = pdf_canvas.Canvas(buffer, pagesize=A4)
    width, height = A4
    right = width - 2.2 * cm
    left = 2.2 * cm

    # خط سرمه‌ای بالا
    y = height - 1.2 * cm
    c.setStrokeColor(NAVY)
    c.setLineWidth(3)
    c.line(left, y, right, y)

    # سربرگ
    y -= 1.1 * cm
    c.setFillColor(T2)
    c.setFont('Vazirmatn', 10)
    c.drawCentredString(width / 2, y, fa('بسمه تعالی'))
    y -= 0.8 * cm
    c.setFillColor(NAVY)
    c.setFont('Vazirmatn-Bold', 16)
    c.drawCentredString(width / 2, y, fa(settings.PLATFORM_ORG_NAME))
    y -= 0.55 * cm
    c.setFillColor(T2)
    c.setFont('Vazirmatn', 8.5)
    c.drawCentredString(width / 2, y, fa(settings.PLATFORM_ORG_MOTTO))
    y -= 0.55 * cm
    c.setStrokeColor(LINE)
    c.setLineWidth(0.7)
    c.line(left, y, right, y)

    # ردیف شماره/تاریخ/پیوست
    y -= 0.85 * cm
    c.setFillColor(T1)
    c.setFont('Vazirmatn', 10)
    c.drawRightString(right, y, fa(f'شماره: {letter.number_display}'))
    jdate_value = letter.letter_date or jdatetime.datetime.fromgregorian(
        datetime=letter.created_at).date()
    c.drawString(left, y, fa(f'تاریخ: {to_persian_digits(jdate_value.strftime("%Y/%m/%d"))}'))
    c.drawCentredString(
        width / 2, y, fa(f'پیوست: {to_persian_digits(letter.attachments.count())}'))

    # موضوع
    y -= 1.15 * cm
    c.setFont('Vazirmatn-Bold', 12)
    c.setFillColor(T1)
    c.drawRightString(right, y, fa(f'موضوع: {letter.subject}'))

    # متن
    y -= 1.0 * cm
    c.setFont('Vazirmatn', 11)
    body = letter.body or letter.summary or ''
    for line in _wrap_lines(body, 'Vazirmatn', 11, right - left):
        if y < 3.4 * cm:
            c.showPage()
            y = height - 2.2 * cm
            c.setFont('Vazirmatn', 11)
            c.setFillColor(T1)
        if line:
            c.drawRightString(right, y, get_display(line))
        y -= 0.78 * cm

    # بلوک امضا (پایین، کنار چپ)
    approvals = list(letter.approvals.filter(decision='approved').select_related('approver'))
    if approvals:
        sig_y = min(y - 0.6 * cm, height * 0.30)
        c.setFont('Vazirmatn', 10)
        c.setFillColor(T1)
        for approval in approvals[:2]:
            name = ''
            if approval.approver:
                name = approval.approver.get_full_name() or approval.approver.username
            label = approval.approver_label or 'امضا'
            c.drawString(left + 0.8 * cm, sig_y, fa(f'{label}: {name}'))
            if approval.signature_image:
                try:
                    c.drawImage(
                        approval.signature_image.path, left + 0.8 * cm, sig_y + 0.25 * cm,
                        width=3.2 * cm, height=1.2 * cm, mask='auto')
                except (OSError, ValueError):
                    pass
            sig_y -= 1.4 * cm

    # پانوشت
    c.setStrokeColor(LINE)
    c.setLineWidth(0.7)
    c.line(left, 1.7 * cm, right, 1.7 * cm)
    c.setFillColor(T2)
    c.setFont('Vazirmatn', 8)
    c.drawCentredString(
        width / 2, 1.2 * cm, fa(f'{settings.PLATFORM_ORG_NAME} — سامانه اتوماسیون اداری'))

    c.showPage()
    c.save()
    return buffer.getvalue()
