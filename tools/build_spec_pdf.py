#!/usr/bin/env python3
"""ساخت PDF فارسی سند جامع از فایل مارک‌داون.

اجرا:
    # سند جامع (پیش‌فرض‌ها):
    python3 tools/build_spec_pdf.py
    # سند دلخواه بدون جلد و فهرست:
    python3 tools/build_spec_pdf.py docs/07-manager-summary.md docs/07-manager-summary.pdf \
        --title "خلاصه مدیریتی" --no-toc --header "خلاصه مدیریتی — اتوماسیون اداری"

نیازمندی‌ها: reportlab, arabic-reshaper, python-bidi (در requirements نیستند؛
فقط برای تولید سند لازم‌اند). فونت وزیرمتن در tools/fonts موجود است.
"""
from __future__ import annotations

import argparse
import os
import re
import sys

import arabic_reshaper
from bidi.algorithm import get_display
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate,
    Flowable,
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

DOC_HEADER = 'سند جامع سامانه اتوماسیون اداری — نسخه ۲٫۰'

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


def pdigits(value) -> str:
    """تبدیل ارقام لاتین به فارسی."""
    return str(value).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))


def fa(text: str) -> str:
    """شکل‌دهی و ترتیب بصری برای رسم متن فارسی/عربی."""
    text = text.replace('«', '"').replace('»', '"')
    return get_display(arabic_reshaper.reshape(text))


def fa_runs(text: str) -> str:
    """تبدیل متن با پررنگ‌سازی **متن**.

    کل رشته (با نشانگرهای **) یکجا شکل و bidi می‌شود تا ترتیب کلمات و
    شکست خط درست بماند؛ سپس بر پایه نشانگرها قطعه‌های پررنگ جدا می‌شوند و
    فهرست قطعه‌ها به ترتیب منطقی باز می‌گردد (چیدمان RTL گزارش‌لب خودش
    نخستین قطعه را راست‌ترین می‌چیند).
    """
    if '**' not in text:
        return fa(text)
    disp = get_display(arabic_reshaper.reshape(text))
    parts = disp.split('**')
    chunks: list[tuple[str, bool]] = [
        (part, i % 2 == 1) for i, part in enumerate(parts)
    ]
    chunks.reverse()
    out = []
    for shaped, bold in chunks:
        if not shaped:
            continue
        out.append(f'<b>{shaped}</b>' if bold else shaped)
    return ''.join(out)


def fa_auto(text: str, width_pt: float, font: str, size: float) -> str:
    """متن با بولد، فقط اگر در یک خط جا شود.

    چیدمان راست‌به‌چپ reportlab تنها برای پاراگراف «تک‌تکه» ترتیب و شکست خط
    درست تولید می‌کند؛ پاراگراف چندتکه‌ای (**بولد**) که شکست خط بخورد،
    عبارت‌هایش جابهجا می‌شود. پس اگر متن پررنگ در یک خط جا شد، قطعه‌های
    بولد نگه داشته می‌شوند وگرنه متن ساده (تک‌تکه) می‌شود تا ترتیب درست بماند.
    """
    if '**' not in text:
        return fa(text)
    plain = text.replace('**', '')
    if pdfmetrics.stringWidth(fa(plain), font, size) <= width_pt:
        return fa_runs(text)
    return fa(plain)


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
        header = fa(DOC_HEADER)
        canvas.drawRightString(A4[0] - 2 * cm, A4[1] - 1.4 * cm, header)
        canvas.setStrokeColor(colors.HexColor('#c9d2e0'))
        canvas.setLineWidth(0.6)
        canvas.line(2 * cm, A4[1] - 1.6 * cm, A4[0] - 2 * cm, A4[1] - 1.6 * cm)
        page_label = fa(f'صفحه {pdigits(doc.page)}')
        canvas.drawCentredString(A4[0] / 2, 1.1 * cm, page_label)
        canvas.restoreState()

    def afterFlowable(self, flowable):
        text = getattr(flowable, 'toc_text', None)
        if text is None:
            return
        level = flowable.toc_level
        if self.page < getattr(self, '_last_toc_page', 10 ** 6):
            TOC_ENTRIES.clear()  # نخستین عنوان هر گذر: پاک‌سازی داده گذر قبل
        self._last_toc_page = self.page
        TOC_ENTRIES.append((level, text, self.page))
        key = f'sec-{self.page}-{abs(hash(text))}'
        self.canv.bookmarkPage(key)
        outline = getattr(flowable, 'outline_text', text)
        self.canv.addOutlineEntry(outline[:120], key, level=level, closed=(level > 0))


