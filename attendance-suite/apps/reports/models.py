"""موتور گزارش — تعریف گزارش (ReportDef) + گزارش‌های سیستمی."""
from django.db import models


class ReportDef(models.Model):
    """تعریف گزارش — ستون‌ها/فیلترها JSON (طراح گزارش مرجع)."""

    title = models.CharField('عنوان', max_length=160)
    code = models.CharField('کد', max_length=40, unique=True)
    module = models.CharField('ماژول', max_length=20, default='attendance')
    columns_json = models.JSONField('ستون‌ها', default=list, blank=True)
    filters_json = models.JSONField('فیلترها', default=list, blank=True)
    definition_json = models.JSONField('تعریف', default=dict, blank=True)
    is_system = models.BooleanField('سیستمی', default=False)

    class Meta:
        verbose_name = 'تعریف گزارش'
        verbose_name_plural = 'تعریف‌های گزارش'

    def __str__(self) -> str:
        return self.title
