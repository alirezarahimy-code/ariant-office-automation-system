using System.Text.Json;
using Ariant.OfficeAutomation.Common;
using Xunit;

namespace Ariant.OfficeAutomation.Tests;

/// <summary>
/// آزمون‌های تقویم شمسی.
/// بردارهای آزمون از کتابخانه مرجع (jdatetime، منطبق بر تقویم رسمی ایران) تولید شده‌اند؛
/// بنابراین اگر این آزمون‌ها سبز شوند، تبدیل تاریخ درست است.
/// </summary>
public class PersianDateTests
{
    /// <summary>بردارهای مرجع تبدیل تاریخ (سال، ماه، روز شمسی ↔ تاریخ میلادی).</summary>
    public static IEnumerable<object[]> CalendarVectors() => new List<object[]>
    {
            new object[] { 1304, 1, 1, new DateTime(1925, 3, 21) },
            new object[] { 1304, 2, 1, new DateTime(1925, 4, 21) },
            new object[] { 1304, 3, 1, new DateTime(1925, 5, 22) },
            new object[] { 1304, 4, 1, new DateTime(1925, 6, 22) },
            new object[] { 1304, 5, 1, new DateTime(1925, 7, 23) },
            new object[] { 1304, 6, 1, new DateTime(1925, 8, 23) },
            new object[] { 1304, 7, 1, new DateTime(1925, 9, 23) },
            new object[] { 1304, 8, 1, new DateTime(1925, 10, 23) },
            new object[] { 1304, 9, 1, new DateTime(1925, 11, 22) },
            new object[] { 1304, 10, 1, new DateTime(1925, 12, 22) },
            new object[] { 1304, 11, 1, new DateTime(1926, 1, 21) },
            new object[] { 1304, 12, 1, new DateTime(1926, 2, 20) },
            new object[] { 1350, 1, 1, new DateTime(1971, 3, 21) },
            new object[] { 1350, 2, 1, new DateTime(1971, 4, 21) },
            new object[] { 1350, 3, 1, new DateTime(1971, 5, 22) },
            new object[] { 1350, 4, 1, new DateTime(1971, 6, 22) },
            new object[] { 1350, 5, 1, new DateTime(1971, 7, 23) },
            new object[] { 1350, 6, 1, new DateTime(1971, 8, 23) },
            new object[] { 1350, 7, 1, new DateTime(1971, 9, 23) },
            new object[] { 1350, 8, 1, new DateTime(1971, 10, 23) },
            new object[] { 1350, 9, 1, new DateTime(1971, 11, 22) },
            new object[] { 1350, 10, 1, new DateTime(1971, 12, 22) },
            new object[] { 1350, 11, 1, new DateTime(1972, 1, 21) },
            new object[] { 1350, 12, 1, new DateTime(1972, 2, 20) },
            new object[] { 1370, 1, 1, new DateTime(1991, 3, 21) },
            new object[] { 1370, 2, 1, new DateTime(1991, 4, 21) },
            new object[] { 1370, 3, 1, new DateTime(1991, 5, 22) },
            new object[] { 1370, 4, 1, new DateTime(1991, 6, 22) },
            new object[] { 1370, 5, 1, new DateTime(1991, 7, 23) },
            new object[] { 1370, 6, 1, new DateTime(1991, 8, 23) },
            new object[] { 1370, 7, 1, new DateTime(1991, 9, 23) },
            new object[] { 1370, 8, 1, new DateTime(1991, 10, 23) },
            new object[] { 1370, 9, 1, new DateTime(1991, 11, 22) },
            new object[] { 1370, 10, 1, new DateTime(1991, 12, 22) },
            new object[] { 1370, 11, 1, new DateTime(1992, 1, 21) },
            new object[] { 1370, 12, 1, new DateTime(1992, 2, 20) },
            new object[] { 1375, 1, 1, new DateTime(1996, 3, 20) },
            new object[] { 1375, 2, 1, new DateTime(1996, 4, 20) },
            new object[] { 1375, 3, 1, new DateTime(1996, 5, 21) },
            new object[] { 1375, 4, 1, new DateTime(1996, 6, 21) },
            new object[] { 1375, 5, 1, new DateTime(1996, 7, 22) },
            new object[] { 1375, 6, 1, new DateTime(1996, 8, 22) },
            new object[] { 1375, 7, 1, new DateTime(1996, 9, 22) },
            new object[] { 1375, 8, 1, new DateTime(1996, 10, 22) },
            new object[] { 1375, 9, 1, new DateTime(1996, 11, 21) },
            new object[] { 1375, 10, 1, new DateTime(1996, 12, 21) },
            new object[] { 1375, 11, 1, new DateTime(1997, 1, 20) },
            new object[] { 1375, 12, 1, new DateTime(1997, 2, 19) },
            new object[] { 1390, 1, 1, new DateTime(2011, 3, 21) },
            new object[] { 1390, 2, 1, new DateTime(2011, 4, 21) },
            new object[] { 1390, 3, 1, new DateTime(2011, 5, 22) },
            new object[] { 1390, 4, 1, new DateTime(2011, 6, 22) },
            new object[] { 1390, 5, 1, new DateTime(2011, 7, 23) },
            new object[] { 1390, 6, 1, new DateTime(2011, 8, 23) },
            new object[] { 1390, 7, 1, new DateTime(2011, 9, 23) },
            new object[] { 1390, 8, 1, new DateTime(2011, 10, 23) },
            new object[] { 1390, 9, 1, new DateTime(2011, 11, 22) },
            new object[] { 1390, 10, 1, new DateTime(2011, 12, 22) },
            new object[] { 1390, 11, 1, new DateTime(2012, 1, 21) },
            new object[] { 1390, 12, 1, new DateTime(2012, 2, 20) },
            new object[] { 1399, 1, 1, new DateTime(2020, 3, 20) },
            new object[] { 1399, 2, 1, new DateTime(2020, 4, 20) },
            new object[] { 1399, 3, 1, new DateTime(2020, 5, 21) },
            new object[] { 1399, 4, 1, new DateTime(2020, 6, 21) },
            new object[] { 1399, 5, 1, new DateTime(2020, 7, 22) },
            new object[] { 1399, 6, 1, new DateTime(2020, 8, 22) },
            new object[] { 1399, 7, 1, new DateTime(2020, 9, 22) },
            new object[] { 1399, 8, 1, new DateTime(2020, 10, 22) },
            new object[] { 1399, 9, 1, new DateTime(2020, 11, 21) },
            new object[] { 1399, 10, 1, new DateTime(2020, 12, 21) },
            new object[] { 1399, 11, 1, new DateTime(2021, 1, 20) },
            new object[] { 1399, 12, 1, new DateTime(2021, 2, 19) },
            new object[] { 1400, 1, 1, new DateTime(2021, 3, 21) },
            new object[] { 1400, 2, 1, new DateTime(2021, 4, 21) },
            new object[] { 1400, 3, 1, new DateTime(2021, 5, 22) },
            new object[] { 1400, 4, 1, new DateTime(2021, 6, 22) },
            new object[] { 1400, 5, 1, new DateTime(2021, 7, 23) },
            new object[] { 1400, 6, 1, new DateTime(2021, 8, 23) },
            new object[] { 1400, 7, 1, new DateTime(2021, 9, 23) },
            new object[] { 1400, 8, 1, new DateTime(2021, 10, 23) },
            new object[] { 1400, 9, 1, new DateTime(2021, 11, 22) },
            new object[] { 1400, 10, 1, new DateTime(2021, 12, 22) },
            new object[] { 1400, 11, 1, new DateTime(2022, 1, 21) },
            new object[] { 1400, 12, 1, new DateTime(2022, 2, 20) },
            new object[] { 1401, 1, 1, new DateTime(2022, 3, 21) },
            new object[] { 1401, 2, 1, new DateTime(2022, 4, 21) },
            new object[] { 1401, 3, 1, new DateTime(2022, 5, 22) },
            new object[] { 1401, 4, 1, new DateTime(2022, 6, 22) },
            new object[] { 1401, 5, 1, new DateTime(2022, 7, 23) },
            new object[] { 1401, 6, 1, new DateTime(2022, 8, 23) },
            new object[] { 1401, 7, 1, new DateTime(2022, 9, 23) },
            new object[] { 1401, 8, 1, new DateTime(2022, 10, 23) },
            new object[] { 1401, 9, 1, new DateTime(2022, 11, 22) },
            new object[] { 1401, 10, 1, new DateTime(2022, 12, 22) },
            new object[] { 1401, 11, 1, new DateTime(2023, 1, 21) },
            new object[] { 1401, 12, 1, new DateTime(2023, 2, 20) },
            new object[] { 1402, 1, 1, new DateTime(2023, 3, 21) },
            new object[] { 1402, 2, 1, new DateTime(2023, 4, 21) },
            new object[] { 1402, 3, 1, new DateTime(2023, 5, 22) },
            new object[] { 1402, 4, 1, new DateTime(2023, 6, 22) },
            new object[] { 1402, 5, 1, new DateTime(2023, 7, 23) },
            new object[] { 1402, 6, 1, new DateTime(2023, 8, 23) },
            new object[] { 1402, 7, 1, new DateTime(2023, 9, 23) },
            new object[] { 1402, 8, 1, new DateTime(2023, 10, 23) },
            new object[] { 1402, 9, 1, new DateTime(2023, 11, 22) },
            new object[] { 1402, 10, 1, new DateTime(2023, 12, 22) },
            new object[] { 1402, 11, 1, new DateTime(2024, 1, 21) },
            new object[] { 1402, 12, 1, new DateTime(2024, 2, 20) },
            new object[] { 1403, 1, 1, new DateTime(2024, 3, 20) },
            new object[] { 1403, 2, 1, new DateTime(2024, 4, 20) },
            new object[] { 1403, 3, 1, new DateTime(2024, 5, 21) },
            new object[] { 1403, 4, 1, new DateTime(2024, 6, 21) },
            new object[] { 1403, 5, 1, new DateTime(2024, 7, 22) },
            new object[] { 1403, 6, 1, new DateTime(2024, 8, 22) },
            new object[] { 1403, 7, 1, new DateTime(2024, 9, 22) },
            new object[] { 1403, 8, 1, new DateTime(2024, 10, 22) },
            new object[] { 1403, 9, 1, new DateTime(2024, 11, 21) },
            new object[] { 1403, 10, 1, new DateTime(2024, 12, 21) },
            new object[] { 1403, 11, 1, new DateTime(2025, 1, 20) },
            new object[] { 1403, 12, 1, new DateTime(2025, 2, 19) },
            new object[] { 1404, 1, 1, new DateTime(2025, 3, 21) },
            new object[] { 1404, 2, 1, new DateTime(2025, 4, 21) },
            new object[] { 1404, 3, 1, new DateTime(2025, 5, 22) },
            new object[] { 1404, 4, 1, new DateTime(2025, 6, 22) },
            new object[] { 1404, 5, 1, new DateTime(2025, 7, 23) },
            new object[] { 1404, 6, 1, new DateTime(2025, 8, 23) },
            new object[] { 1404, 7, 1, new DateTime(2025, 9, 23) },
            new object[] { 1404, 8, 1, new DateTime(2025, 10, 23) },
            new object[] { 1404, 9, 1, new DateTime(2025, 11, 22) },
            new object[] { 1404, 10, 1, new DateTime(2025, 12, 22) },
            new object[] { 1404, 11, 1, new DateTime(2026, 1, 21) },
            new object[] { 1404, 12, 1, new DateTime(2026, 2, 20) },
            new object[] { 1405, 1, 1, new DateTime(2026, 3, 21) },
            new object[] { 1405, 2, 1, new DateTime(2026, 4, 21) },
            new object[] { 1405, 3, 1, new DateTime(2026, 5, 22) },
            new object[] { 1405, 4, 1, new DateTime(2026, 6, 22) },
            new object[] { 1405, 5, 1, new DateTime(2026, 7, 23) },
            new object[] { 1405, 6, 1, new DateTime(2026, 8, 23) },
            new object[] { 1405, 7, 1, new DateTime(2026, 9, 23) },
            new object[] { 1405, 8, 1, new DateTime(2026, 10, 23) },
            new object[] { 1405, 9, 1, new DateTime(2026, 11, 22) },
            new object[] { 1405, 10, 1, new DateTime(2026, 12, 22) },
            new object[] { 1405, 11, 1, new DateTime(2027, 1, 21) },
            new object[] { 1405, 12, 1, new DateTime(2027, 2, 20) },
            new object[] { 1410, 1, 1, new DateTime(2031, 3, 21) },
            new object[] { 1410, 2, 1, new DateTime(2031, 4, 21) },
            new object[] { 1410, 3, 1, new DateTime(2031, 5, 22) },
            new object[] { 1410, 4, 1, new DateTime(2031, 6, 22) },
            new object[] { 1410, 5, 1, new DateTime(2031, 7, 23) },
            new object[] { 1410, 6, 1, new DateTime(2031, 8, 23) },
            new object[] { 1410, 7, 1, new DateTime(2031, 9, 23) },
            new object[] { 1410, 8, 1, new DateTime(2031, 10, 23) },
            new object[] { 1410, 9, 1, new DateTime(2031, 11, 22) },
            new object[] { 1410, 10, 1, new DateTime(2031, 12, 22) },
            new object[] { 1410, 11, 1, new DateTime(2032, 1, 21) },
            new object[] { 1410, 12, 1, new DateTime(2032, 2, 20) },
            new object[] { 1420, 1, 1, new DateTime(2041, 3, 20) },
            new object[] { 1420, 2, 1, new DateTime(2041, 4, 20) },
            new object[] { 1420, 3, 1, new DateTime(2041, 5, 21) },
            new object[] { 1420, 4, 1, new DateTime(2041, 6, 21) },
            new object[] { 1420, 5, 1, new DateTime(2041, 7, 22) },
            new object[] { 1420, 6, 1, new DateTime(2041, 8, 22) },
            new object[] { 1420, 7, 1, new DateTime(2041, 9, 22) },
            new object[] { 1420, 8, 1, new DateTime(2041, 10, 22) },
            new object[] { 1420, 9, 1, new DateTime(2041, 11, 21) },
            new object[] { 1420, 10, 1, new DateTime(2041, 12, 21) },
            new object[] { 1420, 11, 1, new DateTime(2042, 1, 20) },
            new object[] { 1420, 12, 1, new DateTime(2042, 2, 19) },
            new object[] { 1450, 1, 1, new DateTime(2071, 3, 21) },
            new object[] { 1450, 2, 1, new DateTime(2071, 4, 21) },
            new object[] { 1450, 3, 1, new DateTime(2071, 5, 22) },
            new object[] { 1450, 4, 1, new DateTime(2071, 6, 22) },
            new object[] { 1450, 5, 1, new DateTime(2071, 7, 23) },
            new object[] { 1450, 6, 1, new DateTime(2071, 8, 23) },
            new object[] { 1450, 7, 1, new DateTime(2071, 9, 23) },
            new object[] { 1450, 8, 1, new DateTime(2071, 10, 23) },
            new object[] { 1450, 9, 1, new DateTime(2071, 11, 22) },
            new object[] { 1450, 10, 1, new DateTime(2071, 12, 22) },
            new object[] { 1450, 11, 1, new DateTime(2072, 1, 21) },
            new object[] { 1450, 12, 1, new DateTime(2072, 2, 20) },
            new object[] { 1467, 1, 1, new DateTime(2088, 3, 20) },
            new object[] { 1467, 2, 1, new DateTime(2088, 4, 20) },
            new object[] { 1467, 3, 1, new DateTime(2088, 5, 21) },
            new object[] { 1467, 4, 1, new DateTime(2088, 6, 21) },
            new object[] { 1467, 5, 1, new DateTime(2088, 7, 22) },
            new object[] { 1467, 6, 1, new DateTime(2088, 8, 22) },
            new object[] { 1467, 7, 1, new DateTime(2088, 9, 22) },
            new object[] { 1467, 8, 1, new DateTime(2088, 10, 22) },
            new object[] { 1467, 9, 1, new DateTime(2088, 11, 21) },
            new object[] { 1467, 10, 1, new DateTime(2088, 12, 21) },
            new object[] { 1467, 11, 1, new DateTime(2089, 1, 20) },
            new object[] { 1467, 12, 1, new DateTime(2089, 2, 19) },
            new object[] { 1370, 12, 30, new DateTime(1992, 3, 20) },
            new object[] { 1375, 12, 30, new DateTime(1997, 3, 20) },
            new object[] { 1387, 12, 30, new DateTime(2009, 3, 20) },
            new object[] { 1391, 12, 30, new DateTime(2013, 3, 20) },
            new object[] { 1395, 12, 30, new DateTime(2017, 3, 20) },
            new object[] { 1399, 12, 30, new DateTime(2021, 3, 20) },
            new object[] { 1403, 12, 30, new DateTime(2025, 3, 20) },
            new object[] { 1408, 12, 30, new DateTime(2030, 3, 20) },
            new object[] { 1412, 12, 30, new DateTime(2034, 3, 20) },
            new object[] { 1416, 12, 30, new DateTime(2038, 3, 20) },
            new object[] { 1420, 12, 30, new DateTime(2042, 3, 20) },
            new object[] { 1406, 1, 1, new DateTime(2027, 3, 21) },
            new object[] { 1407, 1, 1, new DateTime(2028, 3, 20) },
            new object[] { 1408, 1, 1, new DateTime(2029, 3, 20) },
            new object[] { 1409, 1, 1, new DateTime(2030, 3, 21) },
// total vectors: 207
    };

