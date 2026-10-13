using Microsoft.AspNetCore.Identity;

namespace Ariant.OfficeAutomation.Infrastructure.Identity;

/// <summary>
/// حساب کاربری سامانه بر پایه ASP.NET Core Identity.
/// </summary>
public class ApplicationUser : IdentityUser<Guid>
{
    /// <summary>ساخت حساب کاربری با شناسه یکتای جدید.</summary>
    public ApplicationUser()
    {
        Id = Guid.CreateVersion7();
    }

    /// <summary>نام.</summary>
    public string FirstName { get; set; } = string.Empty;

    /// <summary>نام خانوادگی.</summary>
    public string LastName { get; set; } = string.Empty;

    /// <summary>شناسه پرونده پرسنلی متناظر (اختیاری).</summary>
    public Guid? EmployeeId { get; set; }

    /// <summary>آیا حساب فعال است؟ (حساب غیرفعال اجازه ورود ندارد)</summary>
    public bool IsActive { get; set; } = true;

    /// <summary>آیا کاربر باید در نخستین ورود، گذرواژه خود را تغییر دهد؟</summary>
    public bool MustChangePassword { get; set; }

    /// <summary>زمان آخرین ورود موفق (میلادی، UTC).</summary>
    public DateTime? LastLoginAtUtc { get; set; }

    /// <summary>زمان ایجاد حساب (میلادی، UTC).</summary>
    public DateTime CreatedAtUtc { get; set; } = DateTime.UtcNow;

    /// <summary>نام و نام خانوادگی.</summary>
    public string FullName => $"{FirstName} {LastName}".Trim() is { Length: > 0 } name ? name : (UserName ?? string.Empty);

    /// <summary>نام کاربری برای نمایش.</summary>
    public string DisplayName => string.IsNullOrWhiteSpace(UserName) ? FullName : $"{FullName} ({UserName})";
}

/// <summary>نقش سامانه با عنوان فارسی.</summary>
public class ApplicationRole : IdentityRole<Guid>
{
    /// <summary>ساخت نقش با شناسه یکتای جدید.</summary>
    public ApplicationRole()
    {
        Id = Guid.CreateVersion7();
    }

    /// <summary>ساخت نقش با نام مشخص.</summary>
    public ApplicationRole(string roleName) : this()
    {
        Name = roleName;
    }

    /// <summary>عنوان فارسی نقش (برای نمایش).</summary>
    public string? Title { get; set; }

    /// <summary>توضیح نقش.</summary>
    public string? Description { get; set; }
}
