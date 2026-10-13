using System.Globalization;
using Ariant.OfficeAutomation.Common;
using Microsoft.EntityFrameworkCore.Storage.ValueConversion;

namespace Ariant.OfficeAutomation.Infrastructure.Persistence.Conversions;

/// <summary>
/// تبدیل‌گر EF Core برای نگهداری تاریخ شمسی در ستون متنی به شکل «1404/07/20».
/// </summary>
/// <remarks>
/// قرارداد کلی پروژه این است که تاریخ‌های سیستمی میلادی (UTC) ذخیره شوند.
/// این تبدیل‌گر برای ماژول‌هایی است که تاریخ شمسی، ماهیت خودِ داده است
/// (مثلاً تاریخ یک نامه اداری یا تاریخ صورت‌جلسه).
/// </remarks>
public sealed class PersianDateValueConverter : ValueConverter<PersianDate, string>
{
    /// <summary>ساخت تبدیل‌گر با قالب «yyyy/MM/dd» و ارقام لاتین (قابل مرتب‌سازی متنی).</summary>
    public PersianDateValueConverter()
        : base(
            date => ToStorageString(date),
            text => FromStorageString(text))
    {
    }

    /// <summary>تبدیل تاریخ شمسی به متن قابل ذخیره.</summary>
    public static string ToStorageString(PersianDate date)
        => date.Year.ToString("0000", CultureInfo.InvariantCulture) + "/" +
           date.Month.ToString("00", CultureInfo.InvariantCulture) + "/" +
           date.Day.ToString("00", CultureInfo.InvariantCulture);

    /// <summary>تبدیل متن ذخیره‌شده به تاریخ شمسی.</summary>
    public static PersianDate FromStorageString(string? value)
    {
        if (PersianDate.TryParse(value, out PersianDate date))
            return date;

        throw new FormatException($"مقدار تاریخ شمسی ذخیره‌شده معتبر نیست: '{value}'");
    }
}

/// <summary>تبدیل‌گر EF Core برای تاریخ شمسی پذیرنده مقدار خالی.</summary>
public sealed class NullablePersianDateValueConverter : ValueConverter<PersianDate?, string?>
{
    /// <summary>ساخت تبدیل‌گر.</summary>
    public NullablePersianDateValueConverter()
        : base(
            date => date.HasValue ? PersianDateValueConverter.ToStorageString(date.Value) : null,
            text => string.IsNullOrWhiteSpace(text)
                ? null
                : PersianDateValueConverter.FromStorageString(text))
    {
    }
}
