using Ariant.OfficeAutomation.Application.Dashboard;
using Ariant.OfficeAutomation.Application.Organization;
using Ariant.OfficeAutomation.Application.Security;
using Microsoft.Extensions.DependencyInjection;

namespace Ariant.OfficeAutomation.Application;

/// <summary>ثبت سرویس‌های لایه کاربرد در ظرف وابستگی‌ها.</summary>
public static class DependencyInjection
{
    /// <summary>افزودن سرویس‌های لایه کاربرد.</summary>
    public static IServiceCollection AddApplication(this IServiceCollection services)
    {
        services.AddScoped<IDepartmentService, DepartmentService>();
        services.AddScoped<IDashboardService, DashboardService>();
        services.AddScoped<IAuditLogService, AuditLogService>();

        return services;
    }
}
