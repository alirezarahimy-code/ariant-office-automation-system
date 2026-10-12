#!/usr/bin/env python3
"""نقطه ورود خط فرمان جنگو — سامانه اتوماسیون اداری آرینت."""
import os
import sys


def main() -> None:
    os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')
    try:
        from django.core.management import execute_from_command_line
    except ImportError as exc:  # pragma: no cover
        raise ImportError(
            'جنگو نصب نیست. ابتدا نیازمندی‌ها را نصب کنید: '
            'pip install -r requirements/base.txt'
        ) from exc
    execute_from_command_line(sys.argv)


if __name__ == '__main__':
    main()
