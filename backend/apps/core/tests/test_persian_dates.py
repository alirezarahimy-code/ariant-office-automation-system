"""آزمون تقویم شمسی با ۲۲۰ بردار طلایی مستقل (ICU).

این بردارها از موتور ICU (همان که در پایتون ۰ اختلاف با jdatetime
و ۰ اختلاف با موتور اختصاصی ما داشت) تولید و ثبت شده‌اند و هرگونه
خطای آینده در کتابخانه تاریخ را آشکار می‌کنند.
"""
import json
from datetime import date
from pathlib import Path

from django.test import TestCase

from apps.core.persian import (
    persian_weekday_name,
    persian_weekday_number,
)
import jdatetime

VECTORS_PATH = (
    Path(__file__).resolve().parent.parent / 'testdata' / 'persian_date_vectors.json'
)

_GREGORIAN_WEEKDAYS_FA_BY_SUNDAY0 = (
    'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه',
)


class PersianDateGoldenVectorTests(TestCase):
    @classmethod
    def setUpTestData(cls):
        with open(VECTORS_PATH, encoding='utf-8') as fh:
            cls.vectors = json.load(fh)
        assert len(cls.vectors) >= 200, 'بردارهای طلایی ناقص است'

    def test_jdatetime_matches_golden_vectors(self):
        for v in self.vectors:
            g = date.fromisoformat(v['g'])
            j = jdatetime.date.fromgregorian(date=g)
            self.assertEqual(
                (j.year, j.month, j.day), (v['jy'], v['jm'], v['jd']),
                f"تاریخ {v['g']}: jdatetime={(j.year, j.month, j.day)} "
                f"بردار طلایی={(v['jy'], v['jm'], v['jd'])}",
            )

    def test_weekday_matches_golden_vectors(self):
        for v in self.vectors:
            g = date.fromisoformat(v['g'])
            # wd در بردارها: شماره گریگوریانی با شنبه=6، یکشنبه=0 (getUTCDay)
            self.assertEqual(
                _GREGORIAN_WEEKDAYS_FA_BY_SUNDAY0[v['wd']],
                persian_weekday_name(g),
                f"روز هفته {v['g']} نادرست است",
            )

    def test_persian_weekday_number_contract(self):
        """قرارداد: شنبه=۱ ... جمعه=۷ (درس‌آموخته آزمون Nowruz)."""
        self.assertEqual(persian_weekday_number(date(2021, 3, 20)), 1)  # شنبه
        self.assertEqual(persian_weekday_number(date(2021, 3, 21)), 2)  # یکشنبه
        self.assertEqual(persian_weekday_name(date(2021, 3, 20)), 'شنبه')
        self.assertEqual(persian_weekday_name(date(2021, 3, 21)), 'یکشنبه')
