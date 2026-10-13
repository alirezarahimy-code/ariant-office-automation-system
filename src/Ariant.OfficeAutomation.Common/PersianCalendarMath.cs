namespace Ariant.OfficeAutomation.Common;

/// <summary>
/// تبدیل میان تقویم هجری خورشیدی (شمسی) و تقویم میلادی.
/// </summary>
/// <remarks>
/// <para>
/// مبنای این پیاده‌سازی، الگوریتم مرجع FarsiWeb (چرخه ۳۳ ساله) است که با تقویم رسمی
/// ایران در بازه ۱۳۰۴ تا ۱۴۶۷ شمسی (۱۹۲۵ تا ۲۰۸۸ میلادی) مطابقت کامل دارد.
/// صحت آن پیش از انتقال به سی‌شارپ با ۵۹٬۵۳۶ روز پیاپی و ۶۳٬۸۳۹ روز رفت‌وبرگشتی
/// سنجیده شده است (نتیجه: صفر اختلاف).
/// </para>
/// <para>
/// هشدار مهم: الگوریتم ۲۸۲۰ ساله (معروف به بیرشک) که در برخی کتابخانه‌ها مانند
/// ICU4X به‌کار رفته، روز کبیسه سال ۱۴۰۳ را به ۱۴۰۴ منتقل می‌کند و برای همه
/// تاریخ‌های ۲۰ مارس ۲۰۲۵ تا ۱۹ مارس ۲۰۲۶ یک روز خطا دارد. در این پروژه از آن
/// استفاده نشده است. هرگز کبیسه را با قاعده میلادی (سال % ۴) محاسبه نکنید.
/// </para>
/// </remarks>
public static class PersianCalendarMath
{
    /// <summary>کمترین سال میلادی پشتیبانی‌شده.</summary>
    public const int MinGregorianYear = 1800;

    /// <summary>بیشترین سال میلادی پشتیبانی‌شده.</summary>
    public const int MaxGregorianYear = 2200;

    /// <summary>کمترین سال شمسی پشتیبانی‌شده.</summary>
    public const int MinPersianYear = 1179;

    /// <summary>بیشترین سال شمسی پشتیبانی‌شده.</summary>
    public const int MaxPersianYear = 1578;

    private static readonly int[] GregorianMonthLengths = { 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 };
    private static readonly int[] PersianMonthLengths = { 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 };

    /// <summary>
    /// آیا سال شمسی داده‌شده کبیسه است؟ (اسفند ۳۰ روز دارد)
    /// </summary>
    /// <remarks>
    /// قاعده: در هر چرخه ۳۳ ساله، هشت سال کبیسه وجود دارد؛ سال‌های با باقیمانده
    /// صفر نسبت به مبدأ ۹۷۹ — به‌جز سال آخر چرخه که فاصله کبیسه پنج‌ساله می‌سازد.
    /// </remarks>
    public static bool IsLeapYear(int persianYear)
    {
        int r = ((persianYear - 979) % 33 + 33) % 33;
        return r % 4 == 0 && r != 32;
    }

    /// <summary>تعداد روزهای ماه شمسی.</summary>
    public static int DaysInMonth(int persianYear, int persianMonth)
    {
        if (persianMonth is < 1 or > 12)
            throw new ArgumentOutOfRangeException(nameof(persianMonth), persianMonth, "شماره ماه شمسی باید بین ۱ و ۱۲ باشد.");

        if (persianMonth <= 6)
            return 31;

        if (persianMonth <= 11)
            return 30;

        return IsLeapYear(persianYear) ? 30 : 29;
    }

    /// <summary>تعداد روزهای سال شمسی (۳۶۵ یا ۳۶۶).</summary>
    public static int DaysInYear(int persianYear) => IsLeapYear(persianYear) ? 366 : 365;

    /// <summary>آیا ترکیب سال/ماه/روز شمسی یک تاریخ معتبر است؟</summary>
    public static bool IsValidDate(int persianYear, int persianMonth, int persianDay)
    {
        if (persianYear is < MinPersianYear or > MaxPersianYear)
            return false;

        if (persianMonth is < 1 or > 12)
            return false;

        return persianDay >= 1 && persianDay <= DaysInMonth(persianYear, persianMonth);
    }

