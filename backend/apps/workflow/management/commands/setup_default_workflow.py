"""ساخت گردش پیش‌فرض نامه — منطبق بر گردش فاز ۱ (idempotent)."""
from django.core.management.base import BaseCommand

from apps.workflow.models import Stage, Transition, Workflow

STAGES = [
    ('registered', 'ثبت', 1, True, False),
    ('in_review', 'بررسی/ارجاع', 2, False, False),
    ('in_progress', 'در جریان', 3, False, False),
    ('answered', 'پاسخ', 4, False, False),
    ('closed', 'پایان/بایگانی', 5, False, True),
]

TRANSITIONS = [
    ('registered', 'in_review', 'ارجاع برای بررسی', 'secretariat.refer_letter', '', '', 1),
    ('registered', 'answered', 'پاسخ مستقیم', 'secretariat.answer_letter', '', '', 2),
    ('in_review', 'in_progress', 'ارجاع به کارشناس', 'secretariat.refer_letter', '', '', 1),
    ('in_review', 'answered', 'پاسخ مدیر', 'secretariat.answer_letter', '', '', 2),
    ('in_progress', 'answered', 'ثبت پاسخ/اقدام', 'secretariat.answer_letter', '', '', 1),
    ('answered', 'closed', 'بستن و بایگانی', 'secretariat.close_letter', '', '', 1),
    ('registered', 'in_progress', 'رسیدگی فوری', 'secretariat.refer_letter', 'priority', 'immediate', 3),
]


class Command(BaseCommand):
    help = 'ساخت/به‌روزرسانی گردش پیش‌فرض نامه (letter-default)'

    def handle(self, *args, **options):
        workflow, created = Workflow.objects.get_or_create(
            code='letter-default',
            defaults={'name': 'گردش استاندارد نامه', 'applies_to': 'letter',
                      'description': 'مسیر پیش‌فرض مکاتبات: ثبت، بررسی، اقدام، پاسخ و بایگانی.'})
        stages = {}
        for key, title, order, is_start, is_final in STAGES:
            stage, _ = Stage.objects.get_or_create(
                workflow=workflow, key=key,
                defaults={'title': title, 'order': order,
                          'is_start': is_start, 'is_final': is_final})
            stages[key] = stage
        transitions = 0
        for frm, to, title, perm, cond_f, cond_v, order in TRANSITIONS:
            _, was_created = Transition.objects.get_or_create(
                workflow=workflow, from_stage=stages[frm], to_stage=stages[to],
                defaults={'title': title, 'permission_codename': perm,
                          'condition_field': cond_f, 'condition_value': cond_v,
                          'order': order})
            transitions += int(was_created)
        self.stdout.write(self.style.SUCCESS(
            f'گردش «{workflow.name}» — {len(stages)} مرحله، {transitions} گذار تازه.'))