TOC_ENTRIES: list[tuple[int, str, int]] = []


class FaTocFlowable(Flowable):
    """فهرست مطالب سفارشی: عنوان در ستون راست، شماره صفحه در ستون چپ."""

    def __init__(self, styles: dict):
        super().__init__()
        self.styles = styles
        self._table = None
        self.height = 0

    def _build(self, width: float) -> Table:
        rows = []
        for level, text, page in TOC_ENTRIES:
            title_style = self.styles['toc0'] if level == 0 else self.styles['toc1']
            num = Paragraph(fa(str(page)), self.styles['tocnum'])
            title = Paragraph(text if text else ' ', title_style)
            rows.append([num, title])
        if not rows:
            rows = [[Paragraph(' ', self.styles['tocnum']),
                     Paragraph(fa(' '), self.styles['toc0'])]]
        t = Table(rows, colWidths=[1.4 * cm, width - 1.4 * cm])
        t.setStyle(TableStyle([
            ('VALIGN', (0, 0), (-1, -1), 'TOP'),
            ('TOPPADDING', (0, 0), (-1, -1), 2),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 2),
            ('LEFTPADDING', (0, 0), (-1, -1), 0),
            ('RIGHTPADDING', (0, 0), (-1, -1), 0),
            ('ALIGN', (0, 0), (0, -1), 'LEFT'),
        ]))
        return t

    def wrap(self, availWidth: float, availHeight: float):
        self._table = self._build(availWidth)
        _, height = self._table.wrap(availWidth, availHeight)
        self.height = height
        return availWidth, height

    def split(self, availWidth: float, availHeight: float):
        table = self._build(availWidth)
        parts = [pt for pt in table.split(availWidth, availHeight)
                 if getattr(pt, '_cellvalues', None)]
        if not parts:
            parts = [table]  # جا نشد؛ کل فهرست به صفحه تازه می‌رود
        return [FaTocPart(part) for part in parts]

    def draw(self):
        if self._table is not None:
            self._table.drawOn(self.canv, 0, 0)


class FaTocPart(Flowable):
    """بخشی از جدول فهرست مطالب برای شکست بین صفحات."""

    def __init__(self, table: Table):
        super().__init__()
        self._table = table

    def wrap(self, availWidth: float, availHeight: float):
        return self._table.wrap(availWidth, availHeight)

    def draw(self):
        self._table.drawOn(self.canv, 0, 0)


class TocParagraph(Paragraph):
    def __init__(self, text, style, toc_text=None, toc_level=0, outline_text=None):
        super().__init__(text, style)
        # toc_text: متن شکل‌گرفته برای نمایش در فهرست مطالب
        # outline_text: متن منطقی خام برای بوکمارک‌های ناوبری PDF
        self.toc_text = toc_text
        self.toc_level = toc_level
        self.outline_text = outline_text


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


def cell_paragraph(text: str, header: bool, wide: bool, width_pt: float):
    style = STYLES['cellwidehead' if wide else 'cellhead'] if header \
        else STYLES['cellwide' if wide else 'cell']
    color = None if header else (decision_color(text) or importance_color(text))
    shaped = fa_auto(text if text else '—', width_pt - 10, style.fontName,
                     style.fontSize)
    if color is not None:
        return Paragraph(f'<font color="{color.hexval()}">{shaped}</font>', style)
    return Paragraph(shaped, style)


