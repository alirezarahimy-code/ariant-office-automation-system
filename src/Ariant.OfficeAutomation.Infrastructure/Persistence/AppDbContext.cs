using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Domain.Organization;
using Ariant.OfficeAutomation.Domain.Security;
using Ariant.OfficeAutomation.Infrastructure.Identity;
using Microsoft.AspNetCore.Identity.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore;

namespace Ariant.OfficeAutomation.Infrastructure.Persistence;

/// <summary>
/// زمینه پایگاه‌داده سامانه؛ شامل جداول Identity و جداول کسب‌وکار.
/// </summary>
public class AppDbContext : IdentityDbContext<ApplicationUser, ApplicationRole, Guid>, IAppDbContext
{
    /// <summary>ساخت زمینه پایگاه‌داده.</summary>
    public AppDbContext(DbContextOptions<AppDbContext> options) : base(options)
    {
    }

    /// <summary>واحدهای سازمانی.</summary>
    public DbSet<Department> Departments => Set<Department>();

    /// <summary>کارمندان.</summary>
    public DbSet<Employee> Employees => Set<Employee>();

    /// <summary>گزارش ممیزی.</summary>
    public DbSet<AuditLog> AuditLogs => Set<AuditLog>();

    /// <inheritdoc />
    protected override void OnModelCreating(ModelBuilder builder)
    {
        ArgumentNullException.ThrowIfNull(builder);

        base.OnModelCreating(builder);

        // همه پیکربندی‌های موجود در این اسمبلی (پوشه Persistence/Configurations)
        builder.ApplyConfigurationsFromAssembly(typeof(AppDbContext).Assembly);

        // نام‌گذاری ساده‌تر جداول هویت (بدون پیشوند AspNet)
        builder.Entity<ApplicationUser>().ToTable("Users");
        builder.Entity<ApplicationRole>().ToTable("Roles");
    }
}
