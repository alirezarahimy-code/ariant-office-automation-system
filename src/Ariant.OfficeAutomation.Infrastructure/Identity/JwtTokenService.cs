using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Text;
using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Infrastructure.Options;
using Microsoft.AspNetCore.Identity;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Tokens;

namespace Ariant.OfficeAutomation.Infrastructure.Identity;

/// <inheritdoc />
public sealed class JwtTokenService : IJwtTokenService
{
    private readonly UserManager<ApplicationUser> _userManager;
    private readonly JwtOptions _options;

    /// <summary>ساخت سرویس.</summary>
    public JwtTokenService(UserManager<ApplicationUser> userManager, IOptions<JwtOptions> options)
    {
        _userManager = userManager;
        _options = options.Value;
    }

    /// <inheritdoc />
    public async Task<TokenResultDto> CreateAsync(Guid userId, CancellationToken cancellationToken = default)
    {
        cancellationToken.ThrowIfCancellationRequested();

        ApplicationUser user = await _userManager.FindByIdAsync(userId.ToString())
            ?? throw new InvalidOperationException($"کاربر با شناسه {userId} یافت نشد.");

        IList<string> roles = await _userManager.GetRolesAsync(user);

        var claims = new List<Claim>
        {
            new(JwtRegisteredClaimNames.Sub, user.Id.ToString()),
            new(ClaimTypes.NameIdentifier, user.Id.ToString()),
            new(ClaimTypes.Name, user.UserName ?? string.Empty),
            new(JwtRegisteredClaimNames.Jti, Guid.CreateVersion7().ToString()),
            new(ClaimTypes.Email, user.Email ?? string.Empty),
            new("fullName", user.FullName)
        };

        claims.AddRange(roles.Select(role => new Claim(ClaimTypes.Role, role)));

        byte[] keyBytes = Encoding.UTF8.GetBytes(_options.SigningKey);
        var credentials = new SigningCredentials(new SymmetricSecurityKey(keyBytes), SecurityAlgorithms.HmacSha256);

        DateTime issuedAt = DateTime.UtcNow;
        DateTime expiresAt = issuedAt.AddMinutes(_options.ExpiryMinutes <= 0 ? 480 : _options.ExpiryMinutes);

        var token = new JwtSecurityToken(
            issuer: _options.Issuer,
            audience: _options.Audience,
            claims: claims,
            notBefore: issuedAt,
            expires: expiresAt,
            signingCredentials: credentials);

        return new TokenResultDto
        {
            AccessToken = new JwtSecurityTokenHandler().WriteToken(token),
            ExpiresAtUtc = expiresAt
        };
    }
}