    [Theory]
    [MemberData(nameof(CalendarVectors))]
    public void تبدیل_تاریخ_میلادی_به_شمسی_درست_است(int year, int month, int day, DateTime gregorian)
    {
        var expected = new PersianDate(year, month, day);
        Assert.Equal(expected, PersianDate.FromDateTime(gregorian));
    }

    [Theory]
    [MemberData(nameof(CalendarVectors))]
    public void تبدیل_تاریخ_شمسی_به_میلادی_درست_است(int year, int month, int day, DateTime gregorian)
    {
        var persian = new PersianDate(year, month, day);
        Assert.Equal(gregorian.Date, persian.ToDateTime().Date);
    }

    [Theory]
    [MemberData(nameof(CalendarVectors))]
    public void تبدیل_رفت_و_برگشت_پایدار_است(int year, int month, int day, DateTime gregorian)
    {
        var persian = new PersianDate(year, month, day);
        PersianDate roundTripped = PersianDate.FromDateTime(persian.ToDateTime());

        Assert.Equal(persian, roundTripped);
        Assert.Equal(gregorian.Date, roundTripped.ToDateTime().Date);
    }

    [Theory]
    [InlineData(1375, true)]
    [InlineData(1379, true)]
    [InlineData(1387, true)]
    [InlineData(1391, true)]
    [InlineData(1395, true)]
    [InlineData(1399, true)]
    [InlineData(1403, true)]
    [InlineData(1404, false)]
    [InlineData(1405, false)]
    [InlineData(1408, true)]
    [InlineData(1412, true)]
    public void سال_کبیسه_درست_تشخیص_داده_می‌شود(int year, bool expected)
    {
        Assert.Equal(expected, PersianCalendarMath.IsLeapYear(year));
        Assert.Equal(expected ? 30 : 29, PersianCalendarMath.DaysInMonth(year, 12));
    }

