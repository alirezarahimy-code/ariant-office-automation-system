"""کاشت کلیدهای قابلیت (idempotent) — اجزا همیشه هستند، سازمان روشن/خاموش می‌کند."""
from django.core.management.base import BaseCommand

from apps.core.models import FEATURE_REGISTRY, FeatureToggle
from apps.core.services import clear_feature_cache


class Command(BaseCommand):
    help = 'ایجاد/به‌روزرسانی کلیدهای قابلیت از FEATURE_REGISTRY'

    def handle(self, *args, **options):
        created = 0
        for code, (title, group) in FEATURE_REGISTRY.items():
            _, was_created = FeatureToggle.objects.get_or_create(
                code=code, defaults={'title': title, 'group': group})
            created += int(was_created)
        clear_feature_cache()
        self.stdout.write(self.style.SUCCESS(
            f'کلیدهای قابلیت: {len(FEATURE_REGISTRY)} مورد ({created} تازه).'))
