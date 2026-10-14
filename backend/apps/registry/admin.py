from django.contrib import admin

from .models import Subsystem


@admin.register(Subsystem)
class SubsystemAdmin(admin.ModelAdmin):
    list_display = ('key', 'title', 'status', 'step', 'app_label', 'menu_url', 'order')
    list_filter = ('status', 'step')
    search_fields = ('key', 'title', 'description')