    [Theory]
    [InlineData(1404, 12, 30)]
    [InlineData(1405, 12, 30)]
    [InlineData(1402, 12, 30)]
    [InlineData(1403, 7, 31)]
    [InlineData(1404, 13, 1)]
    [InlineData(1404, 0, 1)]
    [InlineData(1404, 1, 32)]
    public void تاریخ‌های_نامعتبر_رد_می‌شوند(int year, int month, int day)
    {
        Assert.False(PersianCalendarMath.IsValidDate(year, month, day));
        Assert.False(PersianDate.TryCreate(year, month, day, out _));
    }

    [Theory]
    [InlineData(1403, 12, 30, 2025, 3, 20)]
    [InlineData(1399, 12, 30, 2021, 3, 20)]
    [InlineData(1375, 12, 30, 1997, 3, 20)]
    [InlineData(1404, 12, 29, 2026, 3, 20)]
    public void روزهای_کبیسه_اسفند_درست_تبدیل_می‌شوند(int year, int month, int day, int gYear, int gMonth, int gDay)
    {
        var persian = new PersianDate(year, month, day);
        Assert.Equal(new DateTime(gYear, gMonth, gDay), persian.ToDateTime());
    }

    [Fact]
    public void نوروز_۱۴۰۰_روز_شنبه_بود()
    {
        var nowruz1400 = new PersianDate(1400, 1, 1);
        Assert.Equal(DayOfWeek.Saturday, nowruz1400.DayOfWeek);
        Assert.Equal(1, nowruz1400.PersianWeekDayNumber);
        Assert.Equal("شنبه", nowruz1400.DayOfWeekName);
    }

