namespace Ariant.OfficeAutomation.Infrastructure.Options;

/// <summary>تنظیمات پایگاه‌داده.</summary>
public sealed class DatabaseOptions
{
    /// <summary>نام بخش در فایل تنظیمات.</summary>
    public const string SectionName = "Database";

    /// <summary>ارائه‌دهنده پایگاه‌داده: SqlServer یا Sqlite.</summary>
    public string Provider { get; set; } = "Sqlite";

    /// <summary>
    /// اگر true باشد، به‌جای ساخت خودکار جداول، مهاجرت‌های EF Core
    /// (dotnet ef database update) اجرا می‌شوند. برای محیط عملیاتی توصیه می‌شود.
    /// </summary>
    public bool UseMigrations { get; set; }

    /// <summary>ثبت جزئیات بیشتر خطاها (فقط در محیط توسعه).</summary>
    public bool EnableDetailedErrors { get; set; }

    /// <summary>درج داده‌های نمونه (ساختار سازمانی و کارمندان) در نخستین اجرا.</summary>
    public bool SeedSampleData { get; set; } = true;
}

/// <summary>تنظیمات نشانه دسترسی (JWT) برای وب‌سرویس و یکپارچه‌سازی با سامانه‌های دیگر.</summary>
public sealed class JwtOptions
{
    /// <summary>نام بخش در فایل تنظیمات.</summary>
    public const string SectionName = "Jwt";

    /// <summary>صادرکننده نشانه.</summary>
    public string Issuer { get; set; } = "Ariant.OfficeAutomation";

    /// <summary>مخاطب نشانه.</summary>
    public string Audience { get; set; } = "Ariant.Clients";

    /// <summary>کلید امضا (حداقل ۳۲ نویسه). در محیط عملیاتی از متغیر محیطی خوانده شود.</summary>
    public string SigningKey { get; set; } = string.Empty;

    /// <summary>مدت اعتبار نشانه (دقیقه).</summary>
    public int ExpiryMinutes { get; set; } = 480;
}

/// <summary>تنظیمات داده‌های اولیه.</summary>
public sealed class SeedOptions
{
    /// <summary>نام بخش در فایل تنظیمات.</summary>
    public const string SectionName = "Seed";

    /// <summary>نام کاربری مدیر سامانه.</summary>
    public string AdminUserName { get; set; } = "admin";

    /// <summary>گذرواژه مدیر سامانه (در محیط عملیاتی از متغیر محیطی خوانده شود).</summary>
    public string AdminPassword { get; set; } = "Admin@1404";

    /// <summary>پست الکترونیک مدیر سامانه.</summary>
    public string AdminEmail { get; set; } = "admin@ariant.local";

    /// <summary>نام مدیر سامانه.</summary>
    public string AdminFirstName { get; set; } = "مدیر";

    /// <summary>نام خانوادگی مدیر سامانه.</summary>
    public string AdminLastName { get; set; } = "سیستم";
}
