namespace Ariant.OfficeAutomation.Application.Common;

/// <summary>پارامترهای صفحه‌بندی و جست‌وجو.</summary>
public class PagedQuery
{
    /// <summary>اندازه پیش‌فرض صفحه.</summary>
    public const int DefaultPageSize = 20;

    /// <summary>بیشترین اندازه مجاز صفحه.</summary>
    public const int MaxPageSize = 200;

    private int _page = 1;
    private int _pageSize = DefaultPageSize;

    /// <summary>شماره صفحه (از ۱).</summary>
    public int Page
    {
        get => _page;
        set => _page = value < 1 ? 1 : value;
    }

    /// <summary>تعداد رکورد در هر صفحه.</summary>
    public int PageSize
    {
        get => _pageSize;
        set => _pageSize = value switch
        {
            < 1 => DefaultPageSize,
            > MaxPageSize => MaxPageSize,
            _ => value
        };
    }

    /// <summary>عبارت جست‌وجو.</summary>
    public string? Search { get; set; }

    /// <summary>تعداد رکوردهای نادیده‌گرفته‌شده.</summary>
    public int Skip => (Page - 1) * PageSize;
}

/// <summary>نتیجه صفحه‌بندی‌شده.</summary>
/// <typeparam name="T">نوع آیتم‌ها.</typeparam>
public class PagedResult<T>
{
    /// <summary>ساخت نتیجه صفحه‌بندی‌شده.</summary>
    public PagedResult(IReadOnlyList<T> items, int totalCount, int page, int pageSize)
    {
        Items = items;
        TotalCount = totalCount;
        Page = page;
        PageSize = pageSize;
    }

    /// <summary>آیتم‌های صفحه جاری.</summary>
    public IReadOnlyList<T> Items { get; }

    /// <summary>شمار کل رکوردها.</summary>
    public int TotalCount { get; }

    /// <summary>شماره صفحه جاری.</summary>
    public int Page { get; }

    /// <summary>اندازه صفحه.</summary>
    public int PageSize { get; }

    /// <summary>شمار کل صفحه‌ها.</summary>
    public int TotalPages => PageSize <= 0 ? 0 : (int)Math.Ceiling(TotalCount / (double)PageSize);

    /// <summary>آیا صفحه پیشین وجود دارد؟</summary>
    public bool HasPrevious => Page > 1;

    /// <summary>آیا صفحه بعدی وجود دارد؟</summary>
    public bool HasNext => Page < TotalPages;

    /// <summary>نتیجه خالی.</summary>
    public static PagedResult<T> Empty(int pageSize = PagedQuery.DefaultPageSize)
        => new(Array.Empty<T>(), 0, 1, pageSize);

    /// <summary>تبدیل آیتم‌های صفحه به نوع دیگر.</summary>
    public PagedResult<TOut> Map<TOut>(Func<T, TOut> selector)
        => new(Items.Select(selector).ToList(), TotalCount, Page, PageSize);
}

/// <summary>گزینه لیست‌های کشویی (Value/Text).</summary>
public sealed class SelectOptionDto
{
    /// <summary>مقدار.</summary>
    public string Value { get; init; } = string.Empty;

    /// <summary>متن نمایشی.</summary>
    public string Text { get; init; } = string.Empty;

    /// <summary>ساخت گزینه.</summary>
    public static SelectOptionDto Create(string value, string text) => new() { Value = value, Text = text };
}
