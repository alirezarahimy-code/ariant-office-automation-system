"""فیلدهای نمایشی فارسی/جلالی."""
import jdatetime
from django import template

register = template.Library()


@register.filter
def fa(value):
    """اعداد فارسی."""
    if value is None:
        return '—'
    text = f'{value:,}' if isinstance(value, (int, float)) else str(value)
    return text.translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹')).replace(',', '٬')


@register.filter
def jdate(value):
    if not value:
        return '—'
    try:
        if hasattr(value, 'togregorian'):
            j = value
        elif hasattr(value, 'year') and hasattr(value, 'month') and not hasattr(value, 'hour'):
            j = jdatetime.date.fromgregorian(date=value)
        else:
            return '—'
        months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                  'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند']
        return f'{j.day} {months[j.month - 1]} {j.year}'
    except Exception:
        return '—'


@register.filter
def hours(minutes):
    if minutes is None:
        return 0
    return round(float(minutes) / 60, 1)
