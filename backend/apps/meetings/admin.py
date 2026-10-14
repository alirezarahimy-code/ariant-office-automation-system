from django.contrib import admin

from .models import (AgendaItem, Attendee, Meeting, Minutes,
                     MinutesSignature, Resolution)


class AttendeeInline(admin.TabularInline):
    model = Attendee
    extra = 0


class AgendaInline(admin.TabularInline):
    model = AgendaItem
    extra = 0


@admin.register(Meeting)
class MeetingAdmin(admin.ModelAdmin):
    list_display = ('title', 'jalali_date', 'status', 'organizer', 'location')
    list_filter = ('status',)
    search_fields = ('title',)
    inlines = (AttendeeInline, AgendaInline)


@admin.register(Minutes)
class MinutesAdmin(admin.ModelAdmin):
    list_display = ('meeting', 'is_final', 'finalized_at')


@admin.register(MinutesSignature)
class MinutesSignatureAdmin(admin.ModelAdmin):
    list_display = ('minutes', 'user', 'position', 'created_at')


@admin.register(Resolution)
class ResolutionAdmin(admin.ModelAdmin):
    list_display = ('number', 'title', 'meeting', 'responsible', 'status')
    list_filter = ('status',)
