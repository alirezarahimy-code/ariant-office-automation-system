using System.Globalization;
using System.Text;

namespace Ariant.OfficeAutomation.Common;

/// <summary>
/// ابزارهای کار با متن فارسی: ارقام، نویسه‌های عربی، نیم‌فاصله و آماده‌سازی برای جست‌وجو.
/// </summary>
public static class PersianText
{
    private const string PersianDigits = "۰۱۲۳۴۵۶۷۸۹";
    private const string ArabicIndicDigits = "٠١٢٣٤٥٦٧٨٩";
    private const char ZeroWidthNonJoiner = '\u200C'; // نیم‌فاصله
    private const char LeftToRightMark = '\u200E';
    private const char RightToLeftMark = '\u200F';
    private const char PersianThousandsSeparator = '\u066C'; // ٬

    /// <summary>تبدیل ارقام لاتین به ارقام فارسی.</summary>
    public static string ToPersianDigits(string? value)
    {
        if (string.IsNullOrEmpty(value))
            return value ?? string.Empty;

        var builder = new StringBuilder(value.Length);
        foreach (char character in value)
        {
            builder.Append(character is >= '0' and <= '9' ? PersianDigits[character - '0'] : character);
        }

        return builder.ToString();
    }

    /// <summary>تبدیل ارقام فارسی و عربی به ارقام لاتین (برای ذخیره‌سازی و محاسبه).</summary>
    public static string ToEnglishDigits(string? value)
    {
        if (string.IsNullOrEmpty(value))
            return value ?? string.Empty;

        var builder = new StringBuilder(value.Length);
        foreach (char character in value)
        {
            int index = PersianDigits.IndexOf(character);
            if (index < 0)
                index = ArabicIndicDigits.IndexOf(character);

            builder.Append(index >= 0 ? (char)('0' + index) : character);
        }

        return builder.ToString();
    }

    /// <summary>یکسان‌سازی نویسه‌های عربی با فارسی: ي→ی ، ك→ک و حذف اعراب.</summary>
    public static string NormalizeArabicCharacters(string? value)
    {
        if (string.IsNullOrEmpty(value))
            return value ?? string.Empty;

        var builder = new StringBuilder(value.Length);
        foreach (char character in value)
        {
            switch (character)
            {
                case 'ي': // ي عربی
                case 'ى': // ى
                    builder.Append('ی');
                    break;
                case 'ك':
                    builder.Append('ک');
                    break;
                case 'ة':
                    builder.Append('ه');
                    break;
                case 'ؤ':
                    builder.Append('و');
                    break;
                case 'إ':
                case 'أ':
                case 'ٱ':
                    builder.Append('ا');
                    break;
                default:
                    // حذف اعراب و علامت‌های ترکیبی
                    if (character is >= '\u064B' and <= '\u065F' or '\u0670')
                        break;

                    builder.Append(character);
                    break;
            }
        }

        return builder.ToString();
    }

    /// <summary>
    /// آماده‌سازی متن برای جست‌وجو: یکسان‌سازی نویسه‌ها، حذف نیم‌فاصله و نشانه‌های
    /// جهت‌نما، تبدیل ارقام به لاتین، حذف فاصله‌های تکراری و کوچک‌سازی حروف.
    /// </summary>
    public static string NormalizeForSearch(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
            return string.Empty;

        string normalized = NormalizeArabicCharacters(ToEnglishDigits(value));

        var builder = new StringBuilder(normalized.Length);
        bool previousWasSpace = false;

        foreach (char character in normalized)
        {
            if (character is ZeroWidthNonJoiner or LeftToRightMark or RightToLeftMark or '\u200B')
                continue;

            if (char.IsWhiteSpace(character))
            {
                if (!previousWasSpace && builder.Length > 0)
                    builder.Append(' ');

                previousWasSpace = true;
                continue;
            }

            builder.Append(char.ToLowerInvariant(character));
            previousWasSpace = false;
        }

        return builder.ToString().Trim();
    }

    /// <summary>نمایش عدد با جداکننده هزارگان فارسی (مثلاً ۱٬۲۵۰٬۰۰۰).</summary>
    public static string ToPersianNumber(long value)
    {
        string text = value.ToString("N0", CultureInfo.InvariantCulture);
        string withSeparator = text.Replace(',', PersianThousandsSeparator);
        return ToPersianDigits(withSeparator);
    }

    /// <summary>نمایش عدد اعشاری با جداکننده هزارگان فارسی.</summary>
    public static string ToPersianNumber(decimal value, int decimals = 0)
    {
        string text = value.ToString("N" + decimals.ToString(CultureInfo.InvariantCulture), CultureInfo.InvariantCulture);
        string withSeparator = text.Replace(',', PersianThousandsSeparator).Replace('.', '٫');
        return ToPersianDigits(withSeparator);
    }

    /// <summary>نمایش مبلغ ریالی/تومانی با ارقام فارسی.</summary>
    public static string ToPersianCurrency(decimal amount, string unit = "ریال") => $"{ToPersianNumber(decimal.Round(amount), 0)} {unit}";

    /// <summary>آیا متن شامل نویسه فارسی یا عربی است؟</summary>
    public static bool ContainsPersian(string? value)
    {
        if (string.IsNullOrEmpty(value))
            return false;

        foreach (char character in value)
        {
            if (character is >= '\u0600' and <= '\u06FF' or >= '\uFB50' and <= '\uFDFF' or >= '\uFE70' and <= '\uFEFF')
                return true;
        }

        return false;
    }

    /// <summary>کوتاه‌سازی متن با سه‌نقطه.</summary>
    public static string Truncate(string? value, int maxLength)
    {
        if (string.IsNullOrEmpty(value) || value!.Length <= maxLength)
            return value ?? string.Empty;

        return value[..maxLength].TrimEnd() + "…";
    }

    /// <summary>ساخت نام کاربری/شناسه از متن فارسی (جایگزین کردن فاصله‌ها).</summary>
    public static string ToSlug(string? value, char replaceWith = '-')
    {
        if (string.IsNullOrWhiteSpace(value))
            return string.Empty;

        var builder = new StringBuilder(value.Length);
        foreach (char character in NormalizeForSearch(value))
        {
            builder.Append(character switch
            {
                ' ' or '_' => replaceWith,
                '/' or '\\' => replaceWith,
                _ => character
            });
        }

        return builder.ToString().Trim(replaceWith);
    }
}
