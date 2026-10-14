"""کاشت الگوهای نامه پرکاربرد (idempotent)."""
from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand

from apps.secretariat.models import Letter, LetterTemplate

TEMPLATES = [
    ('دعوت به جلسه', Letter.LetterType.INTERNAL, 'دعوت به جلسه {موضوع}',
     'با سلام و احترام،\nبدین‌وسیله از جنابعالی/سرکارعالی دعوت می‌شود در جلسه '
     'مورخ {تاریخ} در محل {محل} حضور به هم رسانید.\nدستور جلسه: {دستور}'),
    ('درخواست تجهیزات', Letter.LetterType.INTERNAL, 'درخواست تجهیزات اداری',
     'با سلام،\nبا عنایت به نیاز واحد، مراتب درخواست تجهیزات به شرح زیر '
     'به استحضار می‌رسد:\n۱) ...\n۲) ...\nخواهشمند است دستور فرمایید اقدام لازم معمول گردد.'),
    ('پیگیری مصوبه', Letter.LetterType.INTERNAL, 'پیگیری مصوبه {شماره}',
     'با سلام و احترام،\nپیرو مصوبه مورخ {تاریخ}، خواهشمند است گزارش پیشرفت '
     'اقدامات به دبیرخانه ارسال گردد.'),
]


class Command(BaseCommand):
    help = 'کاشت الگوهای نامه پرکاربرد (idempotent)'

    def handle(self, *args, **options):
        User = get_user_model()
        admin = User.objects.filter(is_superuser=True).first()
        created = 0
        for title, letter_type, subject, body in TEMPLATES:
            _, was_created = LetterTemplate.objects.get_or_create(
                title=title, defaults={'letter_type': letter_type,
                                       'subject_pattern': subject,
                                       'body': body, 'created_by': admin})
            created += int(was_created)
        self.stdout.write(self.style.SUCCESS(
            f'الگوهای نامه — {created} مورد تازه.'))
