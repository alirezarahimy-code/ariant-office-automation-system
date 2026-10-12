using System.Globalization;
using System.Text.Encodings.Web;
using Ariant.OfficeAutomation.Application;
using Ariant.OfficeAutomation.Common;
using Ariant.OfficeAutomation.Infrastructure;
using Ariant.OfficeAutomation.Infrastructure.Persistence;
using Ariant.OfficeAutomation.Web.Infrastructure;
using Microsoft.AspNetCore.Localization;
using Microsoft.AspNetCore.Mvc;

WebApplicationBuilder builder = WebApplication.CreateBuilder(args);

// ---------------------------------------------------------------------------
// سرویس‌ها
// ---------------------------------------------------------------------------
builder.Services.AddControllersWithViews(options =>
    {
        // پشتیبانی از تاریخ شمسی در فرم‌ها (۱۴۰۴/۰۷/۲۰)
        options.ModelBinderProviders.Insert(0, new PersianDateModelBinderProvider());
    })
    .AddJsonOptions(options =>
    {
        options.JsonSerializerOptions.Converters.Add(new PersianDateJsonConverter());
        options.JsonSerializerOptions.Converters.Add(new NullablePersianDateJsonConverter());
        // خروجی JSON با نویسه‌های فارسی خوانا (نه \u06XX)
        options.JsonSerializerOptions.Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping;
    });

builder.Services.AddHttpContextAccessor();
builder.Services.AddApplication();
builder.Services.AddInfrastructure(builder.Configuration);

// فرهنگ فارسی برای کل برنامه
var persianCulture = new CultureInfo("fa-IR");
builder.Services.Configure<RequestLocalizationOptions>(options =>
{
    options.DefaultRequestCulture = new RequestCulture(persianCulture);
    options.SupportedCultures = new[] { persianCulture };
    options.SupportedUICultures = new[] { persianCulture };
});

WebApplication app = builder.Build();

// ---------------------------------------------------------------------------
// آماده‌سازی پایگاه‌داده (ساخت جداول، نقش‌ها، کاربر مدیر و داده نمونه)
// ---------------------------------------------------------------------------
try
{
    await DbInitializer.InitializeAsync(app.Services);
}
catch (Exception exception)
{
    app.Logger.LogCritical(exception, "آماده‌سازی پایگاه‌داده ناموفق بود. اتصال پایگاه‌داده را بررسی کنید.");
    throw;
}

// ---------------------------------------------------------------------------
// میان‌افزارها
// ---------------------------------------------------------------------------
if (!app.Environment.IsDevelopment())
{
    app.UseExceptionHandler("/Home/Error");
    app.UseHsts();
    app.UseHttpsRedirection();
}

app.UseStaticFiles();
app.UseRequestLocalization();
app.UseRouting();

app.UseAuthentication();
app.UseAuthorization();

app.MapControllerRoute(
    name: "default",
    pattern: "{controller=Home}/{action=Index}/{id?}");

app.MapControllers();

app.Run();
