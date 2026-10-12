using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Domain.Security;
using Ariant.OfficeAutomation.Infrastructure.Identity;
using Ariant.OfficeAutomation.Web.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Mvc;

namespace Ariant.OfficeAutomation.Web.Controllers;

/// <summary>ورود، خروج و مدیریت گذرواژه.</summary>
public class AccountController : Controller
{
    private readonly SignInManager<ApplicationUser> _signInManager;
    private readonly UserManager<ApplicationUser> _userManager;
    private readonly IAppDbContext _context;
    private readonly ICurrentUserService _currentUserService;
    private readonly IDateTimeProvider _dateTimeProvider;
    private readonly ILogger<AccountController> _logger;

    /// <summary>ساخت کنترلر.</summary>
    public AccountController(
        SignInManager<ApplicationUser> signInManager,
        UserManager<ApplicationUser> userManager,
        IAppDbContext context,
        ICurrentUserService currentUserService,
        IDateTimeProvider dateTimeProvider,
        ILogger<AccountController> logger)
    {
        _signInManager = signInManager;
        _userManager = userManager;
        _context = context;
        _currentUserService = currentUserService;
        _dateTimeProvider = dateTimeProvider;
        _logger = logger;
    }

    /// <summary>نمایش فرم ورود.</summary>
    [HttpGet]
    [AllowAnonymous]
    public IActionResult Login(string? returnUrl = null)
    {
        if (_currentUserService.IsAuthenticated)
            return RedirectToAction(nameof(HomeController.Index), "Home");

        return View(new LoginViewModel { ReturnUrl = returnUrl });
    }

    /// <summary>پردازش ورود.</summary>
    [HttpPost]
    [AllowAnonymous]
    [ValidateAntiForgeryToken]
    public async Task<IActionResult> Login(LoginViewModel model, CancellationToken cancellationToken)
    {
        if (!ModelState.IsValid)
            return View(model);

        ApplicationUser? user = await FindUserAsync(model.UserName);

        if (user is null)
        {
            ModelState.AddModelError(string.Empty, "نام کاربری یا گذرواژه نادرست است.");
            return View(model);
        }

        if (!user.IsActive)
        {
            ModelState.AddModelError(string.Empty, "حساب کاربری شما غیرفعال است. با مدیر سیستم تماس بگیرید.");
            return View(model);
        }

        SignInResult result = await _signInManager.PasswordSignInAsync(
            user,
            model.Password,
            model.RememberMe,
            lockoutOnFailure: true);

        if (result.Succeeded)
        {
            user.LastLoginAtUtc = _dateTimeProvider.UtcNow;
            await _userManager.UpdateAsync(user);

            await WriteAuditAsync(AuditAction.Login, user, "ورود موفق به سامانه", cancellationToken);
            _logger.LogInformation("کاربر {UserName} وارد شد.", user.UserName);

            return RedirectToLocal(model.ReturnUrl);
        }

        if (result.IsLockedOut)
        {
            ModelState.AddModelError(string.Empty, "حساب کاربری به دلیل تلاش‌های ناموفق، موقتاً قفل شده است.");
        }
        else if (result.IsNotAllowed)
        {
            ModelState.AddModelError(string.Empty, "ورود برای این حساب کاربری مجاز نیست.");
        }
        else
        {
            ModelState.AddModelError(string.Empty, "نام کاربری یا گذرواژه نادرست است.");
        }

        await WriteAuditAsync(AuditAction.LoginFailed, user, "تلاش ناموفق برای ورود", cancellationToken);
        return View(model);
    }

    /// <summary>خروج از سامانه.</summary>
    [HttpPost]
    [Authorize]
    [ValidateAntiForgeryToken]
    public async Task<IActionResult> Logout(CancellationToken cancellationToken)
    {
        string? userName = _currentUserService.UserName;
        Guid? userId = _currentUserService.UserId;

        await _signInManager.SignOutAsync();

        if (userId.HasValue)
        {
            _context.AuditLogs.Add(new AuditLog
            {
                TimestampUtc = _dateTimeProvider.UtcNow,
                UserId = userId,
                UserName = userName,
                Action = AuditAction.Logout,
                EntityName = nameof(ApplicationUser),
                EntityId = userId.Value.ToString(),
                Description = "خروج از سامانه",
                IpAddress = _currentUserService.IpAddress,
                UserAgent = _currentUserService.UserAgent
            });

            await _context.SaveChangesAsync(cancellationToken);
        }

        return RedirectToAction(nameof(Login));
    }

    /// <summary>نمایش پیام نبود دسترسی.</summary>
    [HttpGet]
    [Authorize]
    public IActionResult AccessDenied()
    {
        ViewData["Title"] = "نبود دسترسی";
        return View();
    }

    /// <summary>نمایش فرم تغییر گذرواژه.</summary>
    [HttpGet]
    [Authorize]
    public IActionResult ChangePassword() => View(new ChangePasswordViewModel());

    /// <summary>تغییر گذرواژه کاربر جاری.</summary>
    [HttpPost]
    [Authorize]
    [ValidateAntiForgeryToken]
    public async Task<IActionResult> ChangePassword(ChangePasswordViewModel model, CancellationToken cancellationToken)
    {
        if (!ModelState.IsValid)
            return View(model);

        ApplicationUser? user = await _userManager.GetUserAsync(User);
        if (user is null)
            return RedirectToAction(nameof(Login));

        IdentityResult result = await _userManager.ChangePasswordAsync(user, model.CurrentPassword, model.NewPassword);

        if (!result.Succeeded)
        {
            foreach (IdentityError error in result.Errors)
                ModelState.AddModelError(string.Empty, error.Description);

            return View(model);
        }

        user.MustChangePassword = false;
        await _userManager.UpdateAsync(user);
        await _signInManager.RefreshSignInAsync(user);

        TempData["SuccessMessage"] = "گذرواژه شما با موفقیت تغییر کرد.";
        return RedirectToAction(nameof(HomeController.Index), "Home");
    }

    private async Task<ApplicationUser?> FindUserAsync(string userNameOrEmail)
        => await _userManager.FindByNameAsync(userNameOrEmail)
           ?? await _userManager.FindByEmailAsync(userNameOrEmail);

    private async Task WriteAuditAsync(AuditAction action, ApplicationUser user, string description, CancellationToken cancellationToken)
    {
        _context.AuditLogs.Add(new AuditLog
        {
            TimestampUtc = _dateTimeProvider.UtcNow,
            UserId = user.Id,
            UserName = user.UserName,
            Action = action,
            EntityName = nameof(ApplicationUser),
            EntityId = user.Id.ToString(),
            Description = description,
            IpAddress = _currentUserService.IpAddress,
            UserAgent = _currentUserService.UserAgent
        });

        await _context.SaveChangesAsync(cancellationToken);
    }

    private IActionResult RedirectToLocal(string? returnUrl)
        => !string.IsNullOrWhiteSpace(returnUrl) && Url.IsLocalUrl(returnUrl)
            ? Redirect(returnUrl)
            : RedirectToAction(nameof(HomeController.Index), "Home");
}