    [Fact]
    public void نام_روز_و_ماه_فارسی_درست_است()
    {
        var date = new PersianDate(1405, 7, 20);
        Assert.Equal(DayOfWeek.Monday, date.DayOfWeek);
        Assert.Equal("دوشنبه", date.DayOfWeekName);
        Assert.Equal("مهر", date.MonthName);
        Assert.Equal("پاییز", date.SeasonName);
        Assert.Equal(3, date.PersianWeekDayNumber);
        Assert.Equal("۱۴۰۵/۰۷/۲۰", date.ToShortString());
        Assert.Equal("۲۰ مهر ۱۴۰۵", date.ToLongString());
        Assert.Equal("دوشنبه ۲۰ مهر ۱۴۰۵", date.ToFullString());
    }

    [Theory]
    [InlineData("1404/07/20")]
    [InlineData("۱۴۰۴/۰۷/۲۰")]
    [InlineData("1404-07-20")]
    [InlineData("1404.07.20")]
    [InlineData(" 1404/07/20 ")]
    [InlineData("1404/07/20 12:30")]
    public void تجزیه_تاریخ_شمسی_با_قالب‌های_مختلف_انجام_می‌شود(string text)
    {
        Assert.True(PersianDate.TryParse(text, out PersianDate date));
        Assert.Equal(new PersianDate(1404, 7, 20), date);
    }

