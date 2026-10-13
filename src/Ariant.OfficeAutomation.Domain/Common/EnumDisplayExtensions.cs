using System.ComponentModel.DataAnnotations;
using System.Reflection;

namespace Ariant.OfficeAutomation.Domain.Common;

/// <summary>
/// خواندن نام فارسی مقادیر شمارشی (Enum) از ویژگی <see cref="DisplayAttribute"/>.
/// </summary>
public static class EnumDisplayExtensions
{
    /// <summary>نام فارسی مقدار شمارشی را برمی‌گرداند؛ در نبود ویژگی، نام خود مقدار.</summary>
    public static string ToDisplayName(this Enum value)
    {
        ArgumentNullException.ThrowIfNull(value);

        FieldInfo? field = value.GetType().GetField(value.ToString());
        DisplayAttribute? attribute = field?.GetCustomAttribute<DisplayAttribute>(inherit: false);

        return attribute?.Name ?? value.ToString();
    }

    /// <summary>فهرست همه مقادیر یک شمارشی همراه نام نمایشی.</summary>
    public static IReadOnlyList<(TEnum Value, string Name)> GetDisplayList<TEnum>() where TEnum : struct, Enum
        => Enum.GetValues<TEnum>()
            .Select(value => (value, value.ToDisplayName()))
            .ToList();
}
