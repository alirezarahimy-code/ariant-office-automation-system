#!/usr/bin/env python3
"""ساخت PDF فارسی سند جامع از فایل مارک‌داون.

اجرا:
    PYTHONPATH=/tmp/pdfenv python3 tools/build_spec_pdf.py

نیازمندی‌ها: reportlab, arabic-reshaper, python-bidi (در requirements نیستند؛
فقط برای تولید سند لازم‌اند). فونت وزیرمتن در tools/fonts موجود است.
"""
from __future__ import annotations

import os
import re
import sys

import arabic_reshaper
from bidi.algorithm import get_display
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_RIGHT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    HRFlowable,
    KeepTogether,
    NextPageTemplate,
    PageBreak,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.platypus.tableofcontents import TableOfContents

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MD_PATH = os.path.join(ROOT, 'docs', '06-comprehensive-spec-v2.md')
PDF_PATH = os.path.join(ROOT, 'docs', '06-comprehensive-spec-v2.pdf')
FONT_DIR = os.path.join(ROOT, 'tools', 'fonts')

NAVY = colors.HexColor('#1a3c6e')
ACCENT = colors.HexColor('#0f7b6c')
LIGHT = colors.HexColor('#eef2f8')
GREY = colors.HexColor('#5b6472')
RED = colors.HexColor('#b3261e')
ORANGE = colors.HexColor('#b45309')
GREEN = colors.HexColor('#15803d')
BLUE = colors.HexColor('#1d4ed8')
OLIVE = colors.HexColor('#7c6a1a')

# ---------------------------------------------------------------------------
# فونت‌ها و متن فارسی
# ---------------------------------------------------------------------------
pdfmetrics.registerFont(TTFont('Vazirmatn', os.path.join(FONT_DIR, 'Vazirmatn-Regular.ttf')))
pdfmetrics.registerFont(TTFont('Vazirmatn-Bold', os.path.join(FONT_DIR, 'Vazirmatn-Bold.ttf')))
pdfmetrics.registerFont(TTFont('Vazirmatn-Black', os.path.join(FONT_DIR, 'Vazirmatn-Black.ttf')))
pdfmetrics.registerFontFamily(
    'Vazirmatn', normal='Vazirmatn', bold='Vazirmatn-Bold')


def fa(text: str) -> str:
    """شکل‌دهی و ترتیب بصری برای رسم متن فارسی/عربی."""
    text = text.replace('«', '"').replace('»', '"')
    return get_display(arabic_reshaper.reshape(text))


def fa_runs(text: str) -> str:
    """تبدیل متن با پررنگ‌سازی **متن** — قطعه‌ها جدا شکل می‌گیرند."""
    parts = re.split(r'(\*\*[^*]+\*\*)', text)
    chunks: list[tuple[str, bool]] = []
    for part in parts:
        if not part:
            continue
        if part.startswith('**') and part.endswith('**'):
            chunks.append((fa(part[2:-2]), True))
        else:
            chunks.append((fa(part), False))
    # در چیدمان راست‌به‌چپ، نخستین قطعه منطقی باید راست‌ترین باشد
    chunks.reverse()
    out = []
    for shaped, bold in chunks:
        out.append(f'<b>{shaped}</b>' if bold else shaped)
    return ' '.join(out)


# ---------------------------------------------------------------------------
# شیوه‌نامه‌ها
# ---------------------------------------------------------------------------
def make_styles() -> dict[str, ParagraphStyle]:
    base = dict(fontName='Vazirmatn', alignment=TA_RIGHT, wordWrap='RTL',
                textColor=colors.HexColor('#1f2937'))
    return {
        'body': ParagraphStyle('body', **base, fontSize=10, leading=17,
                               spaceBefore=2, spaceAfter=4),
        'H1': ParagraphStyle('H1', fontName='Vazirmatn-Black', alignment=TA_RIGHT,
                             wordWrap='RTL', fontSize=17, leading=26,
                             textColor=NAVY, spaceBefore=6, spaceAfter=10),
        'H2': ParagraphStyle('H2', fontName='Vazirmatn-Bold', alignment=TA_RIGHT,
                             wordWrap='RTL', fontSize=12.5, leading=20,
                             textColor=colors.HexColor('#24508f'),
                             spaceBefore=10, spaceAfter=6),
        'H3': ParagraphStyle('H3', fontName='Vazirmatn-Bold', alignment=TA_RIGHT,
                             wordWrap='RTL', fontSize=11, leading=18,
                             textColor=colors.HexColor('#33415c'),
                             spaceBefore=8, spaceAfter=4),
        'bullet': ParagraphStyle('bullet', **base, fontSize=10, leading=17,
                                 rightIndent=14, bulletIndent=4, spaceAfter=2),
        'cell': ParagraphStyle('cell', **base, fontSize=8.5, leading=13),
        'cellhead': ParagraphStyle('cellhead', fontName='Vazirmatn-Bold',
                                   alignment=TA_RIGHT, wordWrap='RTL',
                                   fontSize=8.5, leading=13, textColor=colors.white),
        'cellwide': ParagraphStyle('cellwide', **base, fontSize=7.5, leading=11),
        'cellwidehead': ParagraphStyle('cellwidehead', fontName='Vazirmatn-Bold',
                                       alignment=TA_RIGHT, wordWrap='RTL',
                                       fontSize=7.5, leading=11, textColor=colors.white),
        'quote': ParagraphStyle('quote', **{**base, 'textColor': colors.HexColor('#22334d')},
                                fontSize=9.5, leading=16),
        'cover_title': ParagraphStyle('cover_title', fontName='Vazirmatn-Black',
                                      alignment=TA_CENTER, wordWrap='RTL',
                                      fontSize=26, leading=40, textColor=NAVY),
        'cover_sub': ParagraphStyle('cover_sub', fontName='Vazirmatn-Bold',
                                    alignment=TA_CENTER, wordWrap='RTL',
                                    fontSize=14, leading=24,
                                    textColor=colors.HexColor('#33415c')),
        'toc_h': ParagraphStyle('toc_h', fontName='Vazirmatn-Black',
                                alignment=TA_RIGHT, wordWrap='RTL',
                                fontSize=18, leading=28, textColor=NAVY),
    }