    [Theory]
    [InlineData("")]
    [InlineData("   ")]
    [InlineData("1404/13/01")]
    [InlineData("1404/12/30")]
    [InlineData("abc")]
    [InlineData("1404/07")]
    public void تجزیه_متن_نامعتبر_ناموفق_است(string text)
        => Assert.False(PersianDate.TryParse(text, out _));

    [Fact]
    public void تبدیل_قالب_دلخواه_کار_می‌کند()
    {
        var date = new PersianDate(1404, 7, 5);
        Assert.Equal("1404/07/05", date.ToString("yyyy/MM/dd"));
        Assert.Equal("5 مهر 1404", date.ToString("d MMMM yyyy"));
        Assert.Equal("۱۴۰۴/۰۷/۰۵", date.ToLocalizedString());
        Assert.Equal("مهر ۱۴۰۴", date.ToYearMonthString());
    }

    [Fact]
    public void افزودن_روز_و_ماه_از_مرز_سال_عبور_می‌کند()
    {
        var esfand29 = new PersianDate(1403, 12, 29);
        Assert.Equal(new PersianDate(1403, 12, 30), esfand29.AddDays(1));
        Assert.Equal(new PersianDate(1404, 1, 1), esfand29.AddDays(2));

        var farvardin31 = new PersianDate(1404, 1, 31);
        Assert.Equal(new PersianDate(1404, 2, 31), farvardin31.AddMonths(1));
        Assert.Equal(new PersianDate(1404, 7, 30), new PersianDate(1404, 6, 31).AddMonths(1));

        // ۳۱ فروردین در سال بعد، فروردین ۳۱ روزه است
        Assert.Equal(new PersianDate(1405, 1, 31), farvardin31.AddYears(1));

        // ۲۹ اسفند سال کبیسه در سال غیرکبیسه به ۲۹ اسفند تبدیل می‌شود
        Assert.Equal(new PersianDate(1404, 12, 29), new PersianDate(1403, 12, 30).AddYears(1));
    }

