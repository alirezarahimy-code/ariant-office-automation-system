from django.contrib import admin
from django.contrib.auth.admin import UserAdmin

from .models import (AppSetting, AuditLog, NotificationTemplate, Permission,
                     Role, RoleDataScope, User, UserRole)


@admin.register(User)
class SuiteUserAdmin(UserAdmin):
    fieldsets = UserAdmin.fieldsets + (
        ('پرسنل', {'fields': ('employee',)}),
    )


admin.site.register(Permission)
admin.site.register(Role)
admin.site.register(RoleDataScope)
admin.site.register(UserRole)
admin.site.register(AuditLog)
admin.site.register(AppSetting)
admin.site.register(NotificationTemplate)
