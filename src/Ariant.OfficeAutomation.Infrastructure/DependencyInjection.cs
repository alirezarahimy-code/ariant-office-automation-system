using System.Security.Claims;
using System.Text;
using Ariant.OfficeAutomation.Application.Abstractions;
using Ariant.OfficeAutomation.Domain.Security;
using Ariant.OfficeAutomation.Infrastructure.Identity;
using Ariant.OfficeAutomation.Infrastructure.Options;
using Ariant.OfficeAutomation.Infrastructure.Persistence;
using Ariant.OfficeAutomation.Infrastructure.Persistence.Interceptors;
using Ariant.OfficeAutomation.Infrastructure.Time;
using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.IdentityModel.Tokens;

namespace Ariant.OfficeAutomation.Infrastructure;

/// <summary>ثبت سرویس‌های لایه زیرساخت در ظرف وابستگی‌ها.</summary>
public static class DependencyInjection
{
    /// <summary>افزودن سرویس‌های زیرساخت: پایگاه‌داده، هویت، نشانه دسترسی و مجوزها.</summary>
    public static IServiceCollection AddInfrastructure(this IServiceCollection services, IConfiguration configuration)
    {
        ArgumentNullException.ThrowIfNull(services);
        ArgumentNullException.ThrowIfNull(configuration);

        services.Configure<DatabaseOptions>(configuration.GetSection(DatabaseOptions.SectionName));
        services.Configure<JwtOptions>(configuration.GetSection(JwtOptions.SectionName));
        services.Configure<SeedOptions>(configuration.GetSection(SeedOptions.SectionName));

        services.AddHttpContextAccessor();
        services.AddSingleton<IDateTimeProvider, SystemDateTimeProvider>();
        services.AddScoped<ICurrentUserService, CurrentUserService>();
        services.AddScoped<AuditableEntityInterceptor>();
        services.AddScoped<IUserDirectory, UserDirectory>();

        AddDatabase(services, configuration);
        AddIdentityAndAuthentication(services, configuration);

        return services;
    }

    private static void AddDatabase(IServiceCollection services, IConfiguration configuration)
    {
        DatabaseOptions databaseOptions = configuration
            .GetSection(DatabaseOptions.SectionName)
            .Get<DatabaseOptions>() ?? new DatabaseOptions();

        string connectionString = configuration.GetConnectionString("Default")
            ?? $"Data Source={Path.Combine(AppContext.BaseDirectory, "ariant.db")}";

        services.AddDbContext<AppDbContext>((serviceProvider, optionsBuilder) =>
        {
            string migrationsAssembly = typeof(AppDbContext).Assembly.GetName().Name ?? "Ariant.OfficeAutomation.Infrastructure";

            switch (databaseOptions.Provider.Trim().ToLowerInvariant())
            {
                case "sqlserver":
                case "mssql":
                    optionsBuilder.UseSqlServer(connectionString, sqlOptions =>
                    {
                        sqlOptions.MigrationsAssembly(migrationsAssembly);
                        sqlOptions.EnableRetryOnFailure(3);
                    });
                    break;

                case "sqlite":
                default:
                    optionsBuilder.UseSqlite(connectionString, sqliteOptions =>
                        sqliteOptions.MigrationsAssembly(migrationsAssembly));
                    break;
            }

            optionsBuilder.AddInterceptors(serviceProvider.GetRequiredService<AuditableEntityInterceptor>());

            if (databaseOptions.EnableDetailedErrors)
                optionsBuilder.EnableDetailedErrors();
        });

        // لایه کاربرد فقط انتزاع IAppDbContext را می‌شناسد
        services.AddScoped<IAppDbContext>(provider => provider.GetRequiredService<AppDbContext>());
    }

    private static void AddIdentityAndAuthentication(IServiceCollection services, IConfiguration configuration)
    {
        services.AddIdentity<ApplicationUser, ApplicationRole>(options =>
            {
                options.Password.RequiredLength = 8;
                options.Password.RequireDigit = true;
                options.Password.RequireLowercase = true;
                options.Password.RequireUppercase = true;
                options.Password.RequireNonAlphanumeric = true;

                options.User.RequireUniqueEmail = true;
                options.SignIn.RequireConfirmedAccount = false;

                options.Lockout.MaxFailedAccessAttempts = 5;
                options.Lockout.DefaultLockoutTimeSpan = TimeSpan.FromMinutes(15);
                options.Lockout.AllowedForNewUsers = true;
            })
            .AddEntityFrameworkStores<AppDbContext>()
            .AddErrorDescriber<PersianIdentityErrorDescriber>()
            .AddDefaultTokenProviders();

        services.ConfigureApplicationCookie(options =>
        {
            options.Cookie.Name = "Ariant.OfficeAutomation.Auth";
            options.Cookie.HttpOnly = true;
            options.Cookie.SameSite = SameSiteMode.Lax;
            options.LoginPath = "/Account/Login";
            options.LogoutPath = "/Account/Logout";
            options.AccessDeniedPath = "/Account/AccessDenied";
            options.ExpireTimeSpan = TimeSpan.FromHours(8);
            options.SlidingExpiration = true;
        });

        JwtOptions jwtOptions = configuration.GetSection(JwtOptions.SectionName).Get<JwtOptions>() ?? new JwtOptions();

        // نشانه دسترسی برای وب‌سرویس و پل ارتباطی با سامانه‌های دیگر (مثل سامانه تیکتینگ PHP)
        if (!string.IsNullOrWhiteSpace(jwtOptions.SigningKey))
        {
            services.AddAuthentication()
                .AddJwtBearer(JwtBearerDefaults.AuthenticationScheme, options =>
                {
                    options.TokenValidationParameters = new TokenValidationParameters
                    {
                        ValidateIssuer = true,
                        ValidIssuer = jwtOptions.Issuer,
                        ValidateAudience = true,
                        ValidAudience = jwtOptions.Audience,
                        ValidateIssuerSigningKey = true,
                        IssuerSigningKey = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(jwtOptions.SigningKey)),
                        ValidateLifetime = true,
                        ClockSkew = TimeSpan.FromMinutes(1),
                        NameClaimType = ClaimTypes.Name,
                        RoleClaimType = ClaimTypes.Role
                    };
                });
        }

        services.AddAuthorization(options =>
        {
            options.AddPolicy(AppPolicies.ManageOrganization,
                policy => policy.RequireRole(AppRoles.Admin, AppRoles.Manager));

            options.AddPolicy(AppPolicies.ViewOrganization,
                policy => policy.RequireRole(AppRoles.Admin, AppRoles.Manager, AppRoles.Employee));

            options.AddPolicy(AppPolicies.ViewAuditLog,
                policy => policy.RequireRole(AppRoles.Admin));

            options.AddPolicy(AppPolicies.ManageUsers,
                policy => policy.RequireRole(AppRoles.Admin));

            options.AddPolicy(AppPolicies.ApiAccess,
                policy => policy.RequireAuthenticatedUser());
        });
    }
}
