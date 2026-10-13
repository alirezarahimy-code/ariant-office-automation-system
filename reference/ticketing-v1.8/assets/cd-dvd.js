document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || typeof target.closest !== 'function') {
        return;
    }
    if (target.closest('[data-print-records]')) {
        event.preventDefault();
        if (typeof printCdDvdHistory === 'function') {
            printCdDvdHistory();
        }
        return;
    }
    if (target.closest('[data-print-page]')) {
        event.preventDefault();
        var report = document.querySelector('.cd-dvd-table, .cd-dvd-surface-table, .cd-dvd-record-table');
        if (report) printCdDvdList(report);
    }
});

(function () {
    'use strict';

    const media = document.getElementById('cd-dvd-media');
    const direction = document.getElementById('cd-dvd-direction');
    const serial = document.getElementById('cd-dvd-serial');
    const serialFull = document.getElementById('cd-dvd-serial-full');
    const serialManual = document.getElementById('cd-dvd-serial-manual');
    const serialOutWrap = document.querySelector('.cd-dvd-serial-out');
    const serialHelp = document.getElementById('cd-dvd-serial-help');
    const exitSheetField = document.querySelector('.cd-dvd-exit-sheet-field');
    const incomingField = document.querySelector('.cd-dvd-incoming-field');
    const broughtBy = document.getElementById('cd-dvd-brought-by');
    const broughtById = document.getElementById('cd-dvd-brought-by-id');
    const userPicker = document.querySelector('[data-user-picker]');
    const userOptions = userPicker ? userPicker.querySelector('.cd-dvd-user-options') : null;
    const nextSerial = window.CD_DVD_NEXT || {};
    let lastSelectedMedia = media ? media.value : ((document.querySelector('input[name="media"]:checked') || {}).value || 'CD');

    function normalize(value) {
        return String(value || '').toLocaleLowerCase('fa').trim();
    }

    function filterUsers() {
        if (!userOptions || !broughtBy) {
            return;
        }
        const search = normalize(broughtBy.value);
        userOptions.hidden = false;
        userOptions.querySelectorAll('.cd-dvd-user-option').forEach(function (option) {
            const optionSearch = normalize(option.dataset.search || option.textContent);
            option.hidden = option.classList.contains('external') ? search !== '' && !normalize('خارج').includes(search) : (search !== '' && !optionSearch.includes(search));
        });
    }

    function selectUser(option) {
        if (!broughtBy || !broughtById) {
            return;
        }
        broughtById.value = option.dataset.userId || '0';
        if (option.dataset.userId === '-1') {
            broughtBy.value = '';
            broughtBy.readOnly = false;
            broughtBy.placeholder = 'نام شخص خارج از سامانه';
        } else {
            broughtBy.value = option.dataset.label || option.textContent.trim();
            broughtBy.readOnly = true;
            broughtBy.placeholder = 'انتخاب کاربر';
        }
        if (userOptions) {
            userOptions.hidden = true;
        }
    }

    function initializeUserPicker() {
        if (!userPicker || !userOptions || !broughtBy || !broughtById) {
            return;
        }
        const selected = userOptions.querySelector('[data-user-id="' + broughtById.value + '"]');
        if (selected && broughtById.value !== '-1') {
            broughtBy.readOnly = true;
        }
        broughtBy.addEventListener('focus', filterUsers);
        broughtBy.addEventListener('click', function () {
            if (broughtBy.readOnly) {
                broughtBy.value = '';
                broughtBy.readOnly = false;
                broughtById.value = '0';
                filterUsers();
            }
        });
        broughtBy.addEventListener('input', function () {
            if (broughtBy.readOnly) {
                broughtBy.readOnly = false;
                broughtById.value = '0';
            }
            filterUsers();
        });
        userOptions.querySelectorAll('.cd-dvd-user-option').forEach(function (option) {
            option.addEventListener('click', function () {
                selectUser(option);
            });
        });
        document.addEventListener('click', function (event) {
            if (!userPicker.contains(event.target)) {
                userOptions.hidden = true;
            }
        });
    }

    function getSelectedMedia() {
        return media ? media.value : ((document.querySelector('input[name="media"]:checked') || {}).value || 'CD');
    }

    function mediaBounds(value) {
        return value === 'DVD' ? { min: 1001, max: 2000, label: 'DVD: P86-1001 تا P86-2000' } : { min: 1, max: 1000, label: 'CD: P86-0001 تا P86-1000' };
    }

    function updateForm() {
        const isIncoming = direction && direction.value === 'IN';
        const selectedMedia = getSelectedMedia();
        const mediaChanged = selectedMedia !== lastSelectedMedia;
        const bounds = mediaBounds(selectedMedia);

        if (incomingField) {
            incomingField.hidden = !isIncoming;
        }
        if (broughtBy) {
            broughtBy.required = isIncoming;
        }
        if (exitSheetField) {
            exitSheetField.hidden = isIncoming;
        }
        if (serialOutWrap) {
            serialOutWrap.hidden = isIncoming;
        }
        if (serialManual) {
            serialManual.hidden = !isIncoming;
            serialManual.required = isIncoming;
        }
        if (serialHelp) {
            serialHelp.textContent = isIncoming
                ? 'برای رسانه ورودی از بیرون سازمان، شماره را همان‌طور که روی رسانه درج شده دستی وارد کنید.'
                : bounds.label;
        }

        if (isIncoming) {
            // شماره ورودی دستی است؛ مقدار خودکار P86 نباید وارد شود.
            if (serial && !serialManual.hidden) {
                serial.required = false;
            }
            if (serialManual && serialManual.value.trim() === '' && serial && serial.value.trim() !== '' && mediaChanged) {
                serial.value = '';
            }
            if (serialFull) {
                serialFull.value = serialManual ? serialManual.value.trim() : '';
            }
        } else if (serial) {
            serial.required = false;
            const numeric = String(serial.value || '').replace(/[^0-9]/g, '').slice(-4);
            const next = String(nextSerial[selectedMedia] || String(bounds.min).padStart(4, '0')).replace(/^P86-/, '');
            const rangeIsValid = numeric !== '' && Number(numeric) >= bounds.min && Number(numeric) <= bounds.max;
            serial.min = String(bounds.min);
            serial.max = String(bounds.max);
            serial.placeholder = String(bounds.min).padStart(4, '0');
            if ((mediaChanged || !numeric || !rangeIsValid) && next) {
                serial.value = next;
            } else {
                serial.value = numeric;
            }
            if (serialFull) {
                const finalNumeric = String(serial.value || '').replace(/[^0-9]/g, '').slice(-4);
                const finalValid = finalNumeric !== '' && Number(finalNumeric) >= bounds.min && Number(finalNumeric) <= bounds.max;
                serialFull.value = finalValid ? 'P86-' + finalNumeric.padStart(4, '0') : '';
            }
        }
        lastSelectedMedia = selectedMedia;
    }

    const handleMediaChange = function () {
        lastSelectedMedia = '';
        updateForm();
    };
    const form = serial ? serial.closest('form') : null;
    if (form) {
        form.noValidate = true;
        form.addEventListener('submit', function (event) {
            const emptyField = Array.from(form.querySelectorAll('[required]')).find(function (field) {
                return !field.disabled && !field.closest('[hidden]') && String(field.value || '').trim() === '';
            });
            if (!emptyField) {
                return;
            }
            event.preventDefault();
            const label = emptyField.dataset.requiredLabel || 'نامشخص';
            window.alert('فیلد «' + label + '» خالی است و باید پر شود.');
            emptyField.focus();
        });
        form.addEventListener('change', function (event) {
            if (event.target.matches('input[name="media"], #cd-dvd-media')) {
                handleMediaChange();
            }
        });
    }
    if (serial) {
        serial.addEventListener('input', updateForm);
    }
    if (serialManual) {
        serialManual.addEventListener('input', function () {
            if (serialFull) {
                serialFull.value = serialManual.value.trim();
            }
        });
    }
    if (direction) {
        direction.addEventListener('change', updateForm);
    }
    if (broughtBy && broughtBy.tagName === 'SELECT' && broughtById) {
        broughtBy.addEventListener('change', function () {
            broughtById.value = broughtBy.value || '0';
        });
    }
    initializeUserPicker();
    updateForm();

}());

