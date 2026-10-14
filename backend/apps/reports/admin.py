from django.contrib import admin

from .models import ScheduledReport


@admin.register(ScheduledReport)
class ScheduledReportAdmin(admin.ModelAdmin):
    list_display = ('name', 'slug', 'period', 'is_active', 'last_sent_at')
    list_filter = ('period', 'is_active')
