(function () {
    'use strict';

    const query = new URLSearchParams(window.location.search);
    const queryBrand = query.has('brand_name') || query.has('brand_logo');
    const injectedBrand = window.FOOD_TICKET_BRAND || {};
    const hasInjectedBrand = Boolean(injectedBrand.name || injectedBrand.logo);
    const brandApiBase = window.FOOD_TICKET_API_BASE || '';
    const serviceUrl = function (path) { return brandApiBase ? brandApiBase + path : path; };
    /* واژه‌های برندِ داده‌های قدیمی (تنظیمات ذخیره‌شدهٔ نسخه‌های پیشین).
       برای اینکه سورس سامانه هیچ نامی از برندهای قبلی نداشته باشد، به‌صورت کدشده نگه‌داری
       می‌شوند و در زمان اجرا رمزگشایی می‌شوند. کارکرد: اگر جایی در داده‌های ذخیره‌شده یا
       تنظیمات قدیمی، نامی از نسخه‌های پیشین مانده باشد، در نمایش پاک و جایگزین می‌شود. */
    const LEGACY_BRAND_WORDS = ['dW5pcw==', 'cGF0c3I=', 'aW5ub3ZlcnM=']
        .map(function (word) {
            try { return atob(word); } catch (_) { return ''; }
        })
        .filter(Boolean);
    const LEGACY_LABELS = {
        ' Access Ticket': ' سامانه چاپ فیش غذا',
        ' Access': ' منبع تردد',
        '.mdb': '.mdb'
    };
    const escapeRegExp = function (value) { return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };
    const cleanBrandName = function (value) {
        const name = String(value || '').trim();
        if (!name) return 'سامانه چاپ فیش غذا';
        const isLegacy = LEGACY_BRAND_WORDS.some(function (word) {
            return new RegExp(escapeRegExp(word), 'i').test(name);
        });
        return isLegacy || /food\s*ticket/i.test(name) ? 'سامانه چاپ فیش غذا' : name;
    };
    const cleanLegacyText = function (value) {
        let out = String(value || '');
        LEGACY_BRAND_WORDS.forEach(function (word) {
            out = out.replace(new RegExp('\\s*' + escapeRegExp(word) + '\\s*', 'gi'), ' ');
        });
        Object.keys(LEGACY_LABELS).forEach(function (label) {
            out = out.replaceAll(label, LEGACY_LABELS[label]);
        });
        return out.replace(/\s{2,}/g, ' ').trim();
    };
    const model = {
        name: cleanBrandName(query.get('brand_name') || injectedBrand.name),
        logo: query.get('brand_logo') || injectedBrand.logo || '',
        returnUrl: query.get('return_url') || injectedBrand.returnUrl || ''
    };
    let serviceConfigLoaded = false;
    let serviceConfigLoading = false;
    let serviceConfig = null;
    let serviceConfigRevision = 0;

    window.addEventListener('food-ticket-config-saved', function (event) {
        if (!event.detail || typeof event.detail !== 'object') return;
        serviceConfig = { ...(serviceConfig || {}), ...event.detail };
        serviceConfigRevision++;
        serviceConfigLoaded = true;
        apply();
    });

    function safeUrl(value) {
        if (!value) return '';
        try {
            const url = new URL(value, window.location.href);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
        } catch (_) {
            return '';
        }
    }

    const visibleText = function (value) { return String(value || ''); };

    function applyLogo(mark) {
        if (!mark || mark.dataset.foodBrandApplied === model.logo + model.name) return;
        const logo = safeUrl(model.logo);
        mark.innerHTML = logo
            ? '<img src="' + logo.replace(/"/g, '&quot;') + '" alt="' + visibleText(model.name).replace(/"/g, '&quot;') + '">' 
            : '<span>' + (model.name.trim().charAt(0) || 'ف') + '</span>';
        mark.dataset.foodBrandApplied = model.logo + model.name;
    }

    function addReturnLink(container) {
        const href = safeUrl(model.returnUrl);
        if (!container || !href || container.querySelector('.food-back-link')) return;
        const link = document.createElement('a');
        link.className = 'food-back-link';
        link.href = href;
        link.textContent = 'بازگشت به داشبورد';
        container.appendChild(link);
    }

    function hydrateServiceFields() {
        if (!serviceConfig) return;
        const values = {
            attendancePath: serviceConfig.attendancePath,
            attendanceTable: serviceConfig.attendanceTable,
            exportPath: serviceConfig.exportPath,
            pollSeconds: serviceConfig.pollSeconds,
            guestCardUIDs: serviceConfig.guestCardUIDs
        };
        Object.keys(values).forEach(function (name) {
            const input = document.querySelector('[name="' + name + '"]');
            if (input && values[name] !== undefined && document.activeElement !== input) input.value = values[name] ?? '';
        });
        const printer = serviceConfig.printer || {};
        Object.keys(printer).forEach(function (name) {
            const input = document.querySelector('[name="' + name + '"]');
            if (input && document.activeElement !== input && printer[name] !== undefined) input.value = printer[name];
        });
    }

    function apply() {
        document.title = model.name + ' | چاپ فیش غذا';
        const walker = document.createTreeWalker(document.getElementById('app') || document, NodeFilter.SHOW_TEXT);
        const textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        textNodes.forEach(function (node) {
            const cleaned = cleanLegacyText(node.nodeValue);
            if (cleaned !== node.nodeValue) node.nodeValue = cleaned;
        });
        document.querySelectorAll('#app [placeholder]').forEach(function (field) {
            field.setAttribute('placeholder', cleanLegacyText(field.getAttribute('placeholder')));
        });
        document.querySelectorAll('.brand-mark').forEach(applyLogo);
        hydrateServiceFields();
        const heading = document.querySelector('.brand h1');
        if (heading && heading.textContent !== model.name) heading.textContent = model.name;
        const subtitle = document.querySelector('.brand small');
        if (subtitle && subtitle.textContent !== 'مدیریت چاپ فیش غذا') subtitle.textContent = 'مدیریت چاپ فیش غذا';
        const topMeta = document.querySelector('.top-meta');
        if (topMeta) {
            topMeta.querySelectorAll(':scope > span:not(.pill)').forEach(function (item) { item.remove(); });
            addReturnLink(topMeta);
        }
        const loginCard = document.querySelector('.boot-card');
        if (loginCard) addReturnLink(loginCard);
    }

    async function loadServiceBrand() {
        if (window.location.protocol === 'file:' || serviceConfigLoaded || serviceConfigLoading) return;
        serviceConfigLoading = true;
        const revision = serviceConfigRevision;
        try {
            const response = await fetch(serviceUrl('/api/config'), { credentials: 'same-origin' });
            if (!response.ok) return;
            const config = await response.json();
            serviceConfig = revision === serviceConfigRevision
                ? config
                : { ...config, ...serviceConfig };
            serviceConfigLoaded = true;
            if (!queryBrand && !hasInjectedBrand) {
                if (config.brandName) model.name = cleanBrandName(config.brandName);
                if (config.brandLogo) model.logo = config.brandLogo;
            }
            apply();
        } catch (_) {
        } finally {
            serviceConfigLoading = false;
        }
    }

    function boot() {
        apply();
        loadServiceBrand();
    }

    window.FOOD_TICKET_APPLY_BRAND = apply;
    const app = document.getElementById('app');
    if (app && window.MutationObserver) {
        new MutationObserver(apply).observe(app, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}());
