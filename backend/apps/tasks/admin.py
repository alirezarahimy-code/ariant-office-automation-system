from django.contrib import admin

from .models import Task, TaskEvent


class TaskEventInline(admin.TabularInline):
    model = TaskEvent
    extra = 0


@admin.register(Task)
class TaskAdmin(admin.ModelAdmin):
    list_display = ('title', 'responsible', 'due_date', 'priority', 'status', 'progress')
    list_filter = ('status', 'priority')
    search_fields = ('title', 'description')
    inlines = (TaskEventInline,)