(function () {
    'use strict';

    const tabLinks = document.querySelectorAll('[data-cd-dvd-tab]');
    const panels = document.querySelectorAll('[data-cd-dvd-panel]');
    if (!tabLinks.length || !panels.length) {
        return;
    }

    function activateTab(name, updateUrl) {
        tabLinks.forEach(function (link) {
            const active = link.dataset.cdDvdTab === name;
            link.classList.toggle('active', active);
            link.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.dataset.cdDvdPanel !== name;
        });
        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', name);
            url.hash = '';
            window.history.replaceState({}, '', url);
        }
    }

    tabLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            activateTab(link.dataset.cdDvdTab, true);
        });
    });

    const selected = Array.from(tabLinks).find(function (link) { return link.classList.contains('active'); });
    activateTab(selected ? selected.dataset.cdDvdTab : 'dashboard', false);
}());

function printCdDvdList(table) {
    if (!table || !document.body || typeof window.print !== 'function') return;
    const report = table.cloneNode(true);
    report.querySelectorAll('.actions').forEach(function (node) { node.remove(); });
    report.querySelectorAll('.table-head, .cd-dvd-record-head').forEach(function (head) {
        const last = head.lastElementChild;
        if (last && /عملیات/.test(last.textContent || '')) last.remove();
    });
    const printHost = document.createElement('div');
    printHost.className = 'cd-dvd-print-host';
    printHost.appendChild(report);
    document.body.appendChild(printHost);
    document.body.classList.add('cd-dvd-print-selected');
    let cleaned = false;
    let cleanupTimer = null;
    function cleanup() {
        if (cleaned) return;
        cleaned = true;
        document.body.classList.remove('cd-dvd-print-selected');
        if (printHost.parentNode) printHost.parentNode.removeChild(printHost);
        if (cleanupTimer !== null) window.clearTimeout(cleanupTimer);
        window.removeEventListener('afterprint', cleanup);
    }
    window.addEventListener('afterprint', cleanup, { once: true });
    try {
        window.print();
    } catch (error) {
        cleanup();
        return;
    }
    if (!cleaned) cleanupTimer = window.setTimeout(cleanup, 30000);
}

