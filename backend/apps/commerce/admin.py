from django.contrib import admin

from .models import Contract, Inquiry, PurchaseRequest


@admin.register(PurchaseRequest)
class PurchaseRequestAdmin(admin.ModelAdmin):
    list_display = ('title', 'requested_by', 'status', 'estimated_cost')
    list_filter = ('status',)


@admin.register(Inquiry)
class InquiryAdmin(admin.ModelAdmin):
    list_display = ('request', 'supplier_name', 'quote_amount', 'selected')


@admin.register(Contract)
class ContractAdmin(admin.ModelAdmin):
    list_display = ('title', 'party', 'amount', 'start_date', 'status')
    list_filter = ('status',)
