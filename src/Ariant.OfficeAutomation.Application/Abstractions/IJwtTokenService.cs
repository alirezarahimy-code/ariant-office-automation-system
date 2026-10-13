namespace Ariant.OfficeAutomation.Application.Abstractions;

/// <summary>نشانه دسترسی صادرشده.</summary>
public sealed class TokenResultDto
{
    /// <summary>نشانه دسترسی (JWT).</summary>
    public string AccessToken { get; init; } = string.Empty;

    /// <summary>نوع نشانه.</summary>
    public string TokenType { get; init; } = "Bearer";

    /// <summary>زمان انقضا (UTC).</summary>
    public DateTime ExpiresAtUtc { get; init; }

    /// <summary>زمان انقضا به وقت ایران.</summary>
    public DateTime ExpiresAtLocal => ExpiresAtUtc.ToLocalTime();
}

/// <summary>
/// سرویس صدور نشانه دسترسی. سامانه‌های دیگر (مانند سامانه تیکتینگ PHP)
/// با این نشانه می‌توانند به وب‌سرویس سامانه متصل شوند.
/// </summary>
public interface IJwtTokenService
{
    /// <summary>صدور نشانه برای کاربر.</summary>
    Task<TokenResultDto> CreateAsync(Guid userId, CancellationToken cancellationToken = default);
}
