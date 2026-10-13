using Ariant.OfficeAutomation.Common;
using Ariant.OfficeAutomation.Domain.Organization;
using Ariant.OfficeAutomation.Domain.Security;
using Ariant.OfficeAutomation.Infrastructure.Identity;
using Ariant.OfficeAutomation.Infrastructure.Options;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;

namespace Ariant.OfficeAutomation.Infrastructure.Persistence;

/// <summary>
/// آماده‌سازی پایگاه‌داده در نخستین اجرا: ساخت جداول، نقش‌ها، کاربر مدیر،
/// و داده‌های نمونه ساختار سازمانی.
/// </summary>
public static class DbInitializer
{
    /// <summary>اجرای آماده‌سازی.</summary>
    public static async Task InitializeAsync(IServiceProvider serviceProvider, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(serviceProvider);

        using IServiceScope scope = serviceProvider.CreateScope();
        IServiceProvider services = scope.ServiceProvider;

        ILogger logger = services.GetRequiredService<ILoggerFactory>().CreateLogger("DbInitializer");
        IConfiguration configuration = services.GetRequiredService<IConfiguration>();
        AppDbContext context = services.GetRequiredService<AppDbContext>();

        DatabaseOptions databaseOptions = configuration
            .GetSection(DatabaseOptions.SectionName)
            .Get<DatabaseOptions>() ?? new DatabaseOptions();

        if (databaseOptions.UseMigrations)
        {
            logger.LogInformation("اعمال مهاجرت‌های EF Core …");
            await context.Database.MigrateAsync(cancellationToken);
        }
        else
        {
            logger.LogInformation("ساخت پایگاه‌داده با EnsureCreated (حالت توسعه) …");
            await context.Database.EnsureCreatedAsync(cancellationToken);
        }

        await SeedSecurityAsync(services, configuration, logger, cancellationToken);

        if (databaseOptions.SeedSampleData)
            await SeedOrganizationAsync(context, logger, cancellationToken);
    }

    private static async Task SeedSecurityAsync(
        IServiceProvider services,
        IConfiguration configuration,
        ILogger logger,
        CancellationToken cancellationToken)
    {
        cancellationToken.ThrowIfCancellationRequested();

        RoleManager<ApplicationRole> roleManager = services.GetRequiredService<RoleManager<ApplicationRole>>();

        foreach (string roleName in AppRoles.All)
        {
            if (await roleManager.RoleExistsAsync(roleName))
                continue;

            IdentityResult roleResult = await roleManager.CreateAsync(new ApplicationRole(roleName)
            {
                Title = AppRoles.GetTitle(roleName)
            });

            if (roleResult.Succeeded)
                logger.LogInformation("نقش «{RoleName}» ایجاد شد.", roleName);
            else
                logger.LogWarning("ایجاد نقش {RoleName} ناموفق بود: {Errors}", roleName, Describe(roleResult));
        }

        SeedOptions seedOptions = configuration.GetSection(SeedOptions.SectionName).Get<SeedOptions>() ?? new SeedOptions();
        UserManager<ApplicationUser> userManager = services.GetRequiredService<UserManager<ApplicationUser>>();

        ApplicationUser? admin = await userManager.FindByNameAsync(seedOptions.AdminUserName);
        if (admin is not null)
            return;

        var newAdmin = new ApplicationUser
        {
            UserName = seedOptions.AdminUserName,
            Email = seedOptions.AdminEmail,
            EmailConfirmed = true,
            FirstName = seedOptions.AdminFirstName,
            LastName = seedOptions.AdminLastName,
            IsActive = true,
            MustChangePassword = true
        };

        IdentityResult result = await userManager.CreateAsync(newAdmin, seedOptions.AdminPassword);

        if (!result.Succeeded)
        {
            logger.LogWarning("ایجاد کاربر مدیر ناموفق بود: {Errors}", Describe(result));
            return;
        }

        await userManager.AddToRoleAsync(newAdmin, AppRoles.Admin);
        logger.LogInformation(
            "کاربر مدیر با نام کاربری «{UserName}» ساخته شد. گذرواژه پیش‌فرض را در نخستین ورود تغییر دهید.",
            seedOptions.AdminUserName);
    }

