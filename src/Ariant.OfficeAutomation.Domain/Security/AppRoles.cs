namespace Ariant.OfficeAutomation.Domain.Security;

/// <summary>
/// نقش‌های سامانه. نام نقش‌ها به انگلیسی نگهداری می‌شود (شناسه پایدار) و
/// عنوان فارسی جداگانه برای نمایش تعریف می‌گردد.
/// </summary>
public static class AppRoles
{
    /// <summary>مدیر سیستم: دسترسی کامل.</summary>
    public const string Admin = "Admin";

    /// <summary>مدیر: مشاهده و مدیریت واحدهای سازمانی و کارکنان.</summary>
    public const string Manager = "Manager";

    /// <summary>کارمند: دسترسی پایه.</summary>
    public const string Employee = "Employee";

    /// <summary>عنوان فارسی نقش‌ها.</summary>
    public static readonly IReadOnlyDictionary<string, string> Titles = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
    {
        [Admin] = "مدیر سیستم",
        [Manager] = "مدیر",
        [Employee] = "کارمند"
    };

    /// <summary>همه نقش‌ها.</summary>
    public static readonly IReadOnlyList<string> All = new[] { Admin, Manager, Employee };

    /// <summary>عنوان فارسی نقش.</summary>
    public static string GetTitle(string roleName)
        => Titles.TryGetValue(roleName, out string? title) ? title : roleName;

    /// <summary>تبدیل فهرست نقش‌ها به عنوان فارسی.</summary>
    public static IEnumerable<string> ToTitles(IEnumerable<string> roleNames)
        => roleNames.Select(GetTitle);
}

/// <summary>نام سیاست‌های مجوزدهی (Authorization Policies).</summary>
public static class AppPolicies
{
    /// <summary>مدیریت ساختار سازمانی.</summary>
    public const string ManageOrganization = "ManageOrganization";

    /// <summary>مشاهده ساختار سازمانی.</summary>
    public const string ViewOrganization = "ViewOrganization";

    /// <summary>مشاهده گزارش ممیزی.</summary>
    public const string ViewAuditLog = "ViewAuditLog";

    /// <summary>مدیریت کاربران.</summary>
    public const string ManageUsers = "ManageUsers";

    /// <summary>دسترسی برنامه‌نویسی (وب‌سرویس) از سامانه‌های بیرونی، مانند سامانه تیکتینگ.</summary>
    public const string ApiAccess = "ApiAccess";
}
