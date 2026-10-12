using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Common;
using Ariant.OfficeAutomation.Domain.Organization;
using Microsoft.EntityFrameworkCore;

namespace Ariant.OfficeAutomation.Application.Dashboard;

/// <summary>خلاصه آماری داشبورد.</summary>
public sealed class DashboardSummaryDto
{
    /// <summary>تعداد واحدهای سازمانی.</summary>
    public int DepartmentCount { get; init; }

    /// <summary>تعداد کارمندان.</summary>
    public int EmployeeCount { get; init; }

    /// <summary>تعداد کارمندان شاغل.</summary>
    public int ActiveEmployeeCount { get; init; }

    /// <summary>تعداد کاربران سامانه.</summary>
    public int UserCount { get; init; }

    /// <summary>تعداد رکوردهای گزارش ممیزی.</summary>
    public int AuditLogCount { get; init; }

    /// <summary>تاریخ امروز به شمسی.</summary>
    public PersianDate Today { get; init; } = PersianDate.Today;

    /// <summary>تاریخ امروز به شکل «شنبه ۲۰ مهر ۱۴۰۴».</summary>
    public string TodayDisplay => Today.ToFullString();

    /// <summary>آخرین رویدادهای سامانه.</summary>
    public IReadOnlyList<ActivityItemDto> RecentActivities { get; init; } = Array.Empty<ActivityItemDto>();
}

/// <summary>یک رویداد در فهرست فعالیت‌های اخیر.</summary>
public sealed class ActivityItemDto
{
    /// <summary>زمان رویداد (میلادی).</summary>
    public DateTime TimestampUtc { get; init; }

    /// <summary>کاربر عامل.</summary>
    public string? UserName { get; init; }

    /// <summary>شرح رویداد.</summary>
    public string Description { get; init; } = string.Empty;

    /// <summary>زمان به شکل «۲۰ مهر ۱۴۰۴ - ۱۴:۳۰».</summary>
    public string TimestampDisplay => TimestampUtc == default ? "—" : TimestampUtc.ToPersianDateTimeString();
}

/// <summary>سرویس داده‌های داشبورد.</summary>
public interface IDashboardService
{
    /// <summary>خلاصه آماری داشبورد.</summary>
    Task<DashboardSummaryDto> GetSummaryAsync(CancellationToken cancellationToken = default);
}

/// <inheritdoc />
public sealed class DashboardService : IDashboardService
{
    private readonly IAppDbContext _context;
    private readonly IUserDirectory _userDirectory;

    /// <summary>ساخت سرویس داشبورد.</summary>
    public DashboardService(IAppDbContext context, IUserDirectory userDirectory)
    {
        _context = context;
        _userDirectory = userDirectory;
    }

    /// <inheritdoc />
    public async Task<DashboardSummaryDto> GetSummaryAsync(CancellationToken cancellationToken = default)
    {
        int departmentCount = await _context.Departments.CountAsync(cancellationToken);
        int employeeCount = await _context.Employees.CountAsync(cancellationToken);
        int activeEmployeeCount = await _context.Employees
            .CountAsync(e => e.Status == EmployeeStatus.Active, cancellationToken);
        int auditLogCount = await _context.AuditLogs.CountAsync(cancellationToken);
        int userCount = await _userDirectory.CountAsync(cancellationToken);

        var activities = await _context.AuditLogs
            .AsNoTracking()
            .OrderByDescending(a => a.Id)
            .Take(8)
            .Select(a => new ActivityItemDto
            {
                TimestampUtc = a.TimestampUtc,
                UserName = a.UserName,
                Description = a.Description ?? a.EntityName
            })
            .ToListAsync(cancellationToken);

        return new DashboardSummaryDto
        {
            DepartmentCount = departmentCount,
            EmployeeCount = employeeCount,
            ActiveEmployeeCount = activeEmployeeCount,
            UserCount = userCount,
            AuditLogCount = auditLogCount,
            RecentActivities = activities
        };
    }
}