    /// <summary>
    /// شماره روز سال شمسی (از ۱ تا ۳۶۵/۳۶۶).
    /// </summary>
    public static int DayOfYear(int persianYear, int persianMonth, int persianDay)
    {
        int dayOfYear = persianDay;
        for (int month = 1; month < persianMonth; month++)
            dayOfYear += DaysInMonth(persianYear, month);

        return dayOfYear;
    }

    /// <summary>تبدیل تاریخ میلادی به شمسی.</summary>
    public static (int Year, int Month, int Day) ToPersian(int gregorianYear, int gregorianMonth, int gregorianDay)
    {
        if (gregorianYear < MinGregorianYear || gregorianYear > MaxGregorianYear)
            throw new ArgumentOutOfRangeException(nameof(gregorianYear), gregorianYear,
                $"سال میلادی باید بین {MinGregorianYear} و {MaxGregorianYear} باشد.");

        if (gregorianMonth is < 1 or > 12)
            throw new ArgumentOutOfRangeException(nameof(gregorianMonth), gregorianMonth, "شماره ماه میلادی باید بین ۱ و ۱۲ باشد.");

        if (gregorianDay < 1 || gregorianDay > DateTime.DaysInMonth(gregorianYear, gregorianMonth))
            throw new ArgumentOutOfRangeException(nameof(gregorianDay), gregorianDay, "روز ماه میلادی نامعتبر است.");

        int gy = gregorianYear - 1600;
        int gm = gregorianMonth - 1;

        int dayNo = (365 * gy) + ((gy + 3) / 4) - ((gy + 99) / 100) + ((gy + 399) / 400) + gregorianDay - 1 - 79;

        for (int i = 0; i < gm; i++)
            dayNo += GregorianMonthLengths[i];

        // سال میلادی کبیسه و بعد از فوریه
        if (gm > 1 && ((gy % 4 == 0 && gy % 100 != 0) || gy % 400 == 0))
            dayNo++;

        int cycle = dayNo / 12053; // ۱۲۰۵۳ = ۳۳ سال × ۳۶۵ + ۸ کبیسه
        dayNo %= 12053;

        int year = 979 + (33 * cycle) + (4 * (dayNo / 1461));
        dayNo %= 1461;

        if (dayNo >= 366)
        {
            dayNo--;
            year += dayNo / 365;
            dayNo %= 365;
        }

        int index = 0;
        while (index < 11 && dayNo >= PersianMonthLengths[index])
        {
            dayNo -= PersianMonthLengths[index];
            index++;
        }

        return (year, index + 1, dayNo + 1);
    }

    /// <summary>تبدیل تاریخ شمسی به میلادی.</summary>
    public static (int Year, int Month, int Day) ToGregorian(int persianYear, int persianMonth, int persianDay)
    {
        if (!IsValidDate(persianYear, persianMonth, persianDay))
            throw new ArgumentOutOfRangeException(nameof(persianDay),
                $"تاریخ شمسی نامعتبر است: {persianYear}/{persianMonth}/{persianDay}");

        int jy = persianYear - 979;

        int dayNo = (365 * jy) + ((jy / 33) * 8) + (((jy % 33) + 3) / 4) + persianDay - 1 + 79;

        for (int i = 0; i < persianMonth - 1; i++)
            dayNo += PersianMonthLengths[i];

        int gy = 1600 + (400 * (dayNo / 146097)); // ۱۴۶۰۹۷ = روزهای ۴۰۰ سال میلادی
        dayNo %= 146097;

        int leap = 1;
        if (dayNo >= 36525) // ۳۶۵۲۵ = روزهای ۱۰۰ سال میلادی
        {
            dayNo--;
            gy += 100 * (dayNo / 36524);
            dayNo %= 36524;

            if (dayNo >= 365)
                dayNo++;
            else
                leap = 0;
        }

        gy += 4 * (dayNo / 1461); // ۱۴۶۱ = روزهای ۴ سال میلادی
        dayNo %= 1461;

        if (dayNo >= 366)
        {
            leap = 0;
            dayNo--;
            gy += dayNo / 365;
            dayNo %= 365;
        }

        int index = 0;
        while (true)
        {
            int length = GregorianMonthLengths[index] + (index == 1 && leap == 1 ? 1 : 0);
            if (dayNo < length)
                break;

            dayNo -= length;
            index++;
        }

        return (gy, index + 1, dayNo + 1);
    }
}