    private static async Task SeedOrganizationAsync(AppDbContext context, ILogger logger, CancellationToken cancellationToken)
    {
        if (await context.Departments.AnyAsync(cancellationToken))
            return;

        var headOffice = new Department { Title = "دفتر مدیریت", Code = "100", SortOrder = 1, Description = "دفتر مدیرعامل" };
        var deputy = new Department { Title = "معاونت مالی و اداری", Code = "200", SortOrder = 2 };
        var itUnit = new Department { Title = "واحد فناوری اطلاعات", Code = "201", SortOrder = 3 };
        var secretariat = new Department { Title = "دبیرخانه", Code = "202", SortOrder = 4 };
        var humanResources = new Department { Title = "منابع انسانی", Code = "203", SortOrder = 5 };
        var accounting = new Department { Title = "حسابداری", Code = "204", SortOrder = 6 };
        var procurement = new Department { Title = "کارپردازی", Code = "205", SortOrder = 7 };

        context.Departments.AddRange(headOffice, deputy, itUnit, secretariat, humanResources, accounting, procurement);
        await context.SaveChangesAsync(cancellationToken);

        // زیرمجموعه‌ها پس از ذخیره، تا شناسه واحدهای بالادستی مشخص باشد
        context.Departments.AddRange(
            new Department { Title = "واحد پشتیبانی فناوری", Code = "2011", ParentId = itUnit.Id, SortOrder = 1 },
            new Department { Title = "واحد شبکه و زیرساخت", Code = "2012", ParentId = itUnit.Id, SortOrder = 2 },
            new Department { Title = "واحد آموزش و توسعه", Code = "2031", ParentId = humanResources.Id, SortOrder = 1 });

        context.Employees.AddRange(
            new Employee
            {
                FirstName = "علی", LastName = "رضایی", PersonnelCode = "1001", NationalCode = "0012345671",
                JobTitle = "مسئول دبیرخانه", DepartmentId = secretariat.Id, MobileNumber = "09120000001",
                HireDateUtc = new PersianDate(1395, 4, 15).ToDateTime(), EmploymentType = EmploymentType.FullTime
            },
            new Employee
            {
                FirstName = "مریم", LastName = "محمدی", PersonnelCode = "1002", NationalCode = "0012345672",
                JobTitle = "کارشناس منابع انسانی", DepartmentId = humanResources.Id, MobileNumber = "09120000002",
                HireDateUtc = new PersianDate(1397, 7, 1).ToDateTime(), EmploymentType = EmploymentType.FullTime
            },
            new Employee
            {
                FirstName = "حسین", LastName = "کریمی", PersonnelCode = "1003", NationalCode = "0012345673",
                JobTitle = "کارشناس فناوری اطلاعات", DepartmentId = itUnit.Id, MobileNumber = "09120000003",
                HireDateUtc = new PersianDate(1399, 2, 10).ToDateTime(), EmploymentType = EmploymentType.FullTime
            },
            new Employee
            {
                FirstName = "زهرا", LastName = "حسینی", PersonnelCode = "1004", NationalCode = "0012345674",
                JobTitle = "حسابدار", DepartmentId = accounting.Id, MobileNumber = "09120000004",
                HireDateUtc = new PersianDate(1400, 11, 20).ToDateTime(), EmploymentType = EmploymentType.PartTime
            },
            new Employee
            {
                FirstName = "رضا", LastName = "مرادی", PersonnelCode = "1005", NationalCode = "0012345675",
                JobTitle = "کارپرداز", DepartmentId = procurement.Id, MobileNumber = "09120000005",
                HireDateUtc = new PersianDate(1402, 3, 1).ToDateTime(), EmploymentType = EmploymentType.Contract
            });

        await context.SaveChangesAsync(cancellationToken);
        logger.LogInformation("داده‌های نمونه ساختار سازمانی و کارمندان درج شد.");
    }

    private static string Describe(IdentityResult result)
        => string.Join(" | ", result.Errors.Select(error => $"{error.Code}: {error.Description}"));
}