    [Fact]
    public void مقایسه_و_فاصله_تاریخ‌ها_درست_است()
    {
        var first = new PersianDate(1404, 1, 1);
        var second = new PersianDate(1404, 1, 11);

        Assert.True(first < second);
        Assert.True(second > first);
        Assert.True(first != second);
        Assert.Equal(10, first.DaysBetween(second));
        Assert.Equal(366, new PersianDate(1403, 1, 1).DaysBetween(new PersianDate(1404, 1, 1)));
    }

    [Fact]
    public void تبدیل_به_و_از_جیسون_کار_می‌کند()
    {
        var options = new JsonSerializerOptions();
        options.Converters.Add(new PersianDateJsonConverter());

        var date = new PersianDate(1404, 7, 20);
        string json = JsonSerializer.Serialize(date, options);
        Assert.Equal("\"1404/07/20\"", json);

        var deserialized = JsonSerializer.Deserialize<PersianDate>("\"۱۴۰۴/۰۷/۲۰\"", options);
        Assert.Equal(date, deserialized);
    }

    [Fact]
    public void تاریخ_امروز_قابل_ساخت_است()
    {
        var today = PersianDate.Today;
        Assert.InRange(today.Year, 1400, 1500);
        Assert.Equal(today, PersianDate.FromDateTime(DateTime.Today));
        Assert.Equal(today.ToDateTime().Date, DateTime.Today.Date);
    }