STYLES = make_styles()


# ---------------------------------------------------------------------------
# سند PDF
# ---------------------------------------------------------------------------
class SpecDoc(BaseDocTemplate):
    def __init__(self, filename: str, **kwargs):
        super().__init__(filename, pagesize=A4,
                         leftMargin=2 * cm, rightMargin=2 * cm,
                         topMargin=2.2 * cm, bottomMargin=2 * cm, **kwargs)
        frame_cover = Frame(2 * cm, 2 * cm, A4[0] - 4 * cm, A4[1] - 4 * cm,
                            id='cover')
        frame_body = Frame(2 * cm, 2 * cm, A4[0] - 4 * cm, A4[1] - 4.4 * cm,
                           id='body')
        self.addPageTemplates([
            PageTemplate(id='cover', frames=[frame_cover], onPage=self._cover_page),
            PageTemplate(id='body', frames=[frame_body], onPage=self._body_page),
        ])

    def _cover_page(self, canvas, doc):
        pass

    def _body_page(self, canvas, doc):
        canvas.saveState()
        canvas.setFont('Vazirmatn', 8)
        canvas.setFillColor(GREY)
        header = fa('سند جامع سامانه اتوماسیون اداری — نسخه ۲٫۰')
        canvas.drawRightString(A4[0] - 2 * cm, A4[1] - 1.4 * cm, header)
        canvas.setStrokeColor(colors.HexColor('#c9d2e0'))
        canvas.setLineWidth(0.6)
        canvas.line(2 * cm, A4[1] - 1.6 * cm, A4[0] - 2 * cm, A4[1] - 1.6 * cm)
        page_label = fa(f'صفحه {doc.page}')
        canvas.drawCentredString(A4[0] / 2, 1.1 * cm, page_label)
        canvas.restoreState()

    def afterFlowable(self, flowable):
        text = getattr(flowable, 'toc_text', None)
        if text is None:
            return
        level = flowable.toc_level
        self.notify('TOCEntry', (level, text, self.page))
        key = f'sec-{self.page}-{abs(hash(text))}'
        self.canv.bookmarkPage(key)
        self.canv.addOutlineEntry(text[:120], key, level=level, closed=(level > 0))


class TocParagraph(Paragraph):
    def __init__(self, text, style, toc_text=None, toc_level=0):
        super().__init__(text, style)
        self.toc_text = toc_text
        self.toc_level = toc_level


# ---------------------------------------------------------------------------
# مبدل مارک‌داون
# ---------------------------------------------------------------------------
def decision_color(text: str):
    if 'حذف' in text:
        return RED
    if 'وصل کن' in text:
        return BLUE
    if 'به‌تعویق' in text:
        return ORANGE
    if 'بساز' in text:
        return GREEN
    return None


def importance_color(text: str):
    t = text.strip()
    if len(t) > 30:
        return None
    for prefix, color in (('الف', RED), ('ب', ORANGE), ('ج', OLIVE),
                          ('د', colors.HexColor('#4b7b4b')), ('هـ', GREY)):
        if t.startswith(prefix) or t == prefix:
            return color
    return None


