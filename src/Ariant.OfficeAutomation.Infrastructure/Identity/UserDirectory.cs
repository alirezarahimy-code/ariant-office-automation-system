using Ariant.OfficeAutomation.Application.Abstractions;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;

namespace Ariant.OfficeAutomation.Infrastructure.Identity;

/// <inheritdoc />
public sealed class UserDirectory : IUserDirectory
{
    private readonly UserManager<ApplicationUser> _userManager;

    /// <summary>ساخت سرویس.</summary>
    public UserDirectory(UserManager<ApplicationUser> userManager)
        => _userManager = userManager;

    /// <inheritdoc />
    public Task<int> CountAsync(CancellationToken cancellationToken = default)
        => _userManager.Users.CountAsync(cancellationToken);

    /// <inheritdoc />
    public Task<int> CountActiveAsync(CancellationToken cancellationToken = default)
        => _userManager.Users.CountAsync(u => u.IsActive, cancellationToken);

    /// <inheritdoc />
    public async Task<IReadOnlyList<UserSummaryDto>> GetRecentAsync(int take, CancellationToken cancellationToken = default)
    {
        List<ApplicationUser> users = await _userManager.Users
            .AsNoTracking()
            .OrderByDescending(u => u.LastLoginAtUtc ?? u.CreatedAtUtc)
            .Take(take <= 0 ? 10 : take)
            .ToListAsync(cancellationToken);

        var result = new List<UserSummaryDto>(users.Count);

        foreach (ApplicationUser user in users)
        {
            IList<string> roles = await _userManager.GetRolesAsync(user);

            result.Add(new UserSummaryDto
            {
                Id = user.Id,
                UserName = user.UserName ?? string.Empty,
                FullName = user.FullName,
                Email = user.Email,
                IsActive = user.IsActive,
                LastLoginAtUtc = user.LastLoginAtUtc,
                Roles = roles.ToList()
            });
        }

        return result;
    }
}
