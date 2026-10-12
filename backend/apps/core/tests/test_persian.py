"""آزمون ابزارهای متن فارسی — شامل درس‌آموخته نیم‌فاصله در جست‌وجو."""
from django.test import TestCase

from apps.core.persian import (
    is_jalali_leap_year,
    normalize_for_search,
    to_english_digits,
    to_persian_digits,
)


class PersianTextTests(TestCase):
    def test_digits_round_trip(self):
        self.assertEqual(to_persian_digits(1405), '۱۴۰۵')
        self.assertEqual(to_english_digits('۱۴۰۵/۰۷/۲۰'), '1405/07/20')
        self.assertEqual(to_english_digits('٠١٢٣'), '0123')  # ارقام عربی

    def test_search_ignores_zwnj(self):
        """نیم‌فاصله در جست‌وجو مانند فاصله است: «مدرسه‌ها» = «مدرسه ها»."""
        self.assertEqual(
            normalize_for_search('مدرسه\u200cها'),
            normalize_for_search('مدرسه ها'),
        )

    def test_search_normalizes_arabic_letters(self):
        self.assertEqual(
            normalize_for_search('كتابخانه مركزي'),   # ك و ي عربی
            normalize_for_search('کتابخانه مرکزی'),   # ک و ی فارسی
        )

    def test_search_collapses_whitespace(self):
        self.assertEqual(
            normalize_for_search('  دبیرخانه   مرکزی  '),
            'دبیرخانه مرکزی',
        )

    def test_leap_rule_matches_jdatetime(self):
        """قاعده کبیسه ما با jdatetime (مرجع تأییدشده) یکسان باشد."""
        import jdatetime

        for jy in range(1300, 1501):
            try:
                jdatetime.date(jy, 12, 30)
                expected = True
            except ValueError:
                expected = False
            self.assertEqual(
                is_jalali_leap_year(jy), expected,
                f'سال {jy}: قاعده ما {is_jalali_leap_year(jy)} ولی مرجع {expected}',
            )

    def test_known_leap_years(self):
        """لبه‌های تأییدشده از آزمون‌های قبلی (docs)."""
        self.assertTrue(is_jalali_leap_year(1403))   # 1403/12/30 معتبر
        self.assertFalse(is_jalali_leap_year(1404))  # 1404/12/30 نامعتبر
        self.assertTrue(is_jalali_leap_year(1408))
        self.assertTrue(is_jalali_leap_year(1399))   # 1399/12/30 → 2021-03-20 معتبر
