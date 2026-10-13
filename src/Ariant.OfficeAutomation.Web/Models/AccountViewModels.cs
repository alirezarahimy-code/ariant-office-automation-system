using System.ComponentModel.DataAnnotations;

namespace Ariant.OfficeAutomation.Web.Models;

/// <summary>ورودی فرم ورود به سامانه.</summary>
public class LoginViewModel
{
    /// <summary>نام کاربری یا پست الکترونیک.</summary>
    [Display(Name = "نام کاربری")]
    [Required(ErrorMessage = "نام کاربری را وارد کنید.")]
    [StringLength(256)]
    public string UserName { get; set; } = string.Empty;

    /// <summary>گذرواژه.</summary>
    [Display(Name = "گذرواژه")]
    [Required(ErrorMessage = "گذرواژه را وارد کنید.")]
    [DataType(DataType.Password)]
    [StringLength(100, MinimumLength = 4, ErrorMessage = "گذرواژه باید حداقل ۴ نویسه باشد.")]
    public string Password { get; set; } = string.Empty;

    /// <summary>مرا به خاطر بسپار.</summary>
    [Display(Name = "مرا به خاطر بسپار")]
    public bool RememberMe { get; set; }

    /// <summary>نشانی بازگشت پس از ورود.</summary>
    public string? ReturnUrl { get; set; }
}

/// <summary>تغییر گذرواژه توسط کاربر.</summary>
public class ChangePasswordViewModel
{
    /// <summary>گذرواژه فعلی.</summary>
    [Display(Name = "گذرواژه فعلی")]
    [Required(ErrorMessage = "گذرواژه فعلی را وارد کنید.")]
    [DataType(DataType.Password)]
    public string CurrentPassword { get; set; } = string.Empty;

    /// <summary>گذرواژه جدید.</summary>
    [Display(Name = "گذرواژه جدید")]
    [Required(ErrorMessage = "گذرواژه جدید را وارد کنید.")]
    [DataType(DataType.Password)]
    [StringLength(100, MinimumLength = 8, ErrorMessage = "گذرواژه جدید باید حداقل ۸ نویسه باشد.")]
    public string NewPassword { get; set; } = string.Empty;

    /// <summary>تکرار گذرواژه جدید.</summary>
    [Display(Name = "تکرار گذرواژه جدید")]
    [Required(ErrorMessage = "تکرار گذرواژه جدید را وارد کنید.")]
    [DataType(DataType.Password)]
    [Compare(nameof(NewPassword), ErrorMessage = "گذرواژه جدید و تکرار آن یکسان نیستند.")]
    public string ConfirmPassword { get; set; } = string.Empty;
}
