using System.ComponentModel.DataAnnotations;
using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Infrastructure.Identity;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Mvc;

namespace Ariant.OfficeAutomation.Web.Controllers.Api;

/// <summary>
/// صدور نشانه دسترسی (JWT) برای سامانه‌های بیرونی.
/// نمونه کاربرد: سامانه تیکتینگ برای فراخوانی وب‌سرویس سامانه اتوماسیون.
/// </summary>
[ApiController]
[Route("api/v1/auth")]
public class AuthApiController : ControllerBase
{
    private readonly UserManager<ApplicationUser> _userManager;
    private readonly SignInManager<ApplicationUser> _signInManager;
    private readonly IJwtTokenService _jwtTokenService;
    private readonly IDateTimeProvider _dateTimeProvider;
    private readonly ILogger<AuthApiController> _logger;

    /// <summary>ساخت کنترلر.</summary>
    public AuthApiController(
        UserManager<ApplicationUser> userManager,
        SignInManager<ApplicationUser> signInManager,
        IJwtTokenService jwtTokenService,
        IDateTimeProvider dateTimeProvider,
        ILogger<AuthApiController> logger)
    {
        _userManager = userManager;
        _signInManager = signInManager;
        _jwtTokenService = jwtTokenService;
        _dateTimeProvider = dateTimeProvider;
        _logger = logger;
    }

    /// <summary>دریافت نشانه دسترسی با نام کاربری و گذرواژه.</summary>
    /// <response code="200">نشانه با موفقیت صادر شد.</response>
    /// <response code="401">نام کاربری یا گذرواژه نادرست است.</response>
    [HttpPost("token")]
    [AllowAnonymous]
    [ProducesResponseType(typeof(TokenResponse), StatusCodes.Status200OK)]
    [ProducesResponseType(StatusCodes.Status401Unauthorized)]
    public async Task<IActionResult> Token([FromBody] TokenRequest request, CancellationToken cancellationToken)
    {
        if (!ModelState.IsValid)
            return BadRequest(ModelState);

        ApplicationUser? user = await _userManager.FindByNameAsync(request.UserName)
            ?? await _userManager.FindByEmailAsync(request.UserName);

        if (user is null || !user.IsActive)
            return Unauthorized(new { message = "نام کاربری یا گذرواژه نادرست است." });

        SignInResult signInResult = await _signInManager.CheckPasswordSignInAsync(user, request.Password, lockoutOnFailure: true);

        if (!signInResult.Succeeded)
        {
            _logger.LogWarning("تلاش ناموفق برای دریافت نشانه دسترسی با نام کاربری {UserName}", request.UserName);
            return Unauthorized(new { message = "نام کاربری یا گذرواژه نادرست است." });
        }

        user.LastLoginAtUtc = _dateTimeProvider.UtcNow;
        await _userManager.UpdateAsync(user);

        TokenResultDto token = await _jwtTokenService.CreateAsync(user.Id, cancellationToken);

        return Ok(new TokenResponse
        {
            AccessToken = token.AccessToken,
            TokenType = token.TokenType,
            ExpiresAtUtc = token.ExpiresAtUtc,
            ExpiresAtPersian = Ariant.OfficeAutomation.Common.PersianDate
                .FromDateTime(token.ExpiresAtLocal)
                .ToLocalizedString("yyyy/MM/dd HH:mm")
        });
    }

    /// <summary>اطلاعات کاربر جاری بر پایه نشانه دسترسی.</summary>
    [HttpGet("me")]
    [Authorize(Policy = Domain.Security.AppPolicies.ApiAccess)]
    public async Task<IActionResult> Me()
    {
        ApplicationUser? user = await _userManager.GetUserAsync(User);
        if (user is null)
            return Unauthorized();

        IList<string> roles = await _userManager.GetRolesAsync(user);

        return Ok(new
        {
            id = user.Id,
            userName = user.UserName,
            fullName = user.FullName,
            email = user.Email,
            roles,
            roleTitles = Domain.Security.AppRoles.ToTitles(roles)
        });
    }
}

/// <summary>درخواست دریافت نشانه دسترسی.</summary>
public class TokenRequest
{
    /// <summary>نام کاربری یا پست الکترونیک.</summary>
    [Required(ErrorMessage = "نام کاربری الزامی است.")]
    public string UserName { get; set; } = string.Empty;

    /// <summary>گذرواژه.</summary>
    [Required(ErrorMessage = "گذرواژه الزامی است.")]
    public string Password { get; set; } = string.Empty;
}

/// <summary>پاسخ نشانه دسترسی.</summary>
public class TokenResponse
{
    /// <summary>نشانه دسترسی.</summary>
    public string AccessToken { get; set; } = string.Empty;

    /// <summary>نوع نشانه.</summary>
    public string TokenType { get; set; } = "Bearer";

    /// <summary>زمان انقضا (UTC).</summary>
    public DateTime ExpiresAtUtc { get; set; }

    /// <summary>زمان انقضا به شمسی.</summary>
    public string ExpiresAtPersian { get; set; } = string.Empty;
}
