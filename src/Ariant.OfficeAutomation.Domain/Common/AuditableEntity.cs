using Ariant.OfficeAutomation.Common;

namespace Ariant.OfficeAutomation.Domain.Common;

/// <summary>
/// موجودیت دارای تاریخ ایجاد و ویرایش. فیلدهای زمانی همیشه میلادی (UTC) ذخیره
/// می‌شوند و در نمایش به شمسی تبدیل می‌گردند.
/// </summary>
public abstract class AuditableEntity : BaseEntity
{
    /// <summary>تاریخ ایجاد به شمسی (فقط خواندنی، برای نمایش).</summary>
    public PersianDate CreatedAtPersian => PersianDate.FromDateTime(CreatedAtUtc == default ? DateTime.UtcNow : CreatedAtUtc);

    /// <summary>تاریخ آخرین ویرایش به شمسی (در صورت وجود).</summary>
    public PersianDate? UpdatedAtPersian => UpdatedAtUtc.HasValue ? PersianDate.FromDateTime(UpdatedAtUtc.Value) : null;
}

/// <summary>موجودیت قابل حذف نرم.</summary>
public abstract class SoftDeletableEntity : AuditableEntity, ISoftDelete
{
    /// <inheritdoc />
    public bool IsDeleted { get; set; }

    /// <inheritdoc />
    public DateTime? DeletedAtUtc { get; set; }
}
