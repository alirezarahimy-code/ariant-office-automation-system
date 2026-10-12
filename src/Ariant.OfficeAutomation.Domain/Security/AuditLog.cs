using System.ComponentModel.DataAnnotations;
using Ariant.OfficeAutomation.Common;
using Ariant.OfficeAutomation.Domain.Common;

namespace Ariant.OfficeAutomation.Domain.Security;

/// <summary>
/// گزارش ممیزی (Audit Trail): هر تغییر داده‌ای در سیستم با کاربر، زمان و
/// مقادیر پیش/پس ثبت می‌شود. این گزارش پایه پاسخ‌گویی و پیگیری تخلفات است.
/// </summary>
public class AuditLog
{
    /// <summary>شناسه (خودافزا).</summary>
    public long Id { get; set; }

    /// <summary>زمان رویداد (میلادی، UTC).</summary>
    public DateTime TimestampUtc { get; set; }

    /// <summary>شناسه کاربر عامل.</summary>
    public Guid? UserId { get; set; }

    /// <summary>نام کاربر عامل.</summary>
    public string? UserName { get; set; }

    /// <summary>نوع رویداد.</summary>
    public AuditAction Action { get; set; }

    /// <summary>نام موجودیت (مثلاً Department).</summary>
    public string EntityName { get; set; } = string.Empty;

    /// <summary>شناسه رکورد تغییریافته.</summary>
    public string? EntityId { get; set; }

    /// <summary>فهرست ستون‌های تغییریافته.</summary>
    public string? AffectedColumns { get; set; }

    /// <summary>مقادیر پیش از تغییر (JSON).</summary>
    public string? OldValues { get; set; }

    /// <summary>مقادیر پس از تغییر (JSON).</summary>
    public string? NewValues { get; set; }

    /// <summary>نشانی شبکه کاربر.</summary>
    public string? IpAddress { get; set; }

    /// <summary>مرورگر/کارخواه کاربر.</summary>
    public string? UserAgent { get; set; }

    /// <summary>توضیح تکمیلی.</summary>
    public string? Description { get; set; }

    /// <summary>زمان رویداد به شمسی (برای نمایش).</summary>
    public PersianDate TimestampPersian => PersianDate.FromDateTime(TimestampUtc == default ? DateTime.UtcNow : TimestampUtc);
}

/// <summary>نوع رویداد ثبت‌شده در گزارش ممیزی.</summary>
public enum AuditAction
{
    /// <summary>ایجاد</summary>
    [Display(Name = "ایجاد")]
    Created = 1,

    /// <summary>ویرایش</summary>
    [Display(Name = "ویرایش")]
    Updated = 2,

    /// <summary>حذف</summary>
    [Display(Name = "حذف")]
    Deleted = 3,

    /// <summary>ورود به سامانه</summary>
    [Display(Name = "ورود به سامانه")]
    Login = 4,

    /// <summary>خروج از سامانه</summary>
    [Display(Name = "خروج از سامانه")]
    Logout = 5,

    /// <summary>ورود ناموفق</summary>
    [Display(Name = "ورود ناموفق")]
    LoginFailed = 6,

    /// <summary>مشاهده</summary>
    [Display(Name = "مشاهده")]
    Viewed = 7,

    /// <summary>دیگر</summary>
    [Display(Name = "سایر")]
    Other = 99
}