function printCdDvdHistory() {
    const table = document.querySelector('#cd-dvd-history .cd-dvd-record-table') || document.querySelector('.cd-dvd-surface-table, .cd-dvd-record-table');
    if (!table) return;
    const printWindow = window.open('', '_blank', 'width=1200,height=800');
    if (!printWindow) {
        printCdDvdList(table);
        return;
    }
    const surfaceTable = table.classList.contains('cd-dvd-surface-table');
    const header = table.querySelector(surfaceTable ? '.cd-dvd-surface-head' : '.cd-dvd-record-head');
    const rowSelector = surfaceTable ? '.cd-dvd-surface-row' : '.cd-dvd-record-row';
    const headers = header ? Array.from(header.children).map(function (cell) { return '<th>' + cell.innerHTML + '</th>'; }).join('') : '';
    const rows = Array.from(table.querySelectorAll(rowSelector)).map(function (row) {
        const cells = surfaceTable ? Array.from(row.children) : Array.from(row.children).slice(0, -1);
        return '<tr>' + cells.map(function (cell) { return '<td>' + cell.innerHTML + '</td>'; }).join('') + '</tr>';
    }).join('');
    printWindow.document.write('<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>سوابق CD/DVD</title><style>body{font-family:Tahoma,Arial,sans-serif;padding:24px;color:#172b4d;text-align:center}h1{font-size:22px;margin:0 0 18px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{border:1px solid #cbd5e1;padding:8px;text-align:center;vertical-align:middle}th{background:#eaf2ff;font-weight:700}td:nth-child(7){max-width:260px;white-space:normal;word-break:break-word}</style></head><body><h1>سوابق کنترل CD/DVD</h1><table><thead><tr>' + headers + '</tr></thead><tbody>' + rows + '</tbody></table></body></html>');
    printWindow.document.close();
    printWindow.onafterprint = function () {
        try { printWindow.close(); } catch (error) { /* ignored */ }
    };
    window.setTimeout(function () {
        try {
            printWindow.focus();
            printWindow.print();
        } catch (error) {
            printCdDvdList(table);
        }
    }, 400);
}
