/* سامانه اتوماسیون اداری آرینت — اسکریپت‌های عمومی */
(function () {
    'use strict';

    var persianDigits = '۰۱۲۳۴۵۶۷۸۹';
    var arabicDigits = '٠١٢٣٤٥٦٧٨٩';

    /** تبدیل ارقام فارسی و عربی به لاتین (برای ارسال درست به سرور) */
    function toLatinDigits(value) {
        var result = '';
        for (var i = 0; i < value.length; i++) {
            var character = value.charAt(i);
            var persianIndex = persianDigits.indexOf(character);
            var arabicIndex = arabicDigits.indexOf(character);

            if (persianIndex >= 0) {
                result += String(persianIndex);
            } else if (arabicIndex >= 0) {
                result += String(arabicIndex);
            } else {
                result += character;
            }
        }
        return result;
    }

    /** تبدیل ارقام لاتین به فارسی (برای نمایش) */
    function toPersianDigits(value) {
        return value.replace(/[0-9]/g, function (digit) {
            return persianDigits.charAt(Number(digit));
        });
    }

    // ---------------------------------------------------------------------
    // ورودی تاریخ شمسی: تبدیل خودکار ارقام و افزودن جداکننده
    // ---------------------------------------------------------------------
    function setupPersianDateInputs() {
        document.querySelectorAll('input.js-persian-date').forEach(function (input) {
            input.setAttribute('dir', 'ltr');
            input.setAttribute('inputmode', 'numeric');
            input.setAttribute('autocomplete', 'off');

            input.addEventListener('focus', function () {
                input.value = toLatinDigits(input.value);
            });

            input.addEventListener('blur', function () {
                var value = toLatinDigits(input.value).replace(/[^0-9]/g, '');

                if (value.length === 8) {
                    input.value = toPersianDigits(value.substring(0, 4) + '/' + value.substring(4, 6) + '/' + value.substring(6, 8));
                } else {
                    input.value = toPersianDigits(input.value);
                }
            });
        });
    }

    // ---------------------------------------------------------------------
    // تأیید پیش از عملیات حساس (حذف)
    // ---------------------------------------------------------------------
    function setupConfirmation() {
        document.querySelectorAll('form.js-confirm').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var message = form.getAttribute('data-confirm') || 'آیا مطمئن هستید؟';
                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    // ---------------------------------------------------------------------
    // بستن خودکار پیام‌ها
    // ---------------------------------------------------------------------
    function setupAlerts() {
        document.querySelectorAll('.alert').forEach(function (alert) {
            window.setTimeout(function () {
                alert.style.transition = 'opacity .4s ease';
                alert.style.opacity = '0';
                window.setTimeout(function () { alert.style.display = 'none'; }, 400);
            }, 6000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupPersianDateInputs();
        setupConfirmation();
        setupAlerts();
    });
})();
