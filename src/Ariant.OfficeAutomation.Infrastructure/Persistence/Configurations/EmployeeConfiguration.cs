using Ariant.OfficeAutomation.Domain.Organization;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;

namespace Ariant.OfficeAutomation.Infrastructure.Persistence.Configurations;

/// <inheritdoc />
public sealed class EmployeeConfiguration : IEntityTypeConfiguration<Employee>
{
    /// <inheritdoc />
    public void Configure(EntityTypeBuilder<Employee> builder)
    {
        ArgumentNullException.ThrowIfNull(builder);

        builder.ToTable("Employees");
        builder.HasKey(e => e.Id);

        builder.Property(e => e.FirstName).HasMaxLength(100).IsRequired();
        builder.Property(e => e.LastName).HasMaxLength(100).IsRequired();
        builder.Property(e => e.NationalCode).HasMaxLength(10);
        builder.Property(e => e.PersonnelCode).HasMaxLength(20);
        builder.Property(e => e.JobTitle).HasMaxLength(150);
        builder.Property(e => e.MobileNumber).HasMaxLength(20);
        builder.Property(e => e.Email).HasMaxLength(256);
        builder.Property(e => e.Notes).HasMaxLength(2000);
        builder.Property(e => e.CreatedByUserName).HasMaxLength(256);
        builder.Property(e => e.UpdatedByUserName).HasMaxLength(256);

        builder.Ignore(e => e.FullName);
        builder.Ignore(e => e.HireDatePersian);
        builder.Ignore(e => e.BirthDatePersian);

        builder.HasIndex(e => e.NationalCode);
        builder.HasIndex(e => e.PersonnelCode);
        builder.HasIndex(e => e.DepartmentId);
        builder.HasIndex(e => e.UserId);
        builder.HasIndex(e => new { e.LastName, e.FirstName });

        builder.HasQueryFilter(e => !e.IsDeleted);
    }
}
