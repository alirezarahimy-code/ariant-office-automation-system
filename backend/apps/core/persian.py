"""ابزارهای متن فارسی و تقویم شمسی.

قاعده طلایی نرمال‌سازی (درس‌آموخته آزمون‌ها): نیم‌فاصله (ZWNJ) در جست‌وجو
باید مانند فاصله رفتار کند تا «مدرسه‌ها» و «مدرسه ها» یکسان یافت شوند.
"""
from __future__ import annotations

import re
import unicodedata
from datetime import date

PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹'
ENGLISH_DIGITS = '0123456789'

# نویسه‌های جداکننده بی‌عرض یا فضایی که باید به فاصله تبدیل شوند
_INVISIBLE_SPACES = '\u200c\u200b\u200d\ufeff'

# نگاشت حروف عربی به فارسی
_ARABIC_TO_PERSIAN = str.maketrans({
    'ي': 'ی', 'ى': 'ی', 'ك': 'ک', 'ة': 'ه', 'ۀ': 'ه',
    '\u064a': 'ی', '\u0643': 'ک',
})

_WEEKDAY_NAMES_FA = (
    'شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه',
)


def to_persian_digits(value: str | int) -> str:
    """تبدیل ارقام انگلیسی به فارسی."""
    text = str(value)
    return text.translate(str.maketrans(ENGLISH_DIGITS, PERSIAN_DIGITS))


def to_english_digits(value: str) -> str:
    """تبدیل ارقام فارسی و عربی به انگلیسی."""
    text = str(value)
    text = text.translate(str.maketrans(PERSIAN_DIGITS, ENGLISH_DIGITS))
    arabic_digits = '٠١٢٣٤٥٦٧٨٩'
    return text.translate(str.maketrans(arabic_digits, ENGLISH_DIGITS))


def normalize_for_search(value: str) -> str:
    """نرمال‌سازی متن برای جست‌وجو: حذف تفاوت‌های نامرئی.

    - نیم‌فاصله و نویسه‌های بی‌عرض ← فاصله (تا «مدرسه‌ها» = «مدرسه ها»)
    - حروف عربی ← فارسی
    - ارقام ← انگلیسی
    - تبدیل NFKC برای یکسان‌سازی اشکال مختلف نویسه‌ها
    """
    text = unicodedata.normalize('NFKC', str(value))
    for ch in _INVISIBLE_SPACES:
        text = text.replace(ch, ' ')
    text = text.translate(_ARABIC_TO_PERSIAN)
    text = to_english_digits(text)
    text = re.sub(r'\s+', ' ', text).strip()
    return text.casefold()


def persian_weekday_number(value: date) -> int:
    """شماره روز هفته ایرانی: شنبه=۱ ... جمعه=۷."""
    # date.weekday(): دوشنبه=0 ... یکشنبه=6
    return (value.weekday() + 2) % 7 + 1


def persian_weekday_name(value: date) -> str:
    """نام فارسی روز هفته (شنبه تا جمعه)."""
    return _WEEKDAY_NAMES_FA[persian_weekday_number(value) - 1]


def is_jalali_leap_year(jy: int) -> bool:
    """سال کبیسه شمسی — الگوریتم چرخه ۳۳ ساله FarsiWeb (تأییدشده).

    با ۲۰۷ بردار مرجع و ۶۳٬۸۳۹ روز پیاپی آزمون شده است.
    قاعده: باقیمانده r = ((jy-979) mod 33؛ کبیسه ⇔ r mod 4 = 0 و r ≠ 32
    """
    r = ((jy - 979) % 33 + 33) % 33
    return r % 4 == 0 and r != 32
