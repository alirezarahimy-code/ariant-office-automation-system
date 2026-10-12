using Ariant.OfficeAutomation.Application.Common;
using Ariant.OfficeAutomation.Application.Security;
using Ariant.OfficeAutomation.Domain.Common;
using Ariant.OfficeAutomation.Domain.Security;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.Rendering;

namespace Ariant.OfficeAutomation.Web.Controllers;

/// <summary>گزارش ممیزی سامانه (فقط مدیر سیستم).</summary>
[Authorize(Policy = AppPolicies.ViewAuditLog)]
public class AuditLogsController : Controller
{
    private readonly IAuditLogService _auditLogService;

    /// <summary>ساخت کنترلر.</summary>
    public AuditLogsController(IAuditLogService auditLogService)
        => _auditLogService = auditLogService;

    /// <summary>فهرست رویدادهای ثبت‌شده.</summary>
    public async Task<IActionResult> Index(AuditLogQuery query, CancellationToken cancellationToken)
    {
        ViewData["Title"] = "گزارش ممیزی";

        PagedResult<AuditLogItemDto> result = await _auditLogService.GetPagedAsync(query, cancellationToken);

        ViewData["Query"] = query;
        ViewData["EntityNames"] = (await _auditLogService.GetEntityNamesAsync(cancellationToken))
            .Select(name => new SelectListItem(name, name))
            .ToList();

        ViewData["Actions"] = EnumDisplayExtensions.GetDisplayList<AuditAction>()
            .Select(item => new SelectListItem(item.Name, item.Value.ToString()))
            .ToList();

        return View(result);
    }
}
