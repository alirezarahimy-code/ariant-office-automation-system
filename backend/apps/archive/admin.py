from django.contrib import admin

from .models import ArchiveBox, ArchiveFolder, ArchiveItem, DisposalRequest


@admin.register(ArchiveBox)
class ArchiveBoxAdmin(admin.ModelAdmin):
    list_display = ('code', 'title', 'is_active', 'created_at')
    search_fields = ('code', 'title')


@admin.register(ArchiveFolder)
class ArchiveFolderAdmin(admin.ModelAdmin):
    list_display = ('code', 'title', 'box', 'subject_kind', 'retention_years', 'is_active')
    list_filter = ('box', 'subject_kind', 'is_active')
    search_fields = ('code', 'title')


@admin.register(ArchiveItem)
class ArchiveItemAdmin(admin.ModelAdmin):
    list_display = ('title', 'reference', 'folder', 'archived_by', 'created_at')
    list_filter = ('folder__box',)
    search_fields = ('title', 'reference')


@admin.register(DisposalRequest)
class DisposalRequestAdmin(admin.ModelAdmin):
    list_display = ('folder', 'status', 'requested_by', 'decided_by', 'executed_at')
    list_filter = ('status',)
