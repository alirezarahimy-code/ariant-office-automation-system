#!/usr/bin/env python3
"""نقطه ورود خط فرمان — سامانه یکپارچه حضور و غیاب."""
import os
import sys


def main() -> None:
    os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
    try:
        from django.core.management import execute_from_command_line
    except ImportError as exc:
        raise ImportError('جنگو نصب نیست: pip install -r requirements.txt') from exc
    execute_from_command_line(sys.argv)


if __name__ == '__main__':
    main()
