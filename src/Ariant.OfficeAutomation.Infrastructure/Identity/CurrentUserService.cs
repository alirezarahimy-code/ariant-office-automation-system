using System.Security.Claims;
using Ariant.OfficeAutomation.Application.Abstractions;
using Microsoft.AspNetCore.Http;

namespace Ariant.OfficeAutomation.Infrastructure.Identity;

/// <summary>پیاده‌سازی دسترسی به کاربر جاری بر پایه <see cref="IHttpContextAccessor"/>.</summary>
public sealed class CurrentUserService : ICurrentUserService
{
    private readonly IHttpContextAccessor _httpContextAccessor;

    /// <summary>ساخت سرویس.</summary>
    public CurrentUserService(IHttpContextAccessor httpContextAccessor)
        => _httpContextAccessor = httpContextAccessor;

    private ClaimsPrincipal? Principal => _httpContextAccessor.HttpContext?.User;

    /// <inheritdoc />
    public Guid? UserId
    {
        get
        {
            string? value = Principal?.FindFirstValue(ClaimTypes.NameIdentifier);
            return Guid.TryParse(value, out Guid id) ? id : null;
        }
    }

    /// <inheritdoc />
    public string? UserName => Principal?.Identity?.IsAuthenticated == true
        ? Principal.FindFirstValue(ClaimTypes.Name) ?? Principal.Identity.Name
        : null;

    /// <inheritdoc />
    public string? IpAddress => _httpContextAccessor.HttpContext?.Connection.RemoteIpAddress?.ToString();

    /// <inheritdoc />
    public string? UserAgent
    {
        get
        {
            string? userAgent = _httpContextAccessor.HttpContext?.Request.Headers.UserAgent;
            return string.IsNullOrWhiteSpace(userAgent) ? null : userAgent.ToString();
        }
    }

    /// <inheritdoc />
    public bool IsAuthenticated => Principal?.Identity?.IsAuthenticated ?? false;

    /// <inheritdoc />
    public bool IsInRole(string role) => Principal?.IsInRole(role) ?? false;
}
