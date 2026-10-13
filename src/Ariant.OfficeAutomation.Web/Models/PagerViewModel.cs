namespace Ariant.OfficeAutomation.Web.Models;

/// <summary>مدل نمایش جزء صفحه‌بندی.</summary>
public class PagerViewModel
{
    /// <summary>شماره صفحه جاری.</summary>
    public int Page { get; init; } = 1;

    /// <summary>اندازه صفحه.</summary>
    public int PageSize { get; init; } = 20;

    /// <summary>شمار کل صفحه‌ها.</summary>
    public int TotalPages { get; init; }

    /// <summary>شمار کل رکوردها.</summary>
    public int TotalCount { get; init; }

    /// <summary>نام اکشن مقصد.</summary>
    public string Action { get; init; } = "Index";

    /// <summary>عبارت جست‌وجوی جاری (برای حفظ در صفحه‌بندی).</summary>
    public string? Search { get; init; }

    /// <summary>سایر پارامترهای نگه‌داشتنی جست‌وجو.</summary>
    public string? FilterState { get; init; }
}
