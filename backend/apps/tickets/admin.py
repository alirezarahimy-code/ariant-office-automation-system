from django.contrib import admin

from .models import Ticket, TicketEvent, TicketMessage


class TicketMessageInline(admin.TabularInline):
    model = TicketMessage
    extra = 0


class TicketEventInline(admin.TabularInline):
    model = TicketEvent
    extra = 0


@admin.register(Ticket)
class TicketAdmin(admin.ModelAdmin):
    list_display = ('subject', 'status', 'priority', 'service_group',
                    'requester', 'assignee', 'external_ref')
    list_filter = ('status', 'priority', 'service_group')
    search_fields = ('subject', 'body', 'external_ref')
    inlines = (TicketMessageInline, TicketEventInline)
