using Ariant.OfficeAutomation.Common;
using Microsoft.AspNetCore.Mvc.ModelBinding;

namespace Ariant.OfficeAutomation.Web.Infrastructure;

/// <summary>
/// اتصال‌دهنده مدل (Model Binder) برای <see cref="PersianDate"/>.
/// به این ترتیب فرم‌ها می‌توانند تاریخ را به شکل «۱۴۰۴/۰۷/۲۰» بفرستند و
/// به‌طور خودکار به نوع شمسی تبدیل شود.
/// </summary>
public sealed class PersianDateModelBinder : IModelBinder
{
    /// <inheritdoc />
    public Task BindModelAsync(ModelBindingContext bindingContext)
    {
        ArgumentNullException.ThrowIfNull(bindingContext);

        ValueProviderResult valueResult = bindingContext.ValueProvider.GetValue(bindingContext.ModelName);

        bool isNullable = Nullable.GetUnderlyingType(bindingContext.ModelType) is not null;

        if (valueResult == ValueProviderResult.None)
        {
            // مقداری ارسال نشده است: برای نوعی که مقدار خالی می‌پذیرد مشکلی نیست، در غیر این صورت خطای اعتبارسنجی
            if (isNullable)
            {
                bindingContext.Result = ModelBindingResult.Success(null);
            }
            else
            {
                bindingContext.ModelState.AddModelError(bindingContext.ModelName, "ورود تاریخ الزامی است.");
                bindingContext.Result = ModelBindingResult.Failed();
            }

            return Task.CompletedTask;
        }

        bindingContext.ModelState.SetModelValue(bindingContext.ModelName, valueResult);

        string? rawValue = valueResult.FirstValue;

        // مقدار خالی: برای نوع پذیرنده null مجاز است، در غیر این صورت خطا
        if (string.IsNullOrWhiteSpace(rawValue))
        {
            if (isNullable)
            {
                bindingContext.Result = ModelBindingResult.Success(null);
                return Task.CompletedTask;
            }

            bindingContext.ModelState.AddModelError(bindingContext.ModelName, "ورود تاریخ الزامی است.");
            bindingContext.Result = ModelBindingResult.Failed();
            return Task.CompletedTask;
        }

        if (PersianDate.TryParse(rawValue, out PersianDate value))
        {
            bindingContext.Result = ModelBindingResult.Success(value);
            return Task.CompletedTask;
        }

        bindingContext.ModelState.AddModelError(
            bindingContext.ModelName,
            $"تاریخ وارد‌شده «{rawValue}» معتبر نیست. نمونه درست: ۱۴۰۴/۰۷/۲۰");

        bindingContext.Result = ModelBindingResult.Failed();
        return Task.CompletedTask;
    }
}

/// <summary>معرف اتصال‌دهنده مدل تاریخ شمسی.</summary>
public sealed class PersianDateModelBinderProvider : IModelBinderProvider
{
    /// <inheritdoc />
    public IModelBinder? GetBinder(ModelBinderProviderContext context)
    {
        ArgumentNullException.ThrowIfNull(context);

        Type modelType = context.Metadata.ModelType;

        return modelType == typeof(PersianDate) || modelType == typeof(PersianDate?)
            ? new PersianDateModelBinder()
            : null;
    }
}
