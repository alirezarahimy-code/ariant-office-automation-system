"""مدیریت دبیرخانه — برای اشکال‌زدایی و عملیات روزانه."""
from django.contrib import admin

from .models import Attachment, Copy, Letter, LetterEvent, NumberSequence, Office, Referral


@admin.register(Office)
class OfficeAdmin(admin.ModelAdmin):
    list_display = ('name', 'code', 'department', 'is_active')
    list_filter = ('is_active',)
    search_fields = ('name', 'code')


@admin.register(NumberSequence)
class NumberSequenceAdmin(admin.ModelAdmin):
    list_display = ('office', 'letter_type', 'jalali_year', 'last_serial')
    list_filter = ('office', 'letter_type')
    readonly_fields = ('last_serial',)


class ReferralInline(admin.TabularInline):
    model = Referral
    extra = 0
    fields = ('to_user', 'to_department', 'note', 'deadline', 'status', 'parent')


class CopyInline(admin.TabularInline):
    model = Copy
    extra = 0
    fields = ('to_user', 'to_department', 'note', 'viewed_at')


class AttachmentInline(admin.TabularInline):
    model = Attachment
    extra = 0
    fields = ('file', 'original_name', 'size_bytes', 'uploaded_by')


class LetterEventInline(admin.TabularInline):
    model = LetterEvent
    extra = 0
    can_delete = False
    fields = ('action', 'actor', 'from_status', 'to_status', 'note', 'created_at')
    readonly_fields = fields

    def has_add_permission(self, request, obj=None):
        return False


@admin.register(Letter)
class LetterAdmin(admin.ModelAdmin):
    list_display = ('number_display', 'subject', 'letter_type', 'status', 'priority', 'confidentiality', 'deadline')
    list_filter = ('letter_type', 'status', 'priority', 'confidentiality', 'office')
    search_fields = ('subject', 'summary', 'sender_name', 'sender_org')
    readonly_fields = ('jalali_year', 'serial', 'created_at', 'updated_at')
    inlines = (ReferralInline, CopyInline, AttachmentInline, LetterEventInline)