    [Fact]
    public void شماره_روز_سال_درست_است()
    {
        Assert.Equal(1, new PersianDate(1404, 1, 1).DayOfYear);
        Assert.Equal(186, new PersianDate(1404, 6, 31).DayOfYear);
        Assert.Equal(187, new PersianDate(1404, 7, 1).DayOfYear);
        Assert.Equal(365, new PersianDate(1404, 12, 29).DayOfYear);
        Assert.Equal(366, new PersianDate(1403, 12, 30).DayOfYear);
    }
}

/// <summary>آزمون‌های پردازش متن فارسی.</summary>
public class PersianTextTests
{
    [Fact]
    public void تبدیل_ارقام_درست_انجام_می‌شود()
    {
        Assert.Equal("۱۴۰۴", PersianText.ToPersianDigits("1404"));
        Assert.Equal("1404", PersianText.ToEnglishDigits("۱۴۰۴"));
        Assert.Equal("1404", PersianText.ToEnglishDigits("١٤٠٤"));
        Assert.Equal("۱٬۲۵۰", PersianText.ToPersianNumber(1250));
        Assert.Equal("کد ۲۰۲", PersianText.ToPersianDigits("کد 202"));
    }

    [Fact]
    public void یکسان‌سازی_نویسه‌های_عربی_انجام_می‌شود()
    {
        Assert.Equal("یک", PersianText.NormalizeArabicCharacters("يك"));
        Assert.Equal("کتاب", PersianText.NormalizeArabicCharacters("كتاب"));
        Assert.Equal("سلام", PersianText.NormalizeArabicCharacters("سَلام"));
    }

    [Fact]
    public void آماده‌سازی_جست‌وجو_نیم‌فاصله_و_ارقام_را_یکسان_می‌کند()
    {
        Assert.Equal("مدرسه ها", PersianText.NormalizeForSearch("مدرسه‌ها  "));
        Assert.Equal("1404", PersianText.NormalizeForSearch("۱۴۰۴"));
        Assert.Equal("abc def", PersianText.NormalizeForSearch("  ABC   DEF "));
    }

    [Fact]
    public void کوتاه‌سازی_و_تشخیص_فارسی_کار_می‌کند()
    {
        Assert.Equal("سلام…", PersianText.Truncate("سلام دنیا", 4));
        Assert.True(PersianText.ContainsPersian("سامانه ۱۴۰۴"));
        Assert.False(PersianText.ContainsPersian("Ariant 2026"));
    }
}
