using Ariant.OfficeAutomation.Application.Abstractions;

namespace Ariant.OfficeAutomation.Infrastructure.Time;

/// <summary>فراهم‌کننده زمان بر پایه ساعت سیستم.</summary>
public sealed class SystemDateTimeProvider : IDateTimeProvider
{
    /// <inheritdoc />
    public DateTime UtcNow => DateTime.UtcNow;

    /// <inheritdoc />
    public DateTime Now => DateTime.Now;
}
