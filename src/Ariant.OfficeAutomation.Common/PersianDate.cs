using System.Globalization;
using System.Text;

namespace Ariant.OfficeAutomation.Common;

/// <summary>
/// نوع مقدار (value type) تاریخ شمسی؛ سبک، تغییرناپذیر (immutable) و قابل مقایسه.
/// </summary>
/// <remarks>
/// <para>قرارداد پروژه: تاریخ‌ها در پایگاه‌داده همیشه میلادی (UTC) ذخیره می‌شوند و
/// تنها در لایه نمایش با این نوع به شمسی تبدیل می‌شوند. این کار مرتب‌سازی،
/// مقایسه و گزارش‌گیری را ساده و از خطاهای رایج جلوگیری می‌کند.</para>
/// <para>الگوهای قالب‌بندی: <c>yyyy</c> سال، <c>MM</c> ماه، <c>dd</c> روز،
/// <c>MMMM</c> نام ماه، <c>dddd</c> نام روز هفته. قالب‌های آماده:
/// <c>s</c> کوتاه (۱۴۰۴/۰۷/۲۰)، <c>l</c> بلند (۲۰ مهر ۱۴۰۴)،
/// <c>f</c> کامل با نام روز هفته.</para>
/// </remarks>
public readonly struct PersianDate :
    IComparable<PersianDate>,
    IEquatable<PersianDate>,
    IFormattable,
    IParsable<PersianDate>
{
    /// <summary>قالب پیش‌فرض: ۱۴۰۴/۰۷/۲۰</summary>
    public const string DefaultFormat = "yyyy/MM/dd";

    /// <summary>کمترین تاریخ قابل نمایش.</summary>
    public static readonly PersianDate MinValue = new(1179, 1, 1);

    /// <summary>بیشترین تاریخ قابل نمایش.</summary>
    public static readonly PersianDate MaxValue = new(1578, 12, 29);

    private readonly int _year;
    private readonly int _month;
    private readonly int _day;

    /// <summary>ساخت تاریخ شمسی با اعتبارسنجی کامل.</summary>
    /// <exception cref="ArgumentOutOfRangeException">اگر تاریخ معتبر نباشد.</exception>
    public PersianDate(int year, int month, int day)
    {
        if (!PersianCalendarMath.IsValidDate(year, month, day))
        {
            throw new ArgumentOutOfRangeException(nameof(day), day,
                $"تاریخ شمسی نامعتبر است: {year}/{month}/{day}");
        }

        _year = year;
        _month = month;
        _day = day;
    }

    /// <summary>سال شمسی.</summary>
    public int Year => _year;

    /// <summary>ماه شمسی (۱ تا ۱۲).</summary>
    public int Month => _month;

    /// <summary>روز ماه (۱ تا ۳۱).</summary>
    public int Day => _day;

    /// <summary>آیا سال جاری کبیسه است؟</summary>
    public bool IsLeapYear => PersianCalendarMath.IsLeapYear(_year);

    /// <summary>تعداد روزهای ماه جاری.</summary>
    public int DaysInMonth => PersianCalendarMath.DaysInMonth(_year, _month);

    /// <summary>شماره روز سال (۱ تا ۳۶۶).</summary>
    public int DayOfYear => PersianCalendarMath.DayOfYear(_year, _month, _day);

    /// <summary>نام ماه جاری (مثلاً «مهر»).</summary>
    public string MonthName => PersianDateNames.MonthNames[_month - 1];

    /// <summary>روز هفته.</summary>
    public DayOfWeek DayOfWeek => ToDateTime().DayOfWeek;

    /// <summary>نام روز هفته (مثلاً «شنبه»).</summary>
    public string DayOfWeekName => PersianDateNames.DayName(DayOfWeek);

    /// <summary>فصل سال (بهار/تابستان/پاییز/زمستان).</summary>
    public string SeasonName => PersianDateNames.SeasonName(_month);

    /// <summary>آیا روز تعطیل هفتگی (جمعه) است؟</summary>
    public bool IsFriday => DayOfWeek == DayOfWeek.Friday;

    /// <summary>تاریخ امروز به شمسی.</summary>
    public static PersianDate Today => FromDateTime(DateTime.Today);

    /// <summary>تاریخ و ساعت کنونی به شمسی.</summary>
    public static PersianDate Now => FromDateTime(DateTime.Now);

    /// <summary>تبدیل تاریخ میلادی به شمسی.</summary>
    public static PersianDate FromDateTime(DateTime dateTime)
    {
        var (year, month, day) = PersianCalendarMath.ToPersian(dateTime.Year, dateTime.Month, dateTime.Day);
        return new PersianDate(year, month, day);
    }

    /// <summary>تبدیل تاریخ-ساعت با اختلاف منطقه زمانی به شمسی (بر پایه زمان محلی).</summary>
    public static PersianDate FromDateTimeOffset(DateTimeOffset dateTimeOffset)
        => FromDateTime(dateTimeOffset.LocalDateTime);

    /// <summary>تبدیل از اعداد سال/ماه/روز بدون پرتاب استثنا؛ در صورت نامعتبر بودن false برمی‌گرداند.</summary>
    public static bool TryCreate(int year, int month, int day, out PersianDate result)
    {
        if (PersianCalendarMath.IsValidDate(year, month, day))
        {
            result = new PersianDate(year, month, day);
            return true;
        }

        result = default;
        return false;
    }

    /// <summary>تبدیل به تاریخ میلادی (نیمه‌شب، بدون منطقه زمانی).</summary>
    public DateTime ToDateTime() => ToDateTime(TimeOnly.MinValue);

    /// <summary>تبدیل به تاریخ و ساعت میلادی.</summary>
    public DateTime ToDateTime(TimeOnly timeOfDay)
    {
        var (year, month, day) = PersianCalendarMath.ToGregorian(_year, _month, _day);
        return new DateTime(year, month, day, timeOfDay.Hour, timeOfDay.Minute, timeOfDay.Second, DateTimeKind.Unspecified);
    }

    /// <summary>تبدیل به تاریخ-ساعت میلادی در منطقه زمانی ایران.</summary>
    public DateTimeOffset ToDateTimeOffset(TimeOnly timeOfDay)
    {
        var local = DateTime.SpecifyKind(ToDateTime(timeOfDay), DateTimeKind.Unspecified);
        return new DateTimeOffset(local, TimeZoneInfo.Local.GetUtcOffset(local));
    }

    /// <summary>افزودن روز.</summary>
    public PersianDate AddDays(int days) => FromDateTime(ToDateTime().AddDays(days));

    /// <summary>افزودن ماه (با حفظ روز تا حد امکان؛ مثلاً ۳۱ فروردین + یک ماه = ۳۱ اردیبهشت).</summary>
    public PersianDate AddMonths(int months)
    {
        int zeroBased = (_year * 12) + (_month - 1) + months;
        int year = (int)Math.Floor(zeroBased / 12.0);
        int month = zeroBased - (year * 12) + 1;
        int day = Math.Min(_day, PersianCalendarMath.DaysInMonth(year, month));
        return new PersianDate(year, month, day);
    }

    /// <summary>افزودن سال (۲۹ اسفند در سال کبیسه به ۲۹ اسفند سال غیرکبیسه تبدیل می‌شود).</summary>
    public PersianDate AddYears(int years)
    {
        int year = _year + years;
        int day = Math.Min(_day, PersianCalendarMath.DaysInMonth(year, _month));
        return new PersianDate(year, _month, day);
    }

    /// <summary>شماره روز هفته به ترتیب ایرانی (شنبه = ۱ ... جمعه = ۷).</summary>
    public int PersianWeekDayNumber => DayOfWeek == DayOfWeek.Saturday ? 1 : (int)DayOfWeek + 2;

    /// <summary>شماره هفته در سال (بر پایه شروع هفته از شنبه).</summary>
    public int PersianWeekOfYear
    {
        get
        {
            var firstDay = new PersianDate(_year, 1, 1);
            int offset = firstDay.PersianWeekDayNumber - 1;
            return ((DayOfYear + offset - 1) / 7) + 1;
        }
    }

    /// <summary>تاریخ را با قالب داده‌شده و ارقام فارسی برمی‌گرداند.</summary>
    public string ToLocalizedString(string? format = null)
        => PersianText.ToPersianDigits(ToString(format));

    /// <summary>۱۴۰۴/۰۷/۲۰</summary>
    public string ToShortString() => ToLocalizedString("yyyy/MM/dd");

    /// <summary>۲۰ مهر ۱۴۰۴</summary>
    public string ToLongString() => ToLocalizedString("dd MMMM yyyy");

    /// <summary>شنبه ۲۰ مهر ۱۴۰۴</summary>
    public string ToFullString() => ToLocalizedString("dddd dd MMMM yyyy");

    /// <summary>مهر ۱۴۰۴</summary>
    public string ToYearMonthString() => ToLocalizedString("MMMM yyyy");

    /// <inheritdoc />
    public string ToString(string? format, IFormatProvider? formatProvider)
    {
        if (string.IsNullOrWhiteSpace(format))
            format = DefaultFormat;

        return format switch
        {
            "s" => Format(DefaultFormat),
            "l" => PersianText.ToPersianDigits(Format("dd MMMM yyyy")),
            "f" => PersianText.ToPersianDigits(Format("dddd dd MMMM yyyy")),
            _ => Format(format!)
        };
    }

    /// <summary>قالب‌بندی با الگوی دلخواه و ارقام لاتین (قابل تجزیه و مرتب‌سازی).</summary>
    public string ToString(string? format) => ToString(format, CultureInfo.InvariantCulture);

    /// <inheritdoc />
    public override string ToString() => Format(DefaultFormat);

    private string Format(string pattern)
    {
        var builder = new StringBuilder(pattern.Length + 16);
        int index = 0;

        while (index < pattern.Length)
        {
            char token = pattern[index];

            if (token is not ('y' or 'M' or 'd'))
            {
                builder.Append(token);
                index++;
                continue;
            }

            int runLength = 1;
            while (index + runLength < pattern.Length && pattern[index + runLength] == token)
                runLength++;

            switch (token)
            {
                case 'y':
                    if (runLength <= 2)
                        builder.Append((_year % 100).ToString(runLength <= 1 ? "0" : "00", CultureInfo.InvariantCulture));
                    else
                        builder.Append(_year.ToString("0000", CultureInfo.InvariantCulture));
                    break;

                case 'M':
                    if (runLength >= 4)
                        builder.Append(MonthName);
                    else if (runLength == 3)
                        builder.Append(PersianDateNames.ShortMonthName(_month));
                    else
                        builder.Append(_month.ToString(runLength == 1 ? "0" : "00", CultureInfo.InvariantCulture));
                    break;

                default: // 'd'
                    if (runLength >= 4)
                        builder.Append(DayOfWeekName);
                    else
                        builder.Append(_day.ToString(runLength == 1 ? "0" : "00", CultureInfo.InvariantCulture));
                    break;
            }

            index += runLength;
        }

        return builder.ToString();
    }

    /// <summary>تجزیه تاریخ شمسی؛ ارقام فارسی و جداکننده‌های / - . پذیرفته می‌شوند.</summary>
    public static PersianDate Parse(string s, IFormatProvider? provider)
    {
        if (TryParse(s, provider, out var value))
            return value;

        throw new FormatException($"متن «{s}» یک تاریخ شمسی معتبر نیست. نمونه درست: ۱۴۰۴/۰۷/۲۰");
    }

    /// <summary>تجزیه تاریخ شمسی.</summary>
    public static PersianDate Parse(string s) => Parse(s, CultureInfo.InvariantCulture);

    /// <summary>تلاش برای تجزیه تاریخ شمسی.</summary>
    public static bool TryParse(string? s, IFormatProvider? provider, out PersianDate result)
        => TryParse(s, out result);

    /// <summary>تلاش برای تجزیه تاریخ شمسی (ارقام فارسی/عربی نیز پذیرفته می‌شود).</summary>
    public static bool TryParse(string? s, out PersianDate result)
    {
        result = default;

        if (string.IsNullOrWhiteSpace(s))
            return false;

        string normalized = PersianText.ToEnglishDigits(s).Trim();

        // بخش زمان (در صورت وجود) نادیده گرفته می‌شود
        int separatorIndex = normalized.IndexOfAny(new[] { ' ', 'T', 't' });
        if (separatorIndex > 0)
            normalized = normalized[..separatorIndex];

        var parts = normalized.Split(new[] { '/', '-', '.' }, StringSplitOptions.RemoveEmptyEntries);
        if (parts.Length != 3)
            return false;

        if (!int.TryParse(parts[0], NumberStyles.None, CultureInfo.InvariantCulture, out int year) ||
            !int.TryParse(parts[1], NumberStyles.None, CultureInfo.InvariantCulture, out int month) ||
            !int.TryParse(parts[2], NumberStyles.None, CultureInfo.InvariantCulture, out int day))
        {
            return false;
        }

        return TryCreate(year, month, day, out result);
    }

    /// <summary>مقایسه دو تاریخ شمسی.</summary>
    public int CompareTo(PersianDate other)
    {
        int result = _year.CompareTo(other._year);
        if (result != 0)
            return result;

        result = _month.CompareTo(other._month);
        return result != 0 ? result : _day.CompareTo(other._day);
    }

    /// <inheritdoc />
    public bool Equals(PersianDate other) => _year == other._year && _month == other._month && _day == other._day;

    /// <inheritdoc />
    public override bool Equals(object? obj) => obj is PersianDate other && Equals(other);

    /// <inheritdoc />
    public override int GetHashCode() => HashCode.Combine(_year, _month, _day);

    public static bool operator ==(PersianDate left, PersianDate right) => left.Equals(right);

    public static bool operator !=(PersianDate left, PersianDate right) => !left.Equals(right);

    public static bool operator <(PersianDate left, PersianDate right) => left.CompareTo(right) < 0;

    public static bool operator >(PersianDate left, PersianDate right) => left.CompareTo(right) > 0;

    public static bool operator <=(PersianDate left, PersianDate right) => left.CompareTo(right) <= 0;

    public static bool operator >=(PersianDate left, PersianDate right) => left.CompareTo(right) >= 0;

    /// <summary>تبدیل قطعی به تاریخ میلادی.</summary>
    public static explicit operator DateTime(PersianDate value) => value.ToDateTime();

    /// <summary>تبدیل قطعی از تاریخ میلادی.</summary>
    public static explicit operator PersianDate(DateTime value) => FromDateTime(value);
}
