using System.Text.Json;
using System.Text.Json.Serialization;

namespace Ariant.OfficeAutomation.Common;

/// <summary>
/// تبدیل‌گر JSON برای <see cref="PersianDate"/>.
/// در خروجی به شکل «۱۴۰۴/۰۷/۲۰» و در ورودی با ارقام فارسی یا لاتین پذیرفته می‌شود.
/// </summary>
public sealed class PersianDateJsonConverter : JsonConverter<PersianDate>
{
    /// <inheritdoc />
    public override PersianDate Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
    {
        if (reader.TokenType != JsonTokenType.String)
            throw new JsonException("مقدار تاریخ شمسی باید به شکل متن باشد؛ نمونه: \"1404/07/20\"");

        string? text = reader.GetString();
        if (!PersianDate.TryParse(text, out PersianDate value))
            throw new JsonException($"مقدار تاریخ شمسی نامعتبر است: '{text}'");

        return value;
    }

    /// <inheritdoc />
    public override void Write(Utf8JsonWriter writer, PersianDate value, JsonSerializerOptions options)
        => writer.WriteStringValue(value.ToString());
}

/// <summary>تبدیل‌گر JSON برای تاریخ شمسی پذیرنده مقدار خالی (nullable).</summary>
public sealed class NullablePersianDateJsonConverter : JsonConverter<PersianDate?>
{
    private readonly PersianDateJsonConverter _inner = new();

    /// <inheritdoc />
    public override PersianDate? Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
    {
        if (reader.TokenType == JsonTokenType.Null)
            return null;

        return _inner.Read(ref reader, typeof(PersianDate), options);
    }

    /// <inheritdoc />
    public override void Write(Utf8JsonWriter writer, PersianDate? value, JsonSerializerOptions options)
    {
        if (value is null)
        {
            writer.WriteNullValue();
            return;
        }

        _inner.Write(writer, value.Value, options);
    }
}
