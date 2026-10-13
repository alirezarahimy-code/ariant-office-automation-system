using System.ComponentModel.DataAnnotations;
using Ariant.OfficeAutomation.Application.Common;
using Ariant.OfficeAutomation.Common;

namespace Ariant.OfficeAutomation.Application.Organization.Dtos;

/// <summary>ردیف واحد سازمانی در فهرست.</summary>
public sealed class DepartmentListItemDto
{
    /// <summary>شناسه.</summary>
    public Guid Id { get; init; }

    /// <summary>نام واحد.</summary>
    public string Title { get; init; } = string.Empty;

    /// <summary>کد واحد.</summary>
    public string? Code { get; init; }

    /// <summary>نام واحد بالادستی.</summary>
    public string? ParentTitle { get; init; }

    /// <summary>تعداد کارکنان واحد.</summary>
    public int EmployeeCount { get; init; }

    /// <summary>ترتیب نمایش.</summary>
    public int SortOrder { get; init; }

    /// <summary>وضعیت فعال بودن.</summary>
    public bool IsActive { get; init; }

    /// <summary>تاریخ ایجاد (میلادی).</summary>
    public DateTime CreatedAtUtc { get; init; }

    /// <summary>تاریخ ایجاد به شمسی (محاسبه در حافظه، بدون وابستگی به پایگاه‌داده).</summary>
    public PersianDate CreatedAtPersian => CreatedAtUtc == default ? PersianDate.Today : PersianDate.FromDateTime(CreatedAtUtc);

    /// <summary>تاریخ ایجاد به شکل «۲۰ مهر ۱۴۰۴».</summary>
    public string CreatedAtDisplay => CreatedAtPersian.ToShortString();
}

/// <summary>گره درخت ساختار سازمانی.</summary>
public sealed class DepartmentTreeItemDto
{
    /// <summary>شناسه.</summary>
    public Guid Id { get; init; }

    /// <summary>نام واحد.</summary>
    public string Title { get; init; } = string.Empty;

    /// <summary>کد واحد.</summary>
    public string? Code { get; init; }

    /// <summary>وضعیت فعال بودن.</summary>
    public bool IsActive { get; init; }

    /// <summary>تعداد کارکنان.</summary>
    public int EmployeeCount { get; init; }

    /// <summary>زیرمجموعه‌ها.</summary>
    public List<DepartmentTreeItemDto> Children { get; init; } = new();

    /// <summary>عمق گره در درخت (ریشه = ۰).</summary>
    public int Depth { get; set; }
}

/// <summary>جزئیات یک واحد سازمانی.</summary>
public sealed class DepartmentDetailDto
{
    /// <summary>شناسه.</summary>
    public Guid Id { get; init; }

    /// <summary>نام واحد.</summary>
    public string Title { get; init; } = string.Empty;

    /// <summary>کد واحد.</summary>
    public string? Code { get; init; }

    /// <summary>شناسه واحد بالادستی.</summary>
    public Guid? ParentId { get; init; }

    /// <summary>نام واحد بالادستی.</summary>
    public string? ParentTitle { get; init; }

    /// <summary>توضیحات.</summary>
    public string? Description { get; init; }

    /// <summary>ترتیب نمایش.</summary>
    public int SortOrder { get; init; }

    /// <summary>وضعیت فعال بودن.</summary>
    public bool IsActive { get; init; }

    /// <summary>تعداد کارکنان.</summary>
    public int EmployeeCount { get; init; }

    /// <summary>تاریخ ثبت (میلادی).</summary>
    public DateTime CreatedAtUtc { get; init; }

    /// <summary>تاریخ ثبت به شمسی.</summary>
    public string CreatedAtDisplay => CreatedAtUtc == default ? "—" : PersianDate.FromDateTime(CreatedAtUtc).ToLongString();

    /// <summary>آخرین ویرایش (میلادی).</summary>
    public DateTime? UpdatedAtUtc { get; init; }

    /// <summary>آخرین ویرایش به شمسی.</summary>
    public string UpdatedAtDisplay => UpdatedAtUtc.HasValue
        ? PersianDate.FromDateTime(UpdatedAtUtc.Value).ToLongString()
        : "—";
}

/// <summary>ورودی فرم ایجاد/ویرایش واحد سازمانی.</summary>
public class DepartmentFormRequest
{
    /// <summary>نام واحد.</summary>
    [Display(Name = "نام واحد")]
    [Required(ErrorMessage = "نام واحد الزامی است.")]
    [StringLength(150, ErrorMessage = "نام واحد نمی‌تواند بیشتر از ۱۵۰ نویسه باشد.")]
    public string Title { get; set; } = string.Empty;

    /// <summary>کد واحد.</summary>
    [Display(Name = "کد واحد")]
    [StringLength(30, ErrorMessage = "کد واحد نمی‌تواند بیشتر از ۳۰ نویسه باشد.")]
    public string? Code { get; set; }

    /// <summary>واحد بالادستی.</summary>
    [Display(Name = "واحد بالادستی")]
    public Guid? ParentId { get; set; }

    /// <summary>توضیحات.</summary>
    [Display(Name = "توضیحات")]
    [StringLength(1000, ErrorMessage = "توضیحات نمی‌تواند بیشتر از ۱۰۰۰ نویسه باشد.")]
    public string? Description { get; set; }

    /// <summary>ترتیب نمایش.</summary>
    [Display(Name = "ترتیب نمایش")]
    [Range(0, 9999, ErrorMessage = "ترتیب نمایش باید عددی بین ۰ و ۹۹۹۹ باشد.")]
    public int SortOrder { get; set; }

    /// <summary>وضعیت فعال بودن.</summary>
    [Display(Name = "فعال")]
    public bool IsActive { get; set; } = true;
}

/// <summary>پارامترهای جست‌وجوی واحدهای سازمانی.</summary>
public sealed class DepartmentQuery : PagedQuery
{
    /// <summary>فیلتر وضعیت فعال.</summary>
    public bool? IsActive { get; set; }

    /// <summary>فیلتر واحد بالادستی.</summary>
    public Guid? ParentId { get; set; }
}