def build_table(rows: list[list[str]]) -> Table:
    if not rows:
        return Spacer(1, 1)
    ncols = max(len(r) for r in rows)
    rows = [r + [''] * (ncols - len(r)) for r in rows]
    wide = ncols >= 6
    weights = []
    for col in range(ncols):
        longest = max(len(r[col]) for r in rows)
        weights.append(min(max(longest, 4), 42))
    total = sum(weights) or 1
    avail = A4[0] - 4 * cm
    col_widths = [avail * w / total for w in weights]
    data = []
    for ridx, row in enumerate(rows):
        data.append([cell_paragraph(c, header=(ridx == 0), wide=wide,
                                    width_pt=col_widths[cidx])
                     for cidx, c in enumerate(row)])
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
    inner = [Paragraph(fa_auto(l, A4[0] - 4 * cm - 24, 'Vazirmatn', 9.5),
                       STYLES['quote']) for l in lines if l.strip()]
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
                para = TocParagraph(fa_auto(title, A4[0] - 4 * cm, 'Vazirmatn-Black', 17), STYLES['H1'],
                                    toc_text=fa(title), toc_level=0,
                                    outline_text=title)
            elif level == 2:
                para = TocParagraph(fa_auto(title, A4[0] - 4 * cm, 'Vazirmatn-Bold', 12.5), STYLES['H2'],
                                    toc_text=fa(title), toc_level=1,
                                    outline_text=title)
            else:
                para = Paragraph(fa_auto(title, A4[0] - 4 * cm, 'Vazirmatn-Bold', 11), STYLES['H3'])
            flow.append(para)
            i += 1
            continue

        # فهرست‌ها
        if re.match(r'^[-*]\s+', stripped):
            text = re.sub(r'^[-*]\s+', '', stripped)
            flow.append(Paragraph(fa_auto(text, A4[0] - 5 * cm, 'Vazirmatn', 10),
                                  STYLES['bullet'], bulletText='•'))
            i += 1
            continue

        # پاراگراف
        flow.append(Paragraph(fa_auto(stripped, A4[0] - 4 * cm, 'Vazirmatn', 10),
                              STYLES['body']))
        i += 1

    return flow


def build_cover(title: str = 'سند جامع سامانه اتوماسیون اداری',
                subtitle: str = 'درخت کامل ماژول‌ها، زیرسیستم‌ها، فرم‌ها، درجه اهمیت و اولویت ساخت',
                meta: list | None = None,
                summary: str | None = None) -> list:
    items: list = []
    items.append(Spacer(1, 3.2 * cm))
    items.append(Paragraph(fa(title), STYLES['cover_title']))
    items.append(Spacer(1, 0.4 * cm))
    items.append(Paragraph(fa(subtitle), STYLES['cover_sub']))
    items.append(Spacer(1, 1.2 * cm))
    if meta is None:
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
    if summary:
        items.append(Spacer(1, 1.4 * cm))
        box = [[Paragraph(fa_auto(summary, 15 * cm - 24, 'Vazirmatn', 9.5),
                          STYLES['quote'])]]
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
    """فهرست مطالب از داده‌های گذر پیشین (همگرایی در حلقه ساخت)."""
    styles = {
        'toc0': ParagraphStyle('toc0', fontName='Vazirmatn-Bold', fontSize=10,
                               leading=16, textColor=NAVY, rightIndent=0,
                               firstLineIndent=0, alignment=TA_RIGHT,
                               wordWrap='RTL'),
        'toc1': ParagraphStyle('toc1', fontName='Vazirmatn', fontSize=8.5,
                               leading=12.5, textColor=colors.HexColor('#33415c'),
                               rightIndent=16, firstLineIndent=0,
                               alignment=TA_RIGHT, wordWrap='RTL'),
        'tocnum': ParagraphStyle('tocnum', fontName='Vazirmatn', fontSize=8.5,
                                 leading=12.5, textColor=colors.HexColor('#33415c'),
                                 alignment=TA_LEFT, wordWrap='RTL'),
    }
    rows = []
    for level, text, page in TOC_ENTRIES:
        title_style = styles['toc0'] if level == 0 else styles['toc1']
        rows.append([
            Paragraph(fa(pdigits(page)), styles['tocnum']),
            Paragraph(text if text else fa(' '), title_style),
        ])
    if not rows:
        rows = [[Paragraph(fa(' '), styles['tocnum']),
                 Paragraph(fa(' '), styles['toc0'])]]
    width = A4[0] - 4 * cm
    table = Table(rows, colWidths=[1.4 * cm, width - 1.4 * cm])
    table.setStyle(TableStyle([
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('TOPPADDING', (0, 0), (-1, -1), 1),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 1),
        ('LEFTPADDING', (0, 0), (-1, -1), 0),
        ('RIGHTPADDING', (0, 0), (-1, -1), 0),
        ('ALIGN', (0, 0), (0, -1), 'LEFT'),
    ]))
    return [Paragraph(fa('فهرست مطالب'), STYLES['toc_h']),
            Spacer(1, 8), table]


