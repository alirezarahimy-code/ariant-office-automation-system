using Ariant.OfficeAutomation.Application.Common;
using Ariant.OfficeAutomation.Application.Organization;
using Ariant.OfficeAutomation.Application.Organization.Dtos;
using Ariant.OfficeAutomation.Domain.Security;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace Ariant.OfficeAutomation.Web.Controllers.Api;

/// <summary>وب‌سرویس ساختار سازمانی (نسخه ۱).</summary>
[ApiController]
[Route("api/v1/departments")]
[Authorize(Policy = AppPolicies.ApiAccess)]
[Produces("application/json")]
public class DepartmentsApiController : ControllerBase
{
    private readonly IDepartmentService _departmentService;

    /// <summary>ساخت کنترلر.</summary>
    public DepartmentsApiController(IDepartmentService departmentService)
        => _departmentService = departmentService;

    /// <summary>فهرست واحدهای سازمانی.</summary>
    [HttpGet]
    [ProducesResponseType(typeof(PagedResult<DepartmentListItemDto>), StatusCodes.Status200OK)]
    public async Task<ActionResult<PagedResult<DepartmentListItemDto>>> GetAll([FromQuery] DepartmentQuery query, CancellationToken cancellationToken)
        => Ok(await _departmentService.GetPagedAsync(query, cancellationToken));

    /// <summary>درخت ساختار سازمانی.</summary>
    [HttpGet("tree")]
    [ProducesResponseType(typeof(IReadOnlyList<DepartmentTreeItemDto>), StatusCodes.Status200OK)]
    public async Task<ActionResult<IReadOnlyList<DepartmentTreeItemDto>>> GetTree(CancellationToken cancellationToken)
        => Ok(await _departmentService.GetTreeAsync(cancellationToken));

    /// <summary>جزئیات یک واحد.</summary>
    [HttpGet("{id:guid}")]
    [ProducesResponseType(typeof(DepartmentDetailDto), StatusCodes.Status200OK)]
    [ProducesResponseType(StatusCodes.Status404NotFound)]
    public async Task<ActionResult<DepartmentDetailDto>> GetById(Guid id, CancellationToken cancellationToken)
    {
        DepartmentDetailDto? department = await _departmentService.GetByIdAsync(id, cancellationToken);
        return department is null ? NotFound(new { message = "واحد سازمانی یافت نشد." }) : Ok(department);
    }

    /// <summary>ایجاد واحد جدید.</summary>
    [HttpPost]
    [Authorize(Policy = AppPolicies.ManageOrganization)]
    [ProducesResponseType(StatusCodes.Status201Created)]
    [ProducesResponseType(StatusCodes.Status400BadRequest)]
    public async Task<IActionResult> Create([FromBody] DepartmentFormRequest request, CancellationToken cancellationToken)
    {
        if (!ModelState.IsValid)
            return BadRequest(ModelState);

        Result<Guid> result = await _departmentService.CreateAsync(request, cancellationToken);

        return result.IsSuccess
            ? CreatedAtAction(nameof(GetById), new { id = result.Value }, new { id = result.Value })
            : BadRequest(new { message = result.ErrorMessage });
    }

    /// <summary>ویرایش واحد.</summary>
    [HttpPut("{id:guid}")]
    [Authorize(Policy = AppPolicies.ManageOrganization)]
    [ProducesResponseType(StatusCodes.Status204NoContent)]
    [ProducesResponseType(StatusCodes.Status400BadRequest)]
    public async Task<IActionResult> Update(Guid id, [FromBody] DepartmentFormRequest request, CancellationToken cancellationToken)
    {
        if (!ModelState.IsValid)
            return BadRequest(ModelState);

        Result result = await _departmentService.UpdateAsync(id, request, cancellationToken);

        return result.IsSuccess ? NoContent() : BadRequest(new { message = result.ErrorMessage });
    }

    /// <summary>حذف واحد.</summary>
    [HttpDelete("{id:guid}")]
    [Authorize(Policy = AppPolicies.ManageOrganization)]
    [ProducesResponseType(StatusCodes.Status204NoContent)]
    [ProducesResponseType(StatusCodes.Status400BadRequest)]
    public async Task<IActionResult> Delete(Guid id, CancellationToken cancellationToken)
    {
        Result result = await _departmentService.DeleteAsync(id, cancellationToken);

        return result.IsSuccess ? NoContent() : BadRequest(new { message = result.ErrorMessage });
    }

    /// <summary>بررسی سلامت سرویس.</summary>
    [HttpGet("/api/v1/health")]
    [AllowAnonymous]
    [ProducesResponseType(StatusCodes.Status200OK)]
    public IActionResult Health()
        => Ok(new
        {
            status = "ok",
            application = "سیستم اتوماسیون اداری آرینت",
            serverTimeUtc = DateTime.UtcNow,
            serverTimePersian = Ariant.OfficeAutomation.Common.PersianDate.Today.ToFullString()
        });
}
