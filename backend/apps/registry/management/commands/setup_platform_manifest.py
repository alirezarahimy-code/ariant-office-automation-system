"""کاشت manifest بستر — ۱۱ زیرسامانه طبق docs/08 (ایدِمپوتنت)."""
from django.core.management.base import BaseCommand

from apps.registry.models import Subsystem

MANIFEST = [
    # کلید، عنوان، وضعیت، گام، اپ، منو، شرح، ترتیب
    ('secretariat', 'دبیرخانه و مکاتبات', 'native', 3, 'secretariat', '/kartable/',
     'ثبت، شماره‌گذاری، ارجاع، رونوشت، پیوست و امضای نامه‌ها', 10),
    ('workflow', 'گردش کار', 'native', 3, 'workflow', '/workflow/',
     'مسیرهای گردش قابل پیکربندی روی نامه‌ها', 20),
    ('archive', 'بایگانی و امحا', 'native', 3, 'archive', '/archive/',
     'صندوق، پوشه، ردیف بایگانی و چرخه امحا با تأیید مدیر', 30),
    ('meetings', 'جلسات و مصوبات', 'native', 3, 'meetings', '/meetings/',
     'دعوت، صورت‌جلسه، امضا و تبدیل مصوبه به وظیفه', 40),
    ('tasks', 'وظایف و تقویم', 'native', 3, 'tasks', '/tasks/',
     'انتساب وظیفه، پیشرفت، تقویم سازمانی شمسی', 50),
    ('reports', 'گزارش‌ها و داشبورد', 'native', 3, 'reports', '/reports/',
     'داشبورد شخصی و مدیریتی با نمودارهای تخت', 60),
    ('ticketing', 'تیکتینگ', 'migrating', 3, 'tickets', '/tickets/',
     'سامانه PHP فعلی با پل (docs/04)؛ زیرسامانه بومی در حال گذار', 70),
    ('access_control', 'کنترل تردد', 'planned', 0, '', '',
     'ورود و خروج کارکنان — ثبت در نقشه راه (بند ۸)', 80),
    ('fiscal_print', 'چاپ فیش حقوقی', 'planned', 0, '', '',
     'صدور فیش حقوقی — ثبت در نقشه راه (بند ۸)', 90),
    ('warehouse', 'انبار', 'planned', 0, '', '',
     'رسید و حواله کالا — ثبت در نقشه راه (بند ۸)', 100),
    ('commerce', 'امور بازرگانی', 'planned', 0, '', '',
     'خرید و قراردادها — ثبت در نقشه راه (بند ۸)', 110),
]


class Command(BaseCommand):
    help = 'کاشت/به‌روزرسانی manifest زیرسامانه‌های بستر'

    def handle(self, *args, **options):
        created_count = 0
        for key, title, status, step, app, menu, desc, order in MANIFEST:
            _, created = Subsystem.objects.update_or_create(
                key=key,
                defaults={'title': title, 'status': status, 'step': step,
                          'app_label': app, 'menu_url': menu,
                          'description': desc, 'order': order})
            created_count += int(created)
        self.stdout.write(self.style.SUCCESS(
            f'manifest بستر — {len(MANIFEST)} ردیف '
            f'({created_count} تازه، {len(MANIFEST) - created_count} به‌روز).'))
