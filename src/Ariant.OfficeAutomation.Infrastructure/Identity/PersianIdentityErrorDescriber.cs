using Microsoft.AspNetCore.Identity;

namespace Ariant.OfficeAutomation.Infrastructure.Identity;

/// <summary>
/// پیام‌های خطای فارسی برای ASP.NET Core Identity.
/// بدون این کلاس، خطاهایی مانند «Passwords must have at least one non alphanumeric character»
/// به کاربر نمایش داده می‌شد.
/// </summary>
public class PersianIdentityErrorDescriber : IdentityErrorDescriber
{
    /// <inheritdoc />
    public override IdentityError DefaultError()
        => Create(nameof(DefaultError), "خطای ناشناخته‌ای رخ داد. لطفاً دوباره تلاش کنید.");

    /// <inheritdoc />
    public override IdentityError ConcurrencyFailure()
        => Create(nameof(ConcurrencyFailure), "اطلاعات توسط کاربر دیگری تغییر کرده است. صفحه را تازه کنید و دوباره تلاش کنید.");

    /// <inheritdoc />
    public override IdentityError InvalidToken()
        => Create(nameof(InvalidToken), "نشانه (توکن) نامعتبر است.");

    /// <inheritdoc />
    public override IdentityError DuplicateEmail(string email)
        => Create(nameof(DuplicateEmail), $"پست الکترونیک «{email}» قبلاً در سامانه ثبت شده است.");

    /// <inheritdoc />
    public override IdentityError DuplicateUserName(string userName)
        => Create(nameof(DuplicateUserName), $"نام کاربری «{userName}» قبلاً در سامانه ثبت شده است.");

    /// <inheritdoc />
    public override IdentityError InvalidEmail(string? email)
        => Create(nameof(InvalidEmail), $"پست الکترونیک «{email}» معتبر نیست.");

    /// <inheritdoc />
    public override IdentityError InvalidUserName(string? userName)
        => Create(nameof(InvalidUserName), $"نام کاربری «{userName}» معتبر نیست؛ فقط حروف و ارقام انگلیسی مجاز است.");

    /// <inheritdoc />
    public override IdentityError PasswordMismatch()
        => Create(nameof(PasswordMismatch), "گذرواژه فعلی نادرست است.");

    /// <inheritdoc />
    public override IdentityError PasswordTooShort(int length)
        => Create(nameof(PasswordTooShort), $"گذرواژه باید حداقل {length} نویسه باشد.");

    /// <inheritdoc />
    public override IdentityError PasswordRequiresDigit()
        => Create(nameof(PasswordRequiresDigit), "گذرواژه باید حداقل یک رقم داشته باشد.");

    /// <inheritdoc />
    public override IdentityError PasswordRequiresLower()
        => Create(nameof(PasswordRequiresLower), "گذرواژه باید حداقل یک حرف کوچک انگلیسی داشته باشد.");

    /// <inheritdoc />
    public override IdentityError PasswordRequiresUpper()
        => Create(nameof(PasswordRequiresUpper), "گذرواژه باید حداقل یک حرف بزرگ انگلیسی داشته باشد.");

    /// <inheritdoc />
    public override IdentityError PasswordRequiresNonAlphanumeric()
        => Create(nameof(PasswordRequiresNonAlphanumeric), "گذرواژه باید حداقل یک نویسه ویژه (مانند ! یا @ یا #) داشته باشد.");

    /// <inheritdoc />
    public override IdentityError PasswordRequiresUniqueChars(int uniqueChars)
        => Create(nameof(PasswordRequiresUniqueChars), $"گذرواژه باید حداقل {uniqueChars} نویسه متمایز داشته باشد.");

    /// <inheritdoc />
    public override IdentityError UserAlreadyHasPassword()
        => Create(nameof(UserAlreadyHasPassword), "برای این کاربر قبلاً گذرواژه تعیین شده است.");

    /// <inheritdoc />
    public override IdentityError UserLockoutNotEnabled()
        => Create(nameof(UserLockoutNotEnabled), "قفل حساب برای این کاربر فعال نیست.");

    /// <inheritdoc />
    public override IdentityError UserAlreadyInRole(string role)
        => Create(nameof(UserAlreadyInRole), $"کاربر از قبل نقش «{role}» را دارد.");

    /// <inheritdoc />
    public override IdentityError UserNotInRole(string role)
        => Create(nameof(UserNotInRole), $"کاربر نقش «{role}» را ندارد.");

    private static IdentityError Create(string code, string description)
        => new() { Code = code, Description = description };
}