def cell_paragraph(text: str, header: bool, wide: bool):
    style = STYLES['cellwidehead' if wide else 'cellhead'] if header \
        else STYLES['cellwide' if wide else 'cell']
    color = None if header else (decision_color(text) or importance_color(text))
    shaped = fa_runs(text if text else '—')
    if color is not None:
        return Paragraph(f'<font color="{color.hexval()}">{shaped}</font>', style)
    return Paragraph(shaped, style)


def build_table(rows: list[list[str]]) -> Table:
    if not rows:
        return Spacer(1, 1)
    ncols = max(len(r) for r in rows)
    rows = [r + [''] * (ncols - len(r)) for r in rows]
    wide = ncols >= 6
    data = []
    for ridx, row in enumerate(rows):
        data.append([cell_paragraph(c, header=(ridx == 0), wide=wide) for c in row])
    weights = []
    for col in range(ncols):
        longest = max(len(r[col]) for r in rows)
        weights.append(min(max(longest, 4), 42))
    total = sum(weights) or 1
    avail = A4[0] - 4 * cm
    col_widths = [avail * w / total for w in weights]
    table = Table(data, colWidths=col_widths, repeatRows=1)
    style = [
        ('BACKGROUND', (0, 0), (-1, 0), NAVY),
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('TOPPADDING', (0, 0), (-1, -1), 3),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 3),
        ('LEFTPADDING', (0, 0), (-1, -1), 4),
        ('RIGHTPADDING', (0, 0), (-1, -1), 4),
        ('GRID', (0, 0), (-1, -1), 0.4, colors.HexColor('#c9d2e0')),
        ('BACKGROUND', (0, 1), (-1, -1), colors.white),
    ]
    for r in range(1, len(rows)):
        if r % 2 == 0:
            style.append(('BACKGROUND', (0, r), (-1, r), colors.HexColor('#f4f7fb')))
    table.setStyle(TableStyle(style))
    return table


def build_quote(lines: list[str]) -> Table:
    inner = [Paragraph(fa_runs(l), STYLES['quote']) for l in lines if l.strip()]
    t = Table([[inner]], colWidths=[A4[0] - 4 * cm])
    t.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), LIGHT),
        ('LINEBEFORE', (0, 0), (0, -1), 3, ACCENT),
        ('TOPPADDING', (0, 0), (-1, -1), 8),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 8),
        ('LEFTPADDING', (0, 0), (-1, -1), 10),
        ('RIGHTPADDING', (0, 0), (-1, -1), 10),
    ]))
    return t


def parse_table_block(lines: list[str]) -> list[list[str]]:
    rows = []
    for line in lines:
        raw = line.strip().strip('|')
        cells = [c.strip() for c in raw.split('|')]
        if all(re.fullmatch(r':?-{2,}:?', c) for c in cells if c):
            continue  # ردیف جداکننده
        rows.append(cells)
    return rows


def md_to_flowables(md_text: str) -> list:
    flow: list = []
    lines = md_text.splitlines()
    i = 0
    first_h1 = True
    while i < len(lines):
        line = lines[i]
        stripped = line.strip()

        if not stripped:
            i += 1
            continue

        # جدول
        if stripped.startswith('|'):
            block = []
            while i < len(lines) and lines[i].strip().startswith('|'):
                block.append(lines[i])
                i += 1
            rows = parse_table_block(block)
            flow.append(Spacer(1, 4))
            flow.append(build_table(rows))
            flow.append(Spacer(1, 6))
            continue

        # نقل‌قول (جعبه اطلاعات)
        if stripped.startswith('>'):
            block = []
            while i < len(lines) and lines[i].strip().startswith('>'):
                block.append(lines[i].strip()[1:].strip())
                i += 1
            flow.append(Spacer(1, 4))
            flow.append(build_quote(block))
            flow.append(Spacer(1, 6))
            continue

        # خط جداکننده
        if re.fullmatch(r'-{3,}', stripped):
            flow.append(Spacer(1, 4))
            flow.append(HRFlowable(width='100%', thickness=0.7,
                                   color=colors.HexColor('#c9d2e0'),
                                   spaceBefore=4, spaceAfter=8))
            i += 1
            continue

        # عنوان‌ها
        m = re.match(r'^(#{1,3})\s+(.*)$', stripped)
        if m:
            level = len(m.group(1))
            title = m.group(2).strip()
            if level == 1:
                if not first_h1:
                    flow.append(PageBreak())
                first_h1 = False
                para = TocParagraph(fa_runs(title), STYLES['H1'],
                                    toc_text=title, toc_level=0)
            elif level == 2:
                para = TocParagraph(fa_runs(title), STYLES['H2'],
                                    toc_text=title, toc_level=1)
            else:
                para = Paragraph(fa_runs(title), STYLES['H3'])
            flow.append(para)
            i += 1
            continue

        # فهرست‌ها
        if re.match(r'^[-*]\s+', stripped):
            text = re.sub(r'^[-*]\s+', '', stripped)
            flow.append(Paragraph(fa_runs(text), STYLES['bullet'], bulletText='•'))
            i += 1
            continue

        # پاراگراف
        flow.append(Paragraph(fa_runs(stripped), STYLES['body']))
        i += 1

    return flow


