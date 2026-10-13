using System.ComponentModel.DataAnnotations;

namespace Ariant.OfficeAutomation.Domain.Organization;

/// <summary>نوع همکاری کارمند.</summary>
public enum EmploymentType
{
    /// <summary>تمام‌وقت</summary>
    [Display(Name = "تمام‌وقت")]
    FullTime = 1,

    /// <summary>پاره‌وقت</summary>
    [Display(Name = "پاره‌وقت")]
    PartTime = 2,

    /// <summary>پیمانکاری</summary>
    [Display(Name = "پیمانکاری")]
    Contract = 3,

    /// <summary>ساعتی</summary>
    [Display(Name = "ساعتی")]
    Hourly = 4,

    /// <summary>کارآموز</summary>
    [Display(Name = "کارآموز")]
    Intern = 5
}

/// <summary>وضعیت کارمند.</summary>
public enum EmployeeStatus
{
    /// <summary>شاغل</summary>
    [Display(Name = "شاغل")]
    Active = 1,

    /// <summary>مرخصی بدون حقوق</summary>
    [Display(Name = "مرخصی بدون حقوق")]
    UnpaidLeave = 2,

    /// <summary>تعلیق</summary>
    [Display(Name = "تعلیق")]
    Suspended = 3,

    /// <summary>پایان همکاری</summary>
    [Display(Name = "پایان همکاری")]
    Terminated = 4,

    /// <summary>بازنشسته</summary>
    [Display(Name = "بازنشسته")]
    Retired = 5
}
