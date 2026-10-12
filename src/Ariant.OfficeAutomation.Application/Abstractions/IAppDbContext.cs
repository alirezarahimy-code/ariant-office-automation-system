using Ariant.OfficeAutomation.Domain.Organization;
using Ariant.OfficeAutomation.Domain.Security;
using Microsoft.EntityFrameworkCore;

namespace Ariant.OfficeAutomation.Application.Abstractions;

/// <summary>
/// قرارداد دسترسی به پایگاه‌داده. لایه کاربرد فقط این انتزاع را می‌شناسد و
/// از جزئیات پیاده‌سازی (SQL Server / SQLite / …) بی‌خبر است.
/// </summary>
public interface IAppDbContext
{
    /// <summary>واحدهای سازمانی.</summary>
    DbSet<Department> Departments { get; }

    /// <summary>کارمندان.</summary>
    DbSet<Employee> Employees { get; }

    /// <summary>گزارش ممیزی.</summary>
    DbSet<AuditLog> AuditLogs { get; }

    /// <summary>ذخیره تغییرات.</summary>
    Task<int> SaveChangesAsync(CancellationToken cancellationToken = default);
}
