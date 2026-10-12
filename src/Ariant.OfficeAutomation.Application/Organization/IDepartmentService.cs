using Ariant.OfficeAutomation.Application.Common;
using Ariant.OfficeAutomation.Application.Organization.Dtos;

namespace Ariant.OfficeAutomation.Application.Organization;

/// <summary>سرویس مدیریت ساختار سازمانی (واحدها).</summary>
public interface IDepartmentService
{
    /// <summary>فهرست صفحه‌بندی‌شده واحدها همراه جست‌وجو.</summary>
    Task<PagedResult<DepartmentListItemDto>> GetPagedAsync(DepartmentQuery query, CancellationToken cancellationToken = default);

    /// <summary>درخت کامل ساختار سازمانی.</summary>
    Task<IReadOnlyList<DepartmentTreeItemDto>> GetTreeAsync(CancellationToken cancellationToken = default);

    /// <summary>جزئیات یک واحد.</summary>
    Task<DepartmentDetailDto?> GetByIdAsync(Guid id, CancellationToken cancellationToken = default);

    /// <summary>ایجاد واحد جدید.</summary>
    Task<Result<Guid>> CreateAsync(DepartmentFormRequest request, CancellationToken cancellationToken = default);

    /// <summary>ویرایش واحد.</summary>
    Task<Result> UpdateAsync(Guid id, DepartmentFormRequest request, CancellationToken cancellationToken = default);

    /// <summary>حذف نرم واحد (در صورت نداشتن زیرمجموعه و کارمند).</summary>
    Task<Result> DeleteAsync(Guid id, CancellationToken cancellationToken = default);

    /// <summary>گزینه‌های کشویی واحدهای بالادستی.</summary>
    Task<IReadOnlyList<SelectOptionDto>> GetOptionsAsync(Guid? excludeId = null, CancellationToken cancellationToken = default);

    /// <summary>آیا واحد زیرمجموعه واحد دیگری است؟ (برای جلوگیری از حلقه در درخت)</summary>
    Task<bool> IsDescendantAsync(Guid candidateChildId, Guid ancestorId, CancellationToken cancellationToken = default);
}
