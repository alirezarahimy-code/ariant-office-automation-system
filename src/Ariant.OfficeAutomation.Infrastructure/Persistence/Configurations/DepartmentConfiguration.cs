using Ariant.OfficeAutomation.Domain.Organization;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace Ariant.OfficeAutomation.Infrastructure.Persistence.Configurations;

/// <inheritdoc />
public sealed class DepartmentConfiguration : IEntityTypeConfiguration<Department>
{
    /// <inheritdoc />
    public void Configure(EntityTypeBuilder<Department> builder)
    {
        ArgumentNullException.ThrowIfNull(builder);

        builder.ToTable("Departments");
        builder.HasKey(d => d.Id);

        builder.Property(d => d.Title).HasMaxLength(150).IsRequired();
        builder.Property(d => d.Code).HasMaxLength(30);
        builder.Property(d => d.Description).HasMaxLength(1000);
        builder.Property(d => d.CreatedByUserName).HasMaxLength(256);
        builder.Property(d => d.UpdatedByUserName).HasMaxLength(256);

        builder.HasIndex(d => d.Code);
        builder.HasIndex(d => d.SortOrder);
        builder.HasIndex(d => new { d.ParentId, d.Title });

        builder.HasOne(d => d.Parent)
            .WithMany(d => d.Children)
            .HasForeignKey(d => d.ParentId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasMany(d => d.Employees)
            .WithOne(e => e.Department!)
            .HasForeignKey(e => e.DepartmentId)
            .OnDelete(DeleteBehavior.SetNull);

        // حذف نرم: رکوردهای حذف‌شده در هیچ پرس‌وجویی دیده نمی‌شوند
        builder.HasQueryFilter(d => !d.IsDeleted);
    }
}
