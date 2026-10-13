using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Application.Common;
using Ariant.OfficeAutomation.Common;
using Ariant.OfficeAutomation.Domain.Common;
using Ariant.OfficeAutomation.Domain.Security;
using Microsoft.EntityFrameworkCore;

namespace Ariant.OfficeAutomation.Application.Security;

/// <summary>پارامترهای جست‌وجوی گزارش ممیزی.</summary>
public sealed class AuditLogQuery : PagedQuery
{
    /// <summary>نام موجودیت.</summary>
    public string? EntityName { get; set; }

    /// <summary>نام کاربر.</summary>
    public string? UserName { get; set; }

    /// <summary>نوع رویداد.</summary>
    public AuditAction? Action { get; set; }

    /// <summary>از تاریخ (شمسی، متن؛ نمونه ۱۴۰۴/۰۱/۰۱).</summary>
    public string? FromPersianDate { get; set; }

    /// <summary>تا تاریخ (شمسی، متن).</summary>
    public string? ToPersianDate { get; set; }
}

/// <summary>ردیف گزارش ممیزی برای نمایش.</summary>
public sealed class AuditLogItemDto
{
    /// <summary>شناسه.</summary>
    public long Id { get; init; }

    /// <summary>زمان رویداد (میلادی).</summary>
    public DateTime TimestampUtc { get; init; }

    /// <summary>کاربر عامل.</summary>
    public string? UserName { get; init; }

    /// <summary>نوع رویداد.</summary>
    public AuditAction Action { get; init; }

    /// <summary>نام موجودیت.</summary>
    public string EntityName { get; init; } = string.Empty;

    /// <summary>شناسه رکورد.</summary>
    public string? EntityId { get; init; }

    /// <summary>شرح.</summary>
    public string? Description { get; init; }

    /// <summary>نشانی شبکه.</summary>
    public string? IpAddress { get; init; }

    /// <summary>زمان رویداد به شمسی.</summary>
    public string TimestampDisplay => TimestampUtc == default ? "—" : TimestampUtc.ToPersianDateTimeString();

    /// <summary>نام فارسی نوع رویداد.</summary>
    public string ActionDisplay => Action.ToDisplayName();
}

/// <summary>سرویس خواندن گزارش ممیزی.</summary>
public interface IAuditLogService
{
    /// <summary>فهرست صفحه‌بندی‌شده گزارش ممیزی.</summary>
    Task<PagedResult<AuditLogItemDto>> GetPagedAsync(AuditLogQuery query, CancellationToken cancellationToken = default);

    /// <summary>فهرست نام موجودیت‌های ثبت‌شده (برای فیلتر).</summary>
    Task<IReadOnlyList<string>> GetEntityNamesAsync(CancellationToken cancellationToken = default);
}

/// <inheritdoc />
public sealed class AuditLogService : IAuditLogService
{
    private readonly IAppDbContext _context;

    /// <summary>ساخت سرویس.</summary>
    public AuditLogService(IAppDbContext context) => _context = context;

    /// <inheritdoc />
    public async Task<PagedResult<AuditLogItemDto>> GetPagedAsync(AuditLogQuery query, CancellationToken cancellationToken = default)
    {
        IQueryable<AuditLog> logs = _context.AuditLogs.AsNoTracking();

        if (!string.IsNullOrWhiteSpace(query.EntityName))
            logs = logs.Where(a => a.EntityName == query.EntityName);

        if (!string.IsNullOrWhiteSpace(query.UserName))
            logs = logs.Where(a => a.UserName != null && EF.Functions.Like(a.UserName, $"%{query.UserName.Trim()}%"));

        if (query.Action.HasValue)
            logs = logs.Where(a => a.Action == query.Action.Value);

        // تاریخ‌ها در پایگاه‌داده میلادی ذخیره می‌شوند؛ ورودی شمسی به میلادی تبدیل می‌شود
        if (PersianDate.TryParse(query.FromPersianDate, out PersianDate fromDate))
        {
            DateTime fromUtc = fromDate.ToDateTime();
            logs = logs.Where(a => a.TimestampUtc >= fromUtc);
        }

        if (PersianDate.TryParse(query.ToPersianDate, out PersianDate toDate))
        {
            DateTime toUtc = toDate.AddDays(1).ToDateTime();
            logs = logs.Where(a => a.TimestampUtc < toUtc);
        }

        int totalCount = await logs.CountAsync(cancellationToken);

        List<AuditLogItemDto> items = await logs
            .OrderByDescending(a => a.Id)
            .Skip(query.Skip)
            .Take(query.PageSize)
            .Select(a => new AuditLogItemDto
            {
                Id = a.Id,
                TimestampUtc = a.TimestampUtc,
                UserName = a.UserName,
                Action = a.Action,
                EntityName = a.EntityName,
                EntityId = a.EntityId,
                Description = a.Description,
                IpAddress = a.IpAddress
            })
            .ToListAsync(cancellationToken);

        return new PagedResult<AuditLogItemDto>(items, totalCount, query.Page, query.PageSize);
    }

    /// <inheritdoc />
    public async Task<IReadOnlyList<string>> GetEntityNamesAsync(CancellationToken cancellationToken = default)
        => await _context.AuditLogs
            .AsNoTracking()
            .Select(a => a.EntityName)
            .Distinct()
            .OrderBy(name => name)
            .ToListAsync(cancellationToken);
}
