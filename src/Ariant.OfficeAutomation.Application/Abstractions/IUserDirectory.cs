namespace Ariant.OfficeAutomation.Application.Abstractions;

/// <summary>اطلاعات خلاصه یک حساب کاربری.</summary>
public sealed class UserSummaryDto
{
    /// <summary>شناسه.</summary>
    public Guid Id { get; init; }

    /// <summary>نام کاربری.</summary>
    public string UserName { get; init; } = string.Empty;

    /// <summary>نام و نام خانوادگی.</summary>
    public string FullName { get; init; } = string.Empty;

    /// <summary>پست الکترونیک.</summary>
    public string? Email { get; init; }

    /// <summary>آیا حساب فعال است؟</summary>
    public bool IsActive { get; init; }

    /// <summary>آخرین ورود (میلادی).</summary>
    public DateTime? LastLoginAtUtc { get; init; }

    /// <summary>نقش‌های کاربر.</summary>
    public IReadOnlyList<string> Roles { get; init; } = Array.Empty<string>();
}

/// <summary>
/// انتزاع دسترسی به فهرست کاربران. پیاده‌سازی آن در لایه زیرساخت و روی
/// ASP.NET Core Identity انجام می‌شود تا لایه کاربرد به Identity وابسته نشود.
/// </summary>
public interface IUserDirectory
{
    /// <summary>شمار کل کاربران.</summary>
    Task<int> CountAsync(CancellationToken cancellationToken = default);

    /// <summary>شمار کاربران فعال.</summary>
    Task<int> CountActiveAsync(CancellationToken cancellationToken = default);

    /// <summary>فهرست کاربران (مرتب‌شده بر پایه آخرین ورود).</summary>
    Task<IReadOnlyList<UserSummaryDto>> GetRecentAsync(int take, CancellationToken cancellationToken = default);
}
