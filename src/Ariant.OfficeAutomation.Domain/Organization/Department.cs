using Ariant.OfficeAutomation.Domain.Common;

namespace Ariant.OfficeAutomation.Domain.Organization;

/// <summary>
/// واحد سازمانی (درخت ساختار سازمانی). هر واحد می‌تواند زیرمجموعه واحد دیگر باشد.
/// </summary>
public class Department : SoftDeletableEntity
{
    /// <summary>نام واحد (مثلاً «دفتر مدیریت»).</summary>
    public string Title { get; set; } = string.Empty;

    /// <summary>کد واحد در ساختار سازمانی (اختیاری).</summary>
    public string? Code { get; set; }

    /// <summary>شناسه واحد بالادستی؛ برای واحدهای ریشه‌ای مقدار ندارد.</summary>
    public Guid? ParentId { get; set; }

    /// <summary>واحد بالادستی.</summary>
    public Department? Parent { get; set; }

    /// <summary>زیرمجموعه‌ها.</summary>
    public ICollection<Department> Children { get; set; } = new List<Department>();

    /// <summary>شرح وظایف یا توضیحات واحد.</summary>
    public string? Description { get; set; }

    /// <summary>ترتیب نمایش میان واحدهای هم‌سطح.</summary>
    public int SortOrder { get; set; }

    /// <summary>آیا واحد فعال است؟</summary>
    public bool IsActive { get; set; } = true;

    /// <summary>کارکنان این واحد.</summary>
    public ICollection<Employee> Employees { get; set; } = new List<Employee>();

    /// <summary>آیا این واحد ریشه درخت سازمانی است؟</summary>
    public bool IsRoot => ParentId is null;
}
