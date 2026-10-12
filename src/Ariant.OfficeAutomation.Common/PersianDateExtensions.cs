using System.Globalization;

namespace Ariant.OfficeAutomation.Common;

/// <summary>میان‌برهای کار با تاریخ در لایه نمایش و گزارش.</summary>
public static class PersianDateExtensions
{
    /// <summary>تبدیل تاریخ میلادی به تاریخ شمسی.</summary>
    public static PersianDate ToPersianDate(this DateTime dateTime) => PersianDate.FromDateTime(dateTime);

    /// <summary>تبدیل تاریخ-ساعت به تاریخ شمسی.</summary>
    public static PersianDate ToPersianDate(this DateTimeOffset dateTimeOffset) => PersianDate.FromDateTimeOffset(dateTimeOffset);

    /// <summary>تبدیل تاریخ میلادی به متن شمسی (به‌صورت پیش‌فرض با ارقام فارسی).</summary>
    public static string ToPersianDateString(this DateTime dateTime, string format = PersianDate.DefaultFormat)
        => PersianDate.FromDateTime(dateTime).ToLocalizedString(format);

    /// <summary>تاریخ شمسی به شکل بلند (۲۰ مهر ۱۴۰۴).</summary>
    public static string ToPersianLongDateString(this DateTime dateTime)
        => PersianDate.FromDateTime(dateTime).ToLongString();

    /// <summary>تاریخ و ساعت به شکل «۲۰ مهر ۱۴۰۴ - ۱۴:۳۰».</summary>
    public static string ToPersianDateTimeString(this DateTime dateTime)
    {
        var persianDate = PersianDate.FromDateTime(dateTime);
        string time = dateTime.ToString("HH:mm", CultureInfo.InvariantCulture);
        return $"{persianDate.ToLongString()} - {PersianText.ToPersianDigits(time)}";
    }

    /// <summary>مقدار تاریخ خالی را به شکل «—» نشان می‌دهد (مناسب جدول‌ها).</summary>
    public static string ToPersianDateString(this DateTime? dateTime, string format = PersianDate.DefaultFormat)
        => dateTime.HasValue ? dateTime.Value.ToPersianDateString(format) : "—";

    /// <summary>مقدار تاریخ خالی را به شکل «—» نشان می‌دهد.</summary>
    public static string ToPersianLongDateString(this DateTime? dateTime)
        => dateTime.HasValue ? dateTime.Value.ToPersianLongDateString() : "—";

    /// <summary>تبدیل متن تاریخ شمسی به میلادی (برای ذخیره در پایگاه‌داده).</summary>
    public static DateTime ToGregorianDateTime(this string persianDateText)
        => PersianDate.TryParse(persianDateText, out PersianDate value)
            ? value.ToDateTime()
            : throw new FormatException($"متن «{persianDateText}» تاریخ شمسی معتبری نیست.");

    /// <summary>آیا مقدار تاریخ در گذشته است؟</summary>
    public static bool IsPast(this PersianDate persianDate) => persianDate < PersianDate.Today;

    /// <summary>آیا مقدار تاریخ در آینده است؟</summary>
    public static bool IsFuture(this PersianDate persianDate) => persianDate > PersianDate.Today;

    /// <summary>تعداد روزهای میان دو تاریخ شمسی.</summary>
    public static int DaysBetween(this PersianDate from, PersianDate to)
        => (int)(to.ToDateTime() - from.ToDateTime()).TotalDays;
}
