using Ariant.OfficeAutomation.Application.Dashboard;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace Ariant.OfficeAutomation.Web.Controllers;

/// <summary>صفحه اصلی و داشبورد مدیریتی.</summary>
[Authorize]
public class HomeController : Controller
{
    private readonly IDashboardService _dashboardService;
    private readonly ILogger<HomeController> _logger;

    /// <summary>ساخت کنترلر.</summary>
    public HomeController(IDashboardService dashboardService, ILogger<HomeController> logger)
    {
        _dashboardService = dashboardService;
        _logger = logger;
    }

    /// <summary>داشبورد.</summary>
    public async Task<IActionResult> Index(CancellationToken cancellationToken)
    {
        DashboardSummaryDto summary = await _dashboardService.GetSummaryAsync(cancellationToken);
        return View(summary);
    }

    /// <summary>صفحه خطا.</summary>
    [AllowAnonymous]
    public IActionResult Error()
    {
        ViewData["Title"] = "خطا";
        return View();
    }
}
