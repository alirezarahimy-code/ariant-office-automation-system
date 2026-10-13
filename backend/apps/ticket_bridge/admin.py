from django.contrib import admin

from .models import ExternalTicket, IntegrationUserMap


@admin.register(IntegrationUserMap)
class IntegrationUserMapAdmin(admin.ModelAdmin):
    list_display = ('platform_user', 'system', 'external_username', 'external_user_id')
    list_filter = ('system',)
    search_fields = ('external_username', 'platform_user__username')


@admin.register(ExternalTicket)
class ExternalTicketAdmin(admin.ModelAdmin):
    list_display = ('external_id', 'subject', 'status', 'priority', 'requester_username', 'created_label')
    list_filter = ('system', 'status', 'service_group')
    search_fields = ('subject', 'external_id', 'requester_username')
    readonly_fields = ('payload', 'fetched_at')
