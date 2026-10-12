namespace Ariant.OfficeAutomation.Common;

/// <summary>نام ماه‌ها و روزهای هفته در تقویم شمسی.</summary>
public static class PersianDateNames
{
    /// <summary>نام ماه‌های شمسی از فروردین تا اسفند.</summary>
    public static readonly string[] MonthNames =
    {
        "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
        "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"
    };

    /// <summary>
    /// نام روزهای هفته، هم‌تراز با شاخص <see cref="DayOfWeek"/> دات‌نت
    /// (۰ = یکشنبه ... ۶ = شنبه). هفته کاری در ایران از شنبه آغاز می‌شود.
    /// </summary>
    public static readonly string[] DayOfWeekNames =
    {
        "یکشنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنجشنبه", "جمعه", "شنبه"
    };

    /// <summary>نام کوتاه ماه (سه حرف نخست).</summary>
    public static string ShortMonthName(int persianMonth)
    {
        if (persianMonth is < 1 or > 12)
            throw new ArgumentOutOfRangeException(nameof(persianMonth), persianMonth, "شماره ماه شمسی باید بین ۱ و ۱۲ باشد.");

        return MonthNames[persianMonth - 1];
    }

    /// <summary>نام روز هفته برای شاخص داده‌شده.</summary>
    public static string DayName(DayOfWeek dayOfWeek) => DayOfWeekNames[(int)dayOfWeek];

    /// <summary>فهرست فصل‌ها برای ماه داده‌شده.</summary>
    public static string SeasonName(int persianMonth) => persianMonth switch
    {
        >= 1 and <= 3 => "بهار",
        >= 4 and <= 6 => "تابستان",
        >= 7 and <= 9 => "پاییز",
        _ => "زمستان"
    };
}
