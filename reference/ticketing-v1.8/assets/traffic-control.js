(function () {
    'use strict';

    var codeField = document.getElementById('traffic-national-code');
    var hint = document.getElementById('traffic-lookup-hint');
    var quickExitForm = document.getElementById('traffic-quick-exit-form');
    var quickExitId = document.getElementById('traffic-quick-exit-id');
    if (!codeField || !hint) {
        return;
    }

    var fields = {
        full_name: document.getElementById('traffic-full-name'),
        phone: document.getElementById('traffic-phone'),
        company: document.getElementById('traffic-company'),
        meeting_with: document.getElementById('traffic-meeting-with'),
        approved_by: document.getElementById('traffic-approved-by'),
    };

    function setHint(html, kind) {
        hint.className = 'alert ' + (kind || 'info');
        hint.innerHTML = html;
        hint.hidden = false;
    }

    function clearHint() {
        hint.hidden = true;
        hint.innerHTML = '';
    }

    function lookup() {
        var code = codeField.value.replace(/[^0-9]/g, '');
        if (code.length !== 10) {
            clearHint();
            return;
        }
        fetch('index.php?page=traffic-control&traffic_api=lookup&code=' + encodeURIComponent(code), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.valid) {
                    setHint('کد ملی وارد شده معتبر نیست.', 'danger');
                    return;
                }
                if (!data.found) {
                    setHint('مهمان جدید — لطفاً بقیه‌ی اطلاعات را کامل وارد کنید.', 'info');
                    return;
                }
                if (data.needs_exit_only) {
                    setHint(
                        'این مراجعه‌کننده امروز قبلاً ثبت شده و ساعت خروج ندارد (' + data.last_visit_label + ').' +
                        ' <button type="button" class="mini-button" id="traffic-quick-exit-btn">فقط ثبت ساعت خروج</button>' +
                        ' یا برای ثبت یک تردد جدید همین فرم را کامل و ذخیره کنید.',
                        'info'
                    );
                    if (quickExitForm && quickExitId) {
                        quickExitId.value = data.record_id;
                        var btn = document.getElementById('traffic-quick-exit-btn');
                        if (btn) {
                            btn.addEventListener('click', function () { quickExitForm.submit(); });
                        }
                    }
                } else {
                    setHint('آخرین تردد: ' + data.last_visit_label + '. اطلاعات پیشنهادی پر شد؛ در صورت نیاز اصلاح کنید.', 'success');
                }
                if (fields.full_name && !fields.full_name.value) fields.full_name.value = data.full_name || '';
                if (fields.phone && !fields.phone.value) fields.phone.value = data.phone || '';
                if (fields.company && !fields.company.value) fields.company.value = data.company || '';
                if (fields.meeting_with && data.meeting_with) fields.meeting_with.value = data.meeting_with;
                if (fields.approved_by && !fields.approved_by.value) fields.approved_by.value = data.approved_by || '';
            })
            .catch(function () { clearHint(); });
    }

    codeField.addEventListener('blur', lookup);
    codeField.addEventListener('input', function () {
        if (codeField.value.replace(/[^0-9]/g, '').length === 10) {
            lookup();
        }
    });
}());

(function () {
    'use strict';
    var form = document.getElementById('traffic-report-form');
    var all = document.getElementById('traffic-report-all');
    if (!form || !all) return;

    var destinations = Array.prototype.slice.call(form.querySelectorAll('input[name="destinations[]"]'));
    all.addEventListener('change', function () {
        all.setCustomValidity('');
        destinations.forEach(function (input) { input.checked = all.checked; });
    });
    destinations.forEach(function (input) {
        input.addEventListener('change', function () {
            all.setCustomValidity('');
            if (!input.checked) all.checked = false;
            else all.checked = destinations.length > 0 && destinations.every(function (item) { return item.checked; });
        });
    });
    form.addEventListener('submit', function (event) {
        if (all.checked || destinations.some(function (input) { return input.checked; })) return;
        event.preventDefault();
        all.setCustomValidity('یک مقصد را انتخاب کنید یا گزینهٔ «همه» را علامت بزنید.');
        all.reportValidity();
    });
}());

/* جست‌وجوی آنی در سوابق تردد: به‌محض تایپ، ردیف‌های غیرمرتبط مخفی می‌شوند. */
(function () {
    var input = document.getElementById('traffic-history-search');
    var table = document.getElementById('traffic-history-table');
    if (!input || !table) return;
    var rows = table.querySelectorAll('[data-traffic-row]');
    var empty = document.getElementById('traffic-history-empty');
    function normalize(value) {
        return (value || '')
            .toString()
            .replace(/ي/g, 'ی')
            .replace(/ك/g, 'ک')
            .replace(/[۰-۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); })
            .replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); })
            .toLowerCase()
            .trim();
    }
    function apply() {
        var query = normalize(input.value);
        var visible = 0;
        Array.prototype.forEach.call(rows, function (row) {
            var match = query === '' || normalize(row.getAttribute('data-search') || '').indexOf(query) !== -1;
            row.hidden = !match;
            if (match) visible++;
        });
        if (empty) empty.hidden = visible !== 0 || rows.length === 0;
    }
    input.addEventListener('input', apply);
    apply();
}());

(function () {
    'use strict';
    var alertBox = document.querySelector('[data-focus-form], #traffic-form-error');
    if (!alertBox) {
        return;
    }
    var formId = alertBox.getAttribute('data-focus-form');
    var name = alertBox.getAttribute('data-focus-field');
    var form = formId ? document.getElementById(formId) : null;
    var field = form && name ? form.querySelector('[name="' + name + '"]') : null;
    if (alertBox.scrollIntoView) {
        alertBox.scrollIntoView({ block: 'center' });
    }
    if (field) {
        field.focus();
        if (field.select) {
            field.select();
        }
    } else if (alertBox.focus) {
        alertBox.focus();
    }
})();
