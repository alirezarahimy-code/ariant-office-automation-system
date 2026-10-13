using Ariant.OfficeAutomation.Domain.Security;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace Ariant.OfficeAutomation.Infrastructure.Persistence.Configurations;

/// <inheritdoc />
public sealed class AuditLogConfiguration : IEntityTypeConfiguration<AuditLog>
{
    /// <inheritdoc />
    public void Configure(EntityTypeBuilder<AuditLog> builder)
    {
        ArgumentNullException.ThrowIfNull(builder);

        builder.ToTable("AuditLogs");
        builder.HasKey(a => a.Id);
        builder.Property(a => a.Id).ValueGeneratedOnAdd();

        builder.Property(a => a.EntityName).HasMaxLength(150).IsRequired();
        builder.Property(a => a.EntityId).HasMaxLength(100);
        builder.Property(a => a.UserName).HasMaxLength(256);
        builder.Property(a => a.AffectedColumns).HasMaxLength(2000);
        builder.Property(a => a.IpAddress).HasMaxLength(64);
        builder.Property(a => a.UserAgent).HasMaxLength(500);
        builder.Property(a => a.Description).HasMaxLength(1000);

        builder.Ignore(a => a.TimestampPersian);

        builder.HasIndex(a => a.TimestampUtc);
        builder.HasIndex(a => a.EntityName);
        builder.HasIndex(a => a.UserName);
        builder.HasIndex(a => a.Action);
    }
}