def main(argv: list[str] | None = None) -> int:
    global DOC_HEADER
    parser = argparse.ArgumentParser(
        description='ساخت PDF فارسی/راست‌به‌چپ از زیرمجموعه‌ای از Markdown')
    parser.add_argument('md', nargs='?', default=MD_PATH, help='فایل Markdown ورودی')
    parser.add_argument('pdf', nargs='?', default=PDF_PATH, help='فایل PDF خروجی')
    parser.add_argument('--title', default='سند جامع سامانه اتوماسیون اداری')
    parser.add_argument('--subtitle',
                        default='درخت کامل ماژول‌ها، زیرسیستم‌ها، فرم‌ها، درجه اهمیت و اولویت ساخت')
    parser.add_argument('--header', default=DOC_HEADER,
                        help='متن سربرگ صفحات')
    parser.add_argument('--meta', action='append', default=None,
                        metavar='کلید=مقدار', help='سطر جدول مشخصات جلد (قابل تکرار)')
    parser.add_argument('--summary', default=None,
                        help='متن جعبه خلاصه روی جلد (**برای درشت** پشتیبانی می‌شود)')
    parser.add_argument('--no-cover', action='store_true')
    parser.add_argument('--no-toc', action='store_true')
    args = parser.parse_args(argv)

    DOC_HEADER = args.header

    with open(args.md, encoding='utf-8') as fh:
        md_text = fh.read()

    meta = None
    if args.meta:
        meta = []
        for row in args.meta:
            key, _, value = row.partition('=')
            meta.append([fa(key.strip()), fa(value.strip())])

    def make_story() -> list:
        story: list = [NextPageTemplate('body')]
        if not args.no_cover:
            story.extend(build_cover(args.title, args.subtitle, meta, args.summary))
            story.append(PageBreak())
        if not args.no_toc:
            story.extend(build_toc())
            story.append(PageBreak())
        story.extend(md_to_flowables(md_text))
        return story

    # گذرهای متوالی تا همگرایی شماره صفحات فهرست مطالب؛
    # جریان‌ها در هر گذر تازه ساخته می‌شوند (بازمصرف جریان‌ها مجاز نیست)
    previous: list | None = None
    for _ in range(6):
        doc = SpecDoc(args.pdf, title=args.header,
                      author='دفتر معماری سامانه آرینت', subject='تصویب مدیریت')
        doc.build(make_story())
        current = list(TOC_ENTRIES)
        if previous == current:
            break
        previous = current
    size_kb = os.path.getsize(args.pdf) / 1024
    print(f'PDF ساخته شد: {args.pdf}')
    print(f'اندازه: {size_kb:.0f} کیلوبایت')
    return 0


if __name__ == '__main__':
    sys.exit(main())