def build_cover() -> list:
    items: list = []
    items.append(Spacer(1, 3.2 * cm))
    items.append(Paragraph(fa('سند جامع سامانه اتوماسیون اداری'),
                           STYLES['cover_title']))
    items.append(Spacer(1, 0.4 * cm))
    items.append(Paragraph(fa('درخت کامل ماژول‌ها، زیرسیستم‌ها، فرم‌ها، درجه اهمیت و اولویت ساخت'),
                           STYLES['cover_sub']))
    items.append(Spacer(1, 1.2 * cm))
    meta = [
        [fa('نسخه'), fa('۲٫۰ — بازنگری و تکمیل')],
        [fa('تاریخ'), fa('۱۴۰۵/۰۷/۲۰')],
        [fa('وضعیت'), fa('پیش‌نویس نهایی برای تصویب مدیریت')],
        [fa('مبنا'), fa('بازنگری کارشناسی نسخه ۱٫۰ — رفع ایرادها + درجه اهمیت و اولویت ساخت + فازبندی اجرا و تولید')],
    ]
    t = Table(meta, colWidths=[3.6 * cm, 11.4 * cm])
    t.setStyle(TableStyle([
        ('FONTNAME', (0, 0), (0, -1), 'Vazirmatn-Bold'),
        ('FONTNAME', (1, 0), (1, -1), 'Vazirmatn'),
        ('FONTSIZE', (0, 0), (-1, -1), 11),
        ('TEXTCOLOR', (0, 0), (0, -1), NAVY),
        ('BACKGROUND', (0, 0), (-1, -1), LIGHT),
        ('GRID', (0, 0), (-1, -1), 0.5, colors.HexColor('#c9d2e0')),
        ('TOPPADDING', (0, 0), (-1, -1), 7),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 7),
        ('RIGHTPADDING', (0, 0), (-1, -1), 8),
    ]))
    items.append(t)
    items.append(Spacer(1, 1.4 * cm))
    box = [[Paragraph(fa_runs(
        '**خلاصه:** این سند نقشه کامل سامانه را با درجه اهمیت، اولویت ساخت، '
        'تصمیم «بساز / وصل کن / حذف»، نیازهای غیرعملکردی، مدل داده، ماتریس نقش‌ها، '
        'پل اتصال به سامانه تیکتینگ موجود و برنامه پنج‌فازی اجرا و تولید ارائه می‌کند.'), STYLES['quote'])]]
    bt = Table(box, colWidths=[15 * cm])
    bt.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#e7f2ef')),
        ('LINEBEFORE', (0, 0), (0, -1), 3, ACCENT),
        ('TOPPADDING', (0, 0), (-1, -1), 10),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 10),
        ('LEFTPADDING', (0, 0), (-1, -1), 10),
        ('RIGHTPADDING', (0, 0), (-1, -1), 10),
    ]))
    items.append(bt)
    return items


def build_toc() -> list:
    toc = TableOfContents()
    toc.levelStyles = [
        ParagraphStyle('toc0', fontName='Vazirmatn-Bold', fontSize=10.5,
                       leading=18, textColor=NAVY, rightIndent=0, firstLineIndent=0,
                       alignment=TA_RIGHT, wordWrap='RTL'),
        ParagraphStyle('toc1', fontName='Vazirmatn', fontSize=9,
                       leading=14, textColor=colors.HexColor('#33415c'),
                       rightIndent=16, firstLineIndent=0, alignment=TA_RIGHT,
                       wordWrap='RTL'),
    ]
    return [Paragraph(fa('فهرست مطالب'), STYLES['toc_h']),
            Spacer(1, 8), toc]


def main() -> None:
    with open(MD_PATH, encoding='utf-8') as fh:
        md_text = fh.read()

    doc = SpecDoc(PDF_PATH, title='سند جامع سامانه اتوماسیون اداری — نسخه ۲٫۰',
                  author='دفتر معماری سامانه آرینت', subject='تصویب مدیریت')

    story: list = []
    story.append(NextPageTemplate('body'))
    story.extend(build_cover())
    story.append(PageBreak())
    story.extend(build_toc())
    story.append(PageBreak())
    story.extend(md_to_flowables(md_text))

    doc.multiBuild(story)
    size_kb = os.path.getsize(PDF_PATH) / 1024
    print(f'PDF ساخته شد: {PDF_PATH}')
    print(f'اندازه: {size_kb:.0f} کیلوبایت')


if __name__ == '__main__':
    sys.exit(main())
