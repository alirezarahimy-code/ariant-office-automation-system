namespace Ariant.OfficeAutomation.Application.Common;

/// <summary>
/// نتیجه عملیات: موفق یا ناموفق با پیام خطای فارسی.
/// جایگزین سبک برای پرتاب استثنا در خطاهای قابل پیش‌بینی کسب‌وکار.
/// </summary>
public class Result
{
    /// <summary>پیام‌های خطا.</summary>
    private readonly List<string> _errors = new();

    /// <summary>ساخت نتیجه.</summary>
    protected internal Result(bool isSuccess, IEnumerable<string>? errors = null)
    {
        IsSuccess = isSuccess;
        if (errors is not null)
            _errors.AddRange(errors);
    }

    /// <summary>آیا عملیات موفق بود؟</summary>
    public bool IsSuccess { get; }

    /// <summary>آیا عملیات ناموفق بود؟</summary>
    public bool IsFailure => !IsSuccess;

    /// <summary>پیام‌های خطا.</summary>
    public IReadOnlyList<string> Errors => _errors;

    /// <summary>نخستین پیام خطا (برای نمایش سریع).</summary>
    public string ErrorMessage => _errors.Count > 0 ? _errors[0] : string.Empty;

    /// <summary>نتیجه موفق.</summary>
    public static Result Success() => new(true);

    /// <summary>نتیجه ناموفق با یک پیام.</summary>
    public static Result Failure(string error) => new(false, new[] { error });

    /// <summary>نتیجه ناموفق با چند پیام.</summary>
    public static Result Failure(IEnumerable<string> errors) => new(false, errors);

    /// <summary>نتیجه موفق همراه مقدار.</summary>
    public static Result<TValue> Success<TValue>(TValue value) => new(value, true);

    /// <summary>نتیجه ناموفق همراه نوع مقدار.</summary>
    public static Result<TValue> Failure<TValue>(string error) => new(default, false, new[] { error });

    /// <summary>نتیجه ناموفق همراه نوع مقدار و چند پیام.</summary>
    public static Result<TValue> Failure<TValue>(IEnumerable<string> errors) => new(default, false, errors);
}

/// <summary>نتیجه عملیات همراه مقدار بازگشتی.</summary>
/// <typeparam name="TValue">نوع مقدار.</typeparam>
public class Result<TValue> : Result
{
    private readonly TValue? _value;

    internal Result(TValue? value, bool isSuccess, IEnumerable<string>? errors = null)
        : base(isSuccess, errors)
        => _value = value;

    /// <summary>مقدار بازگشتی؛ در صورت شکست استثنا پرتاب می‌شود.</summary>
    public TValue Value => IsSuccess
        ? _value!
        : throw new InvalidOperationException("مقدار نتیجه ناموفق قابل دسترسی نیست.");

    /// <summary>مقدار یا مقدار جانشین در صورت شکست.</summary>
    public TValue? ValueOrDefault => _value;

    /// <summary>تبدیل نتیجه به بررسی شرط موفقیت (الگوی Try).</summary>
    public bool TryGetValue(out TValue? value)
    {
        value = _value;
        return IsSuccess;
    }

    /// <summary>تبدیل خودکار به مقدار.</summary>
    public static implicit operator TValue?(Result<TValue> result) => result._value;
}
