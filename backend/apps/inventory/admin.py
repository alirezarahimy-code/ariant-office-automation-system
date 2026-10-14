from django.contrib import admin

from .models import (Item, StockCount, StockCountLine, StockMove, Warehouse)


@admin.register(Warehouse)
class WarehouseAdmin(admin.ModelAdmin):
    list_display = ('name',)


@admin.register(Item)
class ItemAdmin(admin.ModelAdmin):
    list_display = ('code', 'name', 'unit', 'category', 'min_stock')
    search_fields = ('code', 'name')


@admin.register(StockMove)
class StockMoveAdmin(admin.ModelAdmin):
    list_display = ('item', 'warehouse', 'kind', 'quantity', 'date', 'reference')
    list_filter = ('kind', 'warehouse')


class StockCountLineInline(admin.TabularInline):
    model = StockCountLine
    extra = 0


@admin.register(StockCount)
class StockCountAdmin(admin.ModelAdmin):
    list_display = ('warehouse', 'date', 'status')
    inlines = [StockCountLineInline]
