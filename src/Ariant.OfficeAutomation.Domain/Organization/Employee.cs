using Ariant.OfficeAutomation.Common;
using Ariant.OfficeAutomation.Domain.Common;

namespace Ariant.OfficeAutomation.Domain.Organization;

/// <summary>
/// کارمند (پرونده پرسنلی). امکان دارد کارمند حساب کاربری نداشته باشد؛
/// در آن صورت <see cref="UserId"/> خالی می‌ماند.
/// </summary>
public class Employee : SoftDeletableEntity
{
    /// <summary>نام.</summary>
    public string FirstName { get; set; } = string.Empty;

    /// <summary>نام خانوادگی.</summary>
    public string LastName { get; set; } = string.Empty;

    /// <summary>کد ملی (۱۰ رقم).</summary>
    public string? NationalCode { get; set; }

    /// <summary>کد پرسنلی.</summary>
    public string? PersonnelCode { get; set; }

    /// <summary>عنوان شغلی (مثلاً «کارشناس دبیرخانه»).</summary>
    public string? JobTitle { get; set; }

    /// <summary>شماره همراه.</summary>
    public string? MobileNumber { get; set; }

    /// <summary>رایانامه سازمانی.</summary>
    public string? Email { get; set; }

    /// <summary>واحد سازمانی.</summary>
    public Guid? DepartmentId { get; set; }

    /// <summary>واحد سازمانی.</summary>
    public Department? Department { get; set; }

    /// <summary>شناسه حساب کاربری متناظر (اختیاری).</summary>
    public Guid? UserId { get; set; }

    /// <summary>تاریخ استخدام (میلادی، UTC).</summary>
    public DateTime? HireDateUtc { get; set; }

    /// <summary>تاریخ تولد (میلادی، UTC).</summary>
    public DateTime? BirthDateUtc { get; set; }

    /// <summary>نوع همکاری.</summary>
    public EmploymentType EmploymentType { get; set; } = EmploymentType.FullTime;

    /// <summary>وضعیت کارمند.</summary>
    public EmployeeStatus Status { get; set; } = EmployeeStatus.Active;

    /// <summary>یادداشت.</summary>
    public string? Notes { get; set; }

    /// <summary>نام و نام خانوادگی.</summary>
    public string FullName => $"{FirstName} {LastName}".Trim();

    /// <summary>تاریخ استخدام به شمسی (برای نمایش).</summary>
    public PersianDate? HireDatePersian => HireDateUtc.HasValue ? PersianDate.FromDateTime(HireDateUtc.Value) : null;

    /// <summary>تاریخ تولد به شمسی (برای نمایش).</summary>
    public PersianDate? BirthDatePersian => BirthDateUtc.HasValue ? PersianDate.FromDateTime(BirthDateUtc.Value) : null;
}
