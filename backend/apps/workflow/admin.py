from django.contrib import admin

from .models import (Stage, Transition, Workflow, WorkflowAction,
                     WorkflowInstance)


class StageInline(admin.TabularInline):
    model = Stage
    extra = 0


class TransitionInline(admin.TabularInline):
    model = Transition
    extra = 0
    fk_name = 'workflow'


@admin.register(Workflow)
class WorkflowAdmin(admin.ModelAdmin):
    list_display = ('code', 'name', 'applies_to', 'is_active')
    search_fields = ('code', 'name')
    inlines = (StageInline, TransitionInline)


@admin.register(WorkflowInstance)
class WorkflowInstanceAdmin(admin.ModelAdmin):
    list_display = ('workflow', 'content_type', 'object_id', 'current_stage', 'started_by')
    list_filter = ('workflow',)


@admin.register(WorkflowAction)
class WorkflowActionAdmin(admin.ModelAdmin):
    list_display = ('instance', 'transition', 'from_stage', 'to_stage', 'actor', 'created_at')
