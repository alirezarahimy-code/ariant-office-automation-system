from django.contrib import admin

from .models import AttendanceRecord, WorkSchedule


@admin.register(WorkSchedule)
class WorkScheduleAdmin(admin.ModelAdmin):
    list_display = ('name', 'start_time', 'end_time', 'grace_minutes')


@admin.register(AttendanceRecord)
class AttendanceRecordAdmin(admin.ModelAdmin):
    list_display = ('user', 'date', 'check_in', 'check_out', 'source')
    list_filter = ('source',)
    search_fields = ('user__username',)
