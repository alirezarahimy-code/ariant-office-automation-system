using System.Text.Json;
using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Domain.Common;
using Ariant.OfficeAutomation.Domain.Organization;
using Ariant.OfficeAutomation.Domain.Security;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.ChangeTracking;
using Microsoft.EntityFrameworkCore.Diagnostics;

namespace Ariant.OfficeAutomation.Infrastructure.Persistence.Interceptors;

/// <summary>
/// میان‌افزار (Interceptor) ثبت خودکار مهر زمانی و گزارش ممیزی.
/// هر ایجاد/ویرایش/حذف روی موجودیت‌های دامنه، به‌صورت خودکار در جدول
/// AuditLogs ثبت می‌شود؛ بنابراین هیچ ماژولی نمی‌تواند از این ثبت‌ها جا بماند.
/// </summary>
public sealed class AuditableEntityInterceptor : SaveChangesInterceptor
{
    private readonly ICurrentUserService _currentUserService;
    private readonly IDateTimeProvider _dateTimeProvider;

    /// <summary>ساخت میان‌افزار.</summary>
    public AuditableEntityInterceptor(ICurrentUserService currentUserService, IDateTimeProvider dateTimeProvider)
    {
        _currentUserService = currentUserService;
        _dateTimeProvider = dateTimeProvider;
    }

    /// <inheritdoc />
    public override InterceptionResult<int> SavingChanges(DbContextEventData eventData, InterceptionResult<int> result)
    {
        ApplyAudit(eventData.Context);
        return base.SavingChanges(eventData, result);
    }

    /// <inheritdoc />
    public override ValueTask<InterceptionResult<int>> SavingChangesAsync(
        DbContextEventData eventData,
        InterceptionResult<int> result,
        CancellationToken cancellationToken = default)
    {
        ApplyAudit(eventData.Context);
        return base.SavingChangesAsync(eventData, result, cancellationToken);
    }

    private void ApplyAudit(DbContext? context)
    {
        if (context is null)
            return;

        DateTime utcNow = _dateTimeProvider.UtcNow;
        Guid? userId = _currentUserService.UserId;
        string? userName = _currentUserService.UserName;

        // مرحله ۱: تبدیل حذف فیزیکی به حذف نرم (نگهداری داده‌ها)
        foreach (EntityEntry entry in context.ChangeTracker.Entries())
        {
            if (entry.State != EntityState.Deleted || entry.Entity is not ISoftDelete softDeletable)
                continue;

            softDeletable.IsDeleted = true;
            softDeletable.DeletedAtUtc = utcNow;

            entry.State = EntityState.Modified;

            foreach (PropertyEntry property in entry.Properties)
            {
                string name = property.Metadata.Name;
                property.IsModified = name is nameof(ISoftDelete.IsDeleted)
                    or nameof(ISoftDelete.DeletedAtUtc)
                    or nameof(BaseEntity.UpdatedAtUtc)
                    or nameof(BaseEntity.UpdatedByUserId)
                    or nameof(BaseEntity.UpdatedByUserName);
            }
        }

        // مرحله ۲: مهر زمانی ایجاد/ویرایش
        foreach (EntityEntry entry in context.ChangeTracker.Entries())
        {
            if (entry.Entity is not BaseEntity entity)
                continue;

            switch (entry.State)
            {
                case EntityState.Added:
                    if (entity.CreatedAtUtc == default)
                        entity.CreatedAtUtc = utcNow;

                    entity.CreatedByUserId ??= userId;
                    entity.CreatedByUserName ??= userName;
                    break;

                case EntityState.Modified:
                    entity.UpdatedAtUtc = utcNow;
                    entity.UpdatedByUserId = userId;
                    entity.UpdatedByUserName = userName;
                    break;
            }
        }

        // مرحله ۳: ساخت رکوردهای گزارش ممیزی
        var auditLogs = new List<AuditLog>();

        foreach (EntityEntry entry in context.ChangeTracker.Entries())
        {
            if (entry.Entity is not BaseEntity domainEntity)
                continue;

            bool isSoftDelete = entry.State == EntityState.Modified
                && domainEntity is ISoftDelete { IsDeleted: true } softDeleted
                && entry.Property(nameof(ISoftDelete.IsDeleted)).IsModified;

            if (entry.State is not (EntityState.Added or EntityState.Modified or EntityState.Deleted))
                continue;

            AuditAction action = entry.State switch
            {
                EntityState.Added => AuditAction.Created,
                EntityState.Deleted => AuditAction.Deleted,
                _ => isSoftDelete ? AuditAction.Deleted : AuditAction.Updated
            };

            var oldValues = new Dictionary<string, object?>();
            var newValues = new Dictionary<string, object?>();
            var changedColumns = new List<string>();

            foreach (PropertyEntry property in entry.Properties)
            {
                if (property.IsTemporary)
                    continue;

                string name = property.Metadata.Name;

                if (entry.State == EntityState.Added)
                {
                    newValues[name] = property.CurrentValue;
                    continue;
                }

                if (entry.State == EntityState.Deleted)
                {
                    oldValues[name] = property.OriginalValue;
                    continue;
                }

                if (!property.IsModified)
                    continue;

                changedColumns.Add(name);
                oldValues[name] = property.OriginalValue;
                newValues[name] = property.CurrentValue;
            }

            string? entityId = entry.Properties
                .FirstOrDefault(p => p.Metadata.IsPrimaryKey())?.CurrentValue?.ToString();

            auditLogs.Add(new AuditLog
            {
                TimestampUtc = utcNow,
                UserId = userId,
                UserName = userName ?? "سیستم",
                Action = action,
                EntityName = entry.Entity.GetType().Name,
                EntityId = entityId,
                AffectedColumns = changedColumns.Count > 0 ? string.Join(", ", changedColumns) : null,
                OldValues = oldValues.Count > 0 ? JsonSerializer.Serialize(oldValues) : null,
                NewValues = newValues.Count > 0 ? JsonSerializer.Serialize(newValues) : null,
                IpAddress = _currentUserService.IpAddress,
                UserAgent = _currentUserService.UserAgent,
                Description = BuildDescription(action, entry.Entity.GetType().Name, changedColumns)
            });
        }

        if (auditLogs.Count > 0)
            context.Set<AuditLog>().AddRange(auditLogs);
    }

    private static string BuildDescription(AuditAction action, string entityName, IReadOnlyCollection<string> changedColumns)
    {
        string entityTitle = entityName switch
        {
            nameof(Department) => "واحد سازمانی",
            nameof(Employee) => "کارمند",
            _ => entityName
        };

        string actionTitle = action switch
        {
            AuditAction.Created => "ایجاد",
            AuditAction.Updated => "ویرایش",
            AuditAction.Deleted => "حذف",
            _ => action.ToDisplayName()
        };

        string columns = changedColumns.Count > 0 ? $" (فیلدهای تغییریافته: {string.Join("، ", changedColumns)})" : string.Empty;
        return $"{actionTitle} {entityTitle}{columns}";
    }
}
