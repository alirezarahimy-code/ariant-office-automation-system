namespace Ariant.OfficeAutomation.Domain.Common;

/// <summary>
/// ریشه مشترک همه موجودیت‌ها.
/// کلید اصلی از نوع Guid نسخه ۷ است تا ترتیب زمانی داشته باشد و در
/// یکپارچه‌سازی با سامانه‌های دیگر (مثل سامانه تیکتینگ PHP) تعارض ایجاد نکند.
/// </summary>
public abstract class BaseEntity
{
    /// <summary>شناسه یکتا.</summary>
    public Guid Id { get; protected set; } = Guid.CreateVersion7();

    /// <summary>تاریخ ایجاد رکورد (میلادی، UTC) — فیلد سطح پایگاه‌داده.</summary>
    public DateTime CreatedAtUtc { get; set; }

    /// <summary>شناسه کاربر ایجادکننده.</summary>
    public Guid? CreatedByUserId { get; set; }

    /// <summary>نام کاربر ایجادکننده (برای ماندگاری حتی پس از حذف کاربر).</summary>
    public string? CreatedByUserName { get; set; }

    /// <summary>تاریخ آخرین ویرایش (میلادی، UTC).</summary>
    public DateTime? UpdatedAtUtc { get; set; }

    /// <summary>شناسه آخرین ویرایش‌کننده.</summary>
    public Guid? UpdatedByUserId { get; set; }

    /// <summary>نام آخرین ویرایش‌کننده.</summary>
    public string? UpdatedByUserName { get; set; }
}

/// <summary>نشانه حذف نرم (منطقی): رکورد از دید کاربر حذف می‌شود ولی داده باقی می‌ماند.</summary>
public interface ISoftDelete
{
    /// <summary>آیا رکورد حذف نرم شده است؟</summary>
    bool IsDeleted { get; set; }

    /// <summary>تاریخ حذف نرم (میلادی، UTC).</summary>
    DateTime? DeletedAtUtc { get; set; }
}
