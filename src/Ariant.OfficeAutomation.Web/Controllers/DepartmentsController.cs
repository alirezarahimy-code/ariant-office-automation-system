using Ariant.OfficeAutomation.Application.Common;
using Ariant.OfficeAutomation.Application.Organization;
using Ariant.OfficeAutomation.Application.Organization.Dtos;
using Ariant.OfficeAutomation.Domain.Security;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.Rendering;

namespace Ariant.OfficeAutomation.Web.Controllers;

/// <summary>مدیریت ساختار سازمانی (واحدها).</summary>
[Authorize(Policy = AppPolicies.ViewOrganization)]
public class DepartmentsController : Controller
{
    private readonly IDepartmentService _departmentService;

    /// <summary>ساخت کنترلر.</summary>
    public DepartmentsController(IDepartmentService departmentService)
        => _departmentService = departmentService;

    /// <summary>فهرست واحدهای سازمانی.</summary>
    public async Task<IActionResult> Index(DepartmentQuery query, CancellationToken cancellationToken)
    {
        ViewData["Title"] = "ساختار سازمانی";
        PagedResult<DepartmentListItemDto> result = await _departmentService.GetPagedAsync(query, cancellationToken);
        ViewData["Query"] = query;
        return View(result);
    }

    /// <summary>نمایش درختی ساختار سازمانی.</summary>
    public async Task<IActionResult> Tree(CancellationToken cancellationToken)
    {
        ViewData["Title"] = "نمای درختی ساختار سازمانی";
        IReadOnlyList<DepartmentTreeItemDto> tree = await _departmentService.GetTreeAsync(cancellationToken);
        return View(tree);
    }

    /// <summary>نمایش جزئیات واحد.</summary>
    public async Task<IActionResult> Details(Guid id, CancellationToken cancellationToken)
    {
        DepartmentDetailDto? department = await _departmentService.GetByIdAsync(id, cancellationToken);
        if (department is null)
            return NotFound();

        ViewData["Title"] = "جزئیات واحد سازمانی";
        return View(department);
    }

    /// <summary>فرم ایجاد واحد جدید.</summary>
    [Authorize(Policy = AppPolicies.ManageOrganization)]
    public async Task<IActionResult> Create(CancellationToken cancellationToken)
    {
        ViewData["Title"] = "افزودن واحد سازمانی";
        await FillParentOptionsAsync(null, cancellationToken);
        return View(new DepartmentFormRequest());
    }

    /// <summary>ثبت واحد جدید.</summary>
    [HttpPost]
    [Authorize(Policy = AppPolicies.ManageOrganization)]
    [ValidateAntiForgeryToken]
    public async Task<IActionResult> Create(DepartmentFormRequest request, CancellationToken cancellationToken)
    {
        if (!ModelState.IsValid)
        {
            ViewData["Title"] = "افزودن واحد سازمانی";
            await FillParentOptionsAsync(null, cancellationToken);
            return View(request);
        }

        Result<Guid> result = await _departmentService.CreateAsync(request, cancellationToken);

        if (result.IsFailure)
        {
            ModelState.AddModelError(string.Empty, result.ErrorMessage);
            ViewData["Title"] = "افزودن واحد سازمانی";
            await FillParentOptionsAsync(null, cancellationToken);
            return View(request);
        }

        TempData["SuccessMessage"] = $"واحد «{request.Title}» با موفقیت ثبت شد.";
        return RedirectToAction(nameof(Index));
    }

    /// <summary>فرم ویرایش واحد.</summary>
    [Authorize(Policy = AppPolicies.ManageOrganization)]
    public async Task<IActionResult> Edit(Guid id, CancellationToken cancellationToken)
    {
        DepartmentDetailDto? department = await _departmentService.GetByIdAsync(id, cancellationToken);
        if (department is null)
            return NotFound();

        ViewData["Title"] = "ویرایش واحد سازمانی";
        await FillParentOptionsAsync(id, cancellationToken);

        return View(new DepartmentFormRequest
        {
            Title = department.Title,
            Code = department.Code,
            ParentId = department.ParentId,
            Description = department.Description,
            SortOrder = department.SortOrder,
            IsActive = department.IsActive
        });
    }

    /// <summary>ثبت ویرایش واحد.</summary>
    [HttpPost]
    [Authorize(Policy = AppPolicies.ManageOrganization)]
    [ValidateAntiForgeryToken]
    public async Task<IActionResult> Edit(Guid id, DepartmentFormRequest request, CancellationToken cancellationToken)
    {
        if (!ModelState.IsValid)
        {
            ViewData["Title"] = "ویرایش واحد سازمانی";
            await FillParentOptionsAsync(id, cancellationToken);
            return View(request);
        }

        Result result = await _departmentService.UpdateAsync(id, request, cancellationToken);

        if (result.IsFailure)
        {
            ModelState.AddModelError(string.Empty, result.ErrorMessage);
            ViewData["Title"] = "ویرایش واحد سازمانی";
            await FillParentOptionsAsync(id, cancellationToken);
            return View(request);
        }

        TempData["SuccessMessage"] = $"تغییرات واحد «{request.Title}» ذخیره شد.";
        return RedirectToAction(nameof(Index));
    }

    /// <summary>حذف واحد.</summary>
    [HttpPost]
    [Authorize(Policy = AppPolicies.ManageOrganization)]
    [ValidateAntiForgeryToken]
    public async Task<IActionResult> Delete(Guid id, CancellationToken cancellationToken)
    {
        Result result = await _departmentService.DeleteAsync(id, cancellationToken);

        TempData[result.IsSuccess ? "SuccessMessage" : "ErrorMessage"] = result.IsSuccess
            ? "واحد سازمانی حذف شد."
            : result.ErrorMessage;

        return RedirectToAction(nameof(Index));
    }

    private async Task FillParentOptionsAsync(Guid? excludeId, CancellationToken cancellationToken)
    {
        IReadOnlyList<SelectOptionDto> options = await _departmentService.GetOptionsAsync(excludeId, cancellationToken);

        var items = new List<SelectListItem>
        {
            new() { Value = string.Empty, Text = "— بدون واحد بالادستی (واحد ریشه) —" }
        };

        items.AddRange(options.Select(option => new SelectListItem(option.Text, option.Value)));

        ViewData["ParentOptions"] = items;
    }
}
