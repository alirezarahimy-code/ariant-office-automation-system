namespace Ariant.OfficeAutomation.Application.Abstractions;

/// <summary>دسترسی به اطلاعات کاربر جاری (برای مهر زمانی و گزارش ممیزی).</summary>
public interface ICurrentUserService
{
    /// <summary>شناسه کاربر جاری؛ در صورت نبود نشست، خالی است.</summary>
    Guid? UserId { get; }

    /// <summary>نام کاربری جاری.</summary>
    string? UserName { get; }

    /// <summary>نشانی شبکه کاربر.</summary>
    string? IpAddress { get; }

    /// <summary>عامل کاربر (مرورگر).</summary>
    string? UserAgent { get; }

    /// <summary>آیا کاربر وارد شده است؟</summary>
    bool IsAuthenticated { get; }

    /// <summary>آیا کاربر عضو نقش داده‌شده است؟</summary>
    bool IsInRole(string role);
}

/// <summary>فراهم‌کننده زمان؛ برای آزمون‌پذیری به‌جای استفاده مستقیم از DateTime.Now.</summary>
public interface IDateTimeProvider
{
    /// <summary>زمان کنونی به وقت جهانی.</summary>
    DateTime UtcNow { get; }

    /// <summary>زمان کنونی به وقت محلی ایران.</summary>
    DateTime Now { get; }
}
