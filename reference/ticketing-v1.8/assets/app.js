document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert').forEach(function (item) {
        window.setTimeout(function () { item.style.opacity = '0'; item.style.transition = 'opacity .4s'; window.setTimeout(function () { item.remove(); }, 450); }, 5500);
    });
    var toastRoot = document.querySelector('#notification-toasts');
    if (toastRoot && window.fetch) {
        var cursor = parseInt(toastRoot.getAttribute('data-cursor') || '0', 10);
        var notificationLink = document.querySelector('.notification-link');
        var badge = notificationLink ? notificationLink.querySelector('b') : null;
        var unreadCount = badge ? parseInt(badge.textContent || '0', 10) : 0;
        var updateBadge = function () {
            if (!notificationLink) return;
            if (!badge) {
                badge = document.createElement('b');
                notificationLink.appendChild(badge);
            }
            badge.textContent = String(unreadCount);
        };
        var showNotification = function (item) {
            var toast = document.createElement('article');
            toast.className = 'notification-toast';
            var link = document.createElement('a');
            link.href = item.ticket_id ? 'index.php?page=ticket&id=' + encodeURIComponent(item.ticket_id) : 'index.php?page=notifications';
            var title = document.createElement('strong');
            title.textContent = item.title;
            var body = document.createElement('span');
            body.textContent = item.body;
            link.appendChild(title);
            link.appendChild(body);
            toast.appendChild(link);
            toastRoot.appendChild(toast);
            window.setTimeout(function () { toast.classList.add('is-hidden'); window.setTimeout(function () { toast.remove(); }, 300); }, 9000);
        };
        var pollNotifications = function () {
            window.fetch('index.php?action=notification_feed&after=' + encodeURIComponent(cursor), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (payload) {
                    if (!payload || !payload.ok || !Array.isArray(payload.items)) return;
                    payload.items.forEach(function (item) {
                        if (item.id <= cursor) return;
                        cursor = item.id;
                        unreadCount += 1;
                        showNotification(item);
                    });
                    updateBadge();
                })
                .catch(function () {});
        };
        window.setInterval(pollNotifications, 30000);
    }
    document.querySelectorAll('input[type="file"]').forEach(function (input) {
        input.addEventListener('change', function () {
            var label = input.closest('label');
            if (label && input.files[0]) {
                var note = label.querySelector('small');
                if (note) note.textContent = input.files[0].name;
            }
        });
    });
    document.querySelectorAll('.reveal-row').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = document.querySelector(button.getAttribute('data-target'));
            if (target) {
                target.classList.remove('is-hidden');
                button.remove();
            }
        });
    });
    document.querySelectorAll('[data-auto-filter], .filter-card form').forEach(function (form) {
        var timer = null;
        var submit = function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { form.submit(); }, 650);
        };
        form.querySelectorAll('select').forEach(function (field) { field.addEventListener('change', submit); });
        form.querySelectorAll('input:not([type="hidden"])').forEach(function (field) { field.addEventListener('input', submit); });
    });
    var bindCdDvdSurface = function () {
        var surface = document.querySelector('[data-cd-dvd-surface]');
        if (!surface || surface.getAttribute('data-bound') === '1') return;
        surface.setAttribute('data-bound', '1');
        var loadSurface = function (url, push) {
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (response) { if (!response.ok) throw new Error('بارگذاری فیلتر ناموفق بود.'); return response.text(); })
                .then(function (html) {
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html;
                    var next = wrapper.querySelector('[data-cd-dvd-surface]');
                    if (!next) throw new Error('سطح CD/DVD در پاسخ پیدا نشد.');
                    surface.replaceWith(next);
                    if (push) window.history.pushState({}, '', url);
                    bindCdDvdSurface();
                })
                .catch(function () { window.location.href = url; });
        };
        surface.querySelectorAll('[data-cd-dvd-filter-link]').forEach(function (link) {
            link.addEventListener('click', function (event) { event.preventDefault(); loadSurface(link.href, true); });
        });
        var form = surface.querySelector('[data-cd-dvd-filter-form]');
        if (form) {
            var timer = null;
            var submit = function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    var query = new URLSearchParams(new FormData(form)).toString();
                    loadSurface(form.action + (form.action.indexOf('?') === -1 ? '?' : '&') + query, true);
                }, 350);
            };
            form.addEventListener('submit', function (event) { event.preventDefault(); submit(); });
            form.querySelectorAll('input:not([type="hidden"]), select').forEach(function (field) { field.addEventListener('input', submit); field.addEventListener('change', submit); });
        }
    };
    bindCdDvdSurface();
    var bindCdDvdHistorySurface = function () {
        var surface = document.querySelector('[data-cd-dvd-history-surface]');
        if (!surface || surface.getAttribute('data-bound') === '1') return;
        surface.setAttribute('data-bound', '1');
        var form = surface.querySelector('[data-cd-dvd-history-form]');
        if (!form) return;
        var timer = null;
        var load = function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                var query = new URLSearchParams(new FormData(form)).toString();
                var url = window.location.pathname + '?' + query;
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (response) { if (!response.ok) throw new Error('بارگذاری سوابق ناموفق بود.'); return response.text(); })
                    .then(function (html) {
                        var wrapper = document.createElement('div');
                        wrapper.innerHTML = html;
                        var next = wrapper.querySelector('[data-cd-dvd-history-surface]');
                        if (!next) throw new Error('سطح سوابق CD/DVD در پاسخ پیدا نشد.');
                        surface.replaceWith(next);
                        window.history.pushState({}, '', url);
                        bindCdDvdHistorySurface();
                    })
                    .catch(function () { window.location.href = url; });
            }, 350);
        };
        form.addEventListener('submit', function (event) { event.preventDefault(); load(); });
        form.querySelectorAll('input:not([type="hidden"]), select').forEach(function (field) { field.addEventListener('input', load); field.addEventListener('change', load); });
    };
    bindCdDvdHistorySurface();
    var bindSettingsTabs = function () {
        var nav = document.querySelector('.settings-tabs');
        if (!nav || !document.querySelector('.settings-stack')) return;
        var definitions = [
            ['general', 'عمومی'],
            ['domain', 'اتصال دامین'],
            ['users', 'کاربران'],
            ['roles', 'نقش‌ها و دسترسی‌ها']
        ];
        var panels = definitions.map(function (item) { return document.getElementById(item[0]); }).filter(Boolean);
        if (!panels.length) return;
        nav.innerHTML = definitions.filter(function (item) { return document.getElementById(item[0]); }).map(function (item) {
            return '<a href="#' + item[0] + '" data-settings-tab="' + item[0] + '">' + item[1] + '</a>';
        }).join('');
        var links = nav.querySelectorAll('[data-settings-tab]');
        var defaultPanel = panels[0].id;
        var activate = function (name, updateUrl) {
            var selected = document.getElementById(name) ? name : defaultPanel;
            panels.forEach(function (panel) { panel.hidden = panel.id !== selected; });
            links.forEach(function (link) {
                var active = link.getAttribute('data-settings-tab') === selected;
                link.classList.toggle('active', active);
                link.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (updateUrl && window.history && window.history.pushState) window.history.pushState({}, '', '#' + selected);
        };
        nav.addEventListener('click', function (event) {
            var link = event.target.closest('[data-settings-tab]');
            if (!link) return;
            event.preventDefault();
            activate(link.getAttribute('data-settings-tab'), true);
        });
        var fromHash = window.location.hash.replace(/^#/, '');
        activate(fromHash || defaultPanel, false);
        window.addEventListener('popstate', function () { activate(window.location.hash.replace(/^#/, '') || defaultPanel, false); });
    };
    bindSettingsTabs();
    var bindRolesBoard = function () {
        var shell = document.querySelector('.roles-shell');
        if (!shell) return;
        var tabs = shell.querySelectorAll('[data-role-tab]');
        var panels = shell.querySelectorAll('[data-role-panel]');
        if (!tabs.length || !panels.length) return;
        var activate = function (name) {
            tabs.forEach(function (tab) {
                var active = tab.getAttribute('data-role-tab') === name;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) { panel.classList.toggle('active', panel.getAttribute('data-role-panel') === name); });
        };
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () { activate(tab.getAttribute('data-role-tab')); });
        });
        var current = Array.prototype.find.call(panels, function (panel) { return panel.classList.contains('active'); }) || panels[0];
        activate(current.getAttribute('data-role-panel'));

        var normalize = function (value) {
            return String(value || '').toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/g, ' ').trim();
        };
        panels.forEach(function (panel) {
            var items = panel.querySelectorAll('[data-perm-item]');
            var cards = panel.querySelectorAll('[data-perm-group-card]');
            var search = panel.querySelector('[data-perm-search]');
            var empty = panel.querySelector('[data-perm-empty]');
            var changesBadge = panel.querySelector('[data-role-changes]');
            var resetBtn = panel.querySelector('[data-perm-reset]');
            var countLabel = panel.querySelector('[data-role-count]');
            var original = Array.prototype.map.call(items, function (item) { return item.querySelector('input').checked; });

            var refreshGroup = function (card) {
                var groupItems = card.querySelectorAll('[data-perm-item] input');
                var checked = Array.prototype.filter.call(groupItems, function (input) { return input.checked; }).length;
                var total = groupItems.length;
                var count = card.querySelector('[data-group-count]');
                var fill = card.querySelector('.group-bar-fill');
                var allBtn = card.querySelector('[data-group-all]');
                if (count) {
                    count.textContent = checked + ' از ' + total;
                    count.classList.toggle('full', checked === total && total > 0);
                    count.classList.toggle('empty', checked === 0);
                    count.classList.toggle('partial', checked > 0 && checked < total);
                }
                if (fill) fill.style.width = (total ? Math.round(checked / total * 100) : 0) + '%';
                if (allBtn) allBtn.textContent = checked === total && total > 0 ? 'حذف همه' : 'انتخاب همه';
            };
            // ویرایش هر بخش (فرزند) : با تیک بخش، ویرایش خودکار روشن می‌شود؛ با برداشتن بخش، ویرایش خاموش و غیرفعال می‌شود.
            // follow=true یعنی ویرایش هم‌حالت بخش شود؛ only=اگر تعیین شود فقط همان بخش بررسی می‌شود.
            var syncEdits = function (follow, only) {
                panel.querySelectorAll('[data-perm-item][data-perm-edit]').forEach(function (child) {
                    var parent = child.previousElementSibling;
                    if (!parent || !parent.hasAttribute('data-perm-item') || parent.hasAttribute('data-perm-edit')) return;
                    var pIn = parent.querySelector('input'), cIn = child.querySelector('input');
                    if (!pIn || !cIn) return;
                    if (follow && (!only || only === pIn)) cIn.checked = pIn.checked;
                    if (!pIn.checked) cIn.checked = false;
                    cIn.disabled = !pIn.checked;
                    child.classList.toggle('checked', cIn.checked);
                    child.classList.toggle('is-disabled', !pIn.checked);
                });
            };
            syncEdits(false, null);
            var refreshChanges = function () {
                syncEdits(false, null);
                var added = 0, removed = 0;
                Array.prototype.forEach.call(items, function (item, index) {
                    var now = item.querySelector('input').checked;
                    if (now && !original[index]) added++;
                    if (!now && original[index]) removed++;
                });
                if (changesBadge) {
                    var hasChange = added > 0 || removed > 0;
                    changesBadge.hidden = !hasChange;
                    changesBadge.textContent = (added ? '+' + added : '') + (removed ? ' −' + removed : '') + ' تغییر ذخیره‌نشده';
                }
                if (resetBtn) resetBtn.hidden = added === 0 && removed === 0;
                if (countLabel) {
                    var total = items.length, checked = 0;
                    Array.prototype.forEach.call(items, function (item) { if (item.querySelector('input').checked) checked++; });
                    countLabel.innerHTML = checked + '<span>/' + total + '</span>';
                }
            };
            panel.querySelectorAll('[data-group-toggle]').forEach(function (header) {
                header.addEventListener('click', function (event) {
                    if (event.target.closest('[data-group-all]')) return;
                    var card = header.closest('[data-perm-group-card]');
                    if (card) card.classList.toggle('collapsed');
                });
            });
            panel.querySelectorAll('[data-group-all]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.stopPropagation();
                    var card = button.closest('[data-perm-group-card]');
                    if (!card) return;
                    var inputs = card.querySelectorAll('[data-perm-item] input');
                    var allChecked = Array.prototype.every.call(inputs, function (input) { return input.checked; });
                    Array.prototype.forEach.call(inputs, function (input) {
                        input.checked = !allChecked;
                        input.closest('[data-perm-item]').classList.toggle('checked', input.checked);
                    });
                    syncEdits(true, null);
                    refreshGroup(card);
                    refreshChanges();
                });
            });
            panel.querySelectorAll('[data-perm-item] input').forEach(function (input) {
                input.addEventListener('change', function () {
                    input.closest('[data-perm-item]').classList.toggle('checked', input.checked);
                    syncEdits(true, input);
                    var card = input.closest('[data-perm-group-card]');
                    if (card) refreshGroup(card);
                    refreshChanges();
                });
            });
            var allBtn = panel.querySelector('[data-perm-all]');
            var noneBtn = panel.querySelector('[data-perm-none]');
            if (allBtn) allBtn.addEventListener('click', function () {
                panel.querySelectorAll('[data-perm-item] input').forEach(function (input) {
                    input.checked = true;
                    input.closest('[data-perm-item]').classList.add('checked');
                });
                syncEdits(true, null);
                cards.forEach(refreshGroup);
                refreshChanges();
            });
            if (noneBtn) noneBtn.addEventListener('click', function () {
                panel.querySelectorAll('[data-perm-item] input').forEach(function (input) {
                    input.checked = false;
                    input.closest('[data-perm-item]').classList.remove('checked');
                });
                syncEdits(true, null);
                cards.forEach(refreshGroup);
                refreshChanges();
            });
            if (resetBtn) resetBtn.addEventListener('click', function () {
                Array.prototype.forEach.call(items, function (item, index) {
                    var input = item.querySelector('input');
                    input.checked = original[index];
                    item.classList.toggle('checked', input.checked);
                });
                cards.forEach(refreshGroup);
                refreshChanges();
            });
            if (search) search.addEventListener('input', function () {
                var query = normalize(search.value);
                var visible = 0;
                cards.forEach(function (card) {
                    var groupLabel = normalize(card.getAttribute('data-group-label'));
                    var groupVisible = query === '' || groupLabel.indexOf(query) !== -1;
                    var anyItem = false;
                    card.querySelectorAll('[data-perm-item]').forEach(function (item) {
                        var match = groupVisible || normalize(item.getAttribute('data-perm-label')).indexOf(query) !== -1;
                        item.hidden = !match;
                        if (match) { anyItem = true; visible++; }
                    });
                    card.hidden = !anyItem;
                });
                if (empty) empty.hidden = !(query !== '' && visible === 0);
            });
            cards.forEach(refreshGroup);
            refreshChanges();
        });

        var copyModal = shell.parentNode.querySelector('[data-copy-modal]');
        var copyBtn = shell.querySelector('[data-copy-role]');
        var selectedSource = null;
        var activeInputs = function () {
            var panel = Array.prototype.find.call(panels, function (item) { return item.classList.contains('active'); });
            return panel ? panel.querySelectorAll('[data-perm-item] input') : [];
        };
        var applyCopy = function () {
            var sources = copyModal ? copyModal.querySelectorAll('[data-copy-source]') : [];
            sources.forEach(function (option) {
                if (option.getAttribute('data-copy-source') === selectedSource) option.classList.add('selected');
                else option.classList.remove('selected');
            });
            var applyBtn = copyModal ? copyModal.querySelector('[data-copy-apply]') : null;
            if (applyBtn) applyBtn.disabled = !selectedSource;
        };
        if (copyBtn && copyModal) {
            copyBtn.addEventListener('click', function () { selectedSource = null; applyCopy(); copyModal.hidden = false; });
        }
        if (copyModal) copyModal.querySelectorAll('[data-copy-source]').forEach(function (option) {
            option.addEventListener('click', function () { selectedSource = option.getAttribute('data-copy-source'); applyCopy(); });
        });
        var cancelCopy = copyModal ? copyModal.querySelector('[data-copy-cancel]') : null;
        if (cancelCopy) cancelCopy.addEventListener('click', function () { copyModal.hidden = true; });
        var applyBtn = copyModal ? copyModal.querySelector('[data-copy-apply]') : null;
        if (applyBtn) applyBtn.addEventListener('click', function () {
            if (!selectedSource) return;
            var sourceRole = selectedSource;
            // خواندن دسترسی‌های نقش مبدأ از پنل مخفی همان نقش
            var sourcePanel = Array.prototype.find.call(panels, function (panel) { return panel.getAttribute('data-role-panel') === sourceRole; });
            var sourceChecked = [];
            if (sourcePanel) sourcePanel.querySelectorAll('[data-perm-item]').forEach(function (item) {
                if (item.querySelector('input').checked) sourceChecked.push(item.getAttribute('data-perm-label'));
            });
            var targetPanel = Array.prototype.find.call(panels, function (panel) { return panel.classList.contains('active'); });
            if (targetPanel && targetPanel.getAttribute('data-role-panel') !== 'primary_admin') {
                targetPanel.querySelectorAll('[data-perm-item]').forEach(function (item) {
                    var input = item.querySelector('input');
                    input.checked = sourceChecked.indexOf(item.getAttribute('data-perm-label')) !== -1;
                    item.classList.toggle('checked', input.checked);
                });
                targetPanel.querySelectorAll('[data-perm-group-card]').forEach(function (card) {
                    var inputs = card.querySelectorAll('[data-perm-item] input');
                    var checked = Array.prototype.filter.call(inputs, function (input) { return input.checked; }).length;
                    var count = card.querySelector('[data-group-count]');
                    var fill = card.querySelector('.group-bar-fill');
                    var groupAll = card.querySelector('[data-group-all]');
                    if (count) {
                        count.textContent = checked + ' از ' + inputs.length;
                        count.classList.toggle('full', checked === inputs.length && inputs.length > 0);
                        count.classList.toggle('empty', checked === 0);
                        count.classList.toggle('partial', checked > 0 && checked < inputs.length);
                    }
                    if (fill) fill.style.width = (inputs.length ? Math.round(checked / inputs.length * 100) : 0) + '%';
                    if (groupAll) groupAll.textContent = checked === inputs.length && inputs.length > 0 ? 'حذف همه' : 'انتخاب همه';
                });
                var badge = targetPanel.querySelector('[data-role-changes]');
                var reset = targetPanel.querySelector('[data-perm-reset]');
                if (badge) { badge.hidden = false; badge.textContent = 'کپی شد؛ برای اعمال ذخیره کنید'; }
                if (reset) reset.hidden = false;
                var label = targetPanel.querySelector('[data-role-count]');
                if (label) label.innerHTML = sourceChecked.length + '<span>/' + targetPanel.querySelectorAll('[data-perm-item]').length + '</span>';
            }
            copyModal.hidden = true;
        });
    };
    bindRolesBoard();
    var bindSettingsUserSearch = function () {
        var panel = document.getElementById('users');
        if (!panel || panel.getAttribute('data-user-search-bound') === '1') return;
        var list = panel.querySelector('.category-list');
        if (!list) return;
        panel.setAttribute('data-user-search-bound', '1');
        var toolbar = document.createElement('div');
        toolbar.className = 'settings-user-search';
        toolbar.innerHTML = '<label>جست‌وجوی کاربر<input type="search" autocomplete="off" placeholder="نام، نام خانوادگی یا نام کاربری"></label><small aria-live="polite"></small>';
        panel.insertBefore(toolbar, list);
        var input = toolbar.querySelector('input');
        var result = toolbar.querySelector('small');
        var rows = Array.from(list.children);
        var normalize = function (value) {
            return String(value || '').toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/g, ' ').trim();
        };
        var update = function () {
            var query = normalize(input.value);
            var visible = 0;
            rows.forEach(function (row) {
                var matches = query === '' || normalize(row.textContent).indexOf(query) !== -1;
                row.hidden = !matches;
                if (matches) visible += 1;
            });
            result.textContent = query === '' ? rows.length + ' کاربر' : visible + ' نتیجه';
        };
        input.addEventListener('input', update);
        update();
    };
    bindSettingsUserSearch();
    document.querySelectorAll('[data-digits-only]').forEach(function (field) {
        field.addEventListener('keydown', function (event) {
            if (event.ctrlKey || event.metaKey || event.altKey || event.key.length !== 1) return;
            if (!/[0-9۰-۹]/.test(event.key)) event.preventDefault();
        });
        field.addEventListener('input', function () { field.value = field.value.replace(/[^0-9۰-۹]/g, ''); });
    });
    var serviceSelector = document.querySelector('#service-selector');
    var serviceFields = document.querySelectorAll('[data-service-fields]');
    var groupInputs = document.querySelectorAll('input[name="service_group"]');
    var categorySelector = document.querySelector('#category-selector');
    var assetField = document.querySelector('.ticket-asset-field');
    var assetSelector = document.querySelector('#asset-selector');
    var supportFields = document.querySelectorAll('.support-field');
    var numberBox = document.querySelector('.ticket-number-box');
    var numberPreview = document.querySelector('#ticket-number-preview');
    var getGroup = function () {
        var checked = document.querySelector('input[name="service_group"]:checked');
        return checked ? checked.value : 'it';
    };
    // کاتالوگ کامل خدمت‌ها را یک‌بار نگه می‌داریم و هر بار فقط موارد سازگار با حوزه را
    // داخل select می‌گذاریم؛ چون hidden روی <option> در بعضی مرورگرها نادیده گرفته می‌شود.
    var serviceMaster = [];
    if (serviceSelector) {
        Array.prototype.forEach.call(serviceSelector.options, function (option) {
            if (!option.value) return;
            serviceMaster.push({
                value: option.value,
                text: option.textContent,
                group: option.getAttribute('data-service-group') || '',
                category: option.getAttribute('data-category-id') || '0',
                requiresAsset: option.getAttribute('data-requires-asset') || '0',
                selected: option.selected
            });
        });
    }
    var rebuildServiceSelect = function (group) {
        if (!serviceSelector) return;
        var previous = serviceSelector.value;
        var placeholder = serviceSelector.querySelector('option[value=""]');
        serviceSelector.innerHTML = '';
        if (placeholder) serviceSelector.appendChild(placeholder);
        else {
            var ph = document.createElement('option');
            ph.value = '';
            ph.textContent = 'انتخاب کنید…';
            serviceSelector.appendChild(ph);
        }
        var shown = 0;
        serviceMaster.forEach(function (item) {
            if (item.group !== group) return;
            var option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.text;
            option.setAttribute('data-service-group', item.group);
            option.setAttribute('data-category-id', item.category);
            option.setAttribute('data-requires-asset', item.requiresAsset);
            serviceSelector.appendChild(option);
            shown++;
        });
        var keep = serviceSelector.querySelector('option[value="' + (previous || '') + '"]');
        if (keep) serviceSelector.value = previous;
        else serviceSelector.value = '';
        placeholder = serviceSelector.querySelector('option[value=""]');
        if (placeholder) placeholder.textContent = shown ? 'انتخاب کنید…' : 'برای این حوزه خدمتی ثبت نشده';
    };
    var toggleServiceFields = function () {
        serviceFields.forEach(function (fieldset) {
            fieldset.hidden = !serviceSelector || fieldset.getAttribute('data-service-fields') !== serviceSelector.value;
        });
    };
    var updateTicketNumber = function () {
        if (!numberBox || !numberPreview) return;
        numberPreview.textContent = (getGroup() === 'support' ? numberBox.getAttribute('data-next-support') : numberBox.getAttribute('data-next-it')) || '';
    };
    var filterCategories = function (group) {
        if (!categorySelector || categorySelector.tagName !== 'SELECT') return;
        Array.prototype.forEach.call(categorySelector.options, function (option) {
            if (!option.value) return;
            var compatible = option.getAttribute('data-service-group') === group;
            option.hidden = !compatible;
            if (!compatible && option.selected) categorySelector.value = '';
        });
    };
    var syncTicketForm = function () {
        var group = getGroup();
        filterCategories(group);
        rebuildServiceSelect(group);
        var selectedService = serviceSelector ? serviceSelector.options[serviceSelector.selectedIndex] : null;
        if (categorySelector && categorySelector.tagName === 'INPUT') {
            categorySelector.value = (selectedService && selectedService.value !== '' ? (selectedService.getAttribute('data-category-id') || '') : '');
        }
        // ۱.۳۷.۲ (درخواست کاربر): نمایش و الزامِ «سیستم مرتبط» بر پایهٔ «حوزه خدمت» است:
        //   خدمات کامپیوتری ⇒ فیلد پیدا است و پرکردنش الزامی است.
        //   خدمات پشتیبانی ⇒ فیلد کاملاً پنهان می‌شود و مقدارش هم پاک می‌گردد.
        var requiresAsset = group === 'it';
        if (assetField) assetField.hidden = !requiresAsset;
        var assetErrorNode = document.querySelector('[data-asset-error]');
        if (assetErrorNode) {
            assetErrorNode.hidden = true;
            assetErrorNode.textContent = '';
        }
        if (assetField) assetField.classList.remove('field-invalid');
        var assetSearchNode = document.querySelector('#asset-search');
        if (assetSearchNode) {
            assetSearchNode.setAttribute('aria-required', requiresAsset ? 'true' : 'false');
            assetSearchNode.setAttribute('aria-invalid', 'false');
        }
        if (assetSelector) {
            assetSelector.required = false;
            if (!requiresAsset) {
                assetSelector.value = '';
                // ۱.۳۷: وقتی خدمت انتخاب‌شده به سیستم نیاز ندارد، متن نمایش‌داده‌شده در
                // کمبوی «سیستم مرتبط» هم پاک می‌شود تا چیزی برخلاف مقدار ثبت‌شده نماند.
                if (assetSearch) {
                    assetSearch.value = '';
                    assetSearch.removeAttribute('data-picked');
                }
            }
        }
        supportFields.forEach(function (field) {
            field.hidden = group !== 'support';
            var input = field.querySelector('input, textarea');
            if (input && input.name === 'support_location') input.required = group === 'support';
        });
        updateTicketNumber();
        toggleServiceFields();
    };
    groupInputs.forEach(function (input) {
        input.addEventListener('change', syncTicketForm);
    });
    if (categorySelector) categorySelector.addEventListener('change', syncTicketForm);
    if (serviceSelector) serviceSelector.addEventListener('change', syncTicketForm);
    syncTicketForm();
    /* ── «سیستم مرتبط»: کمبوی زندهٔ ۱.۳۷ ──────────────────────────────────────
       با تایپ، فهرست نتایج همان لحظه زیر فیلد باز می‌شود و با کلیک/Enter یکی انتخاب
       می‌شود. select اصلی مخفی است و فقط مقدار نهایی را برای فرم نگه می‌دارد. */
    var assetSearch = document.querySelector('#asset-search');
    var assetResults = document.querySelector('#asset-results');
    var assetCombo = document.querySelector('[data-asset-combo]');
    if (assetSearch && assetSelector && assetResults) {
        var comboCatalogue = [];
        Array.prototype.forEach.call(assetSelector.options, function (option) {
            if (!option.value) return;
            comboCatalogue.push({ id: option.value, text: option.textContent.replace(/\s+/g, ' ').trim() });
        });
        var comboNormalize = function (value) {
            return String(value || '')
                .toLowerCase()
                .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
                .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
                .replace(/[\u064A\u0649]/g, '\u06CC')
                .replace(/\u0643/g, '\u06A9')
                .replace(/\u200C/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        };
        var comboMatches = [];
        var comboActive = -1;
        var comboDirty = false;

        var comboClose = function () {
            assetResults.hidden = true;
            assetResults.innerHTML = '';
            comboActive = -1;
            assetSearch.setAttribute('aria-expanded', 'false');
        };
        var comboHighlight = function () {
            Array.prototype.forEach.call(assetResults.querySelectorAll('[data-combo-index]'), function (node, index) {
                node.setAttribute('data-active', index === comboActive ? '1' : '0');
            });
            var active = assetResults.querySelector('[data-combo-index="' + comboActive + '"]');
            if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
        };
        var comboPick = function (item) {
            // ۱.۳۷.۲: با انتخاب یک سیستم، پیام «الزامی است» پاک می‌شود.
            var errNode = document.querySelector('[data-asset-error]');
            if (errNode) {
                errNode.hidden = true;
                errNode.textContent = '';
            }
            if (assetField) assetField.classList.remove('field-invalid');
            assetSearch.removeAttribute('aria-invalid');
            assetSelector.value = item ? item.id : '';
            assetSearch.value = item ? item.text.replace(/\s*\(سیستم فعلی\)\s*$/, '') : '';
            comboDirty = !!item;
            comboClose();
            if (item) assetSearch.setAttribute('data-picked', '1');
            else assetSearch.removeAttribute('data-picked');
        };
        var comboRender = function () {
            var query = comboNormalize(assetSearch.value);
            comboMatches = query === ''
                ? comboCatalogue.slice()
                : comboCatalogue.filter(function (item) { return comboNormalize(item.text).indexOf(query) !== -1; });
            var limited = comboMatches.slice(0, 60);
            assetResults.innerHTML = '';
            // شمارهٔ ردیف‌ها به ترتیب دقیق نمایش (اول ردیف «حذف انتخاب»، بعد نتایج) تا
            // کلیدهای ↑/↓ و Enter همیشه با همان چیزی که دیده می‌شود یکی باشد.
            var comboSeq = 0;
            if (query !== '') {
                var clear = document.createElement('button');
                clear.type = 'button';
                clear.className = 'asset-result-clear';
                clear.setAttribute('data-combo-index', String(comboSeq++));
                clear.setAttribute('data-combo-clear', '1');
                clear.textContent = 'بدون سیستم خاص / حذف انتخاب';
                clear.addEventListener('mousedown', function (event) { event.preventDefault(); });
                clear.addEventListener('click', function () { comboPick(null); });
                assetResults.appendChild(clear);
            }
            limited.forEach(function (item, index) {
                var option = document.createElement('button');
                option.type = 'button';
                option.setAttribute('data-combo-index', String(comboSeq++));
                option.setAttribute('data-combo-id', item.id);
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', assetSelector.value === item.id ? 'true' : 'false');
                if (assetSelector.value === item.id) option.setAttribute('data-selected', '1');
                option.textContent = item.text;
                option.addEventListener('mousedown', function (event) { event.preventDefault(); });
                option.addEventListener('click', function () { comboPick(item); });
                assetResults.appendChild(option);
            });
            if (!limited.length) {
                var none = document.createElement('div');
                none.className = 'asset-result-none';
                none.textContent = 'سیستمی با این عبارت پیدا نشد.';
                assetResults.appendChild(none);
            } else if (!query && comboMatches.length > limited.length) {
                var more = document.createElement('div');
                more.className = 'asset-result-none';
                more.textContent = 'برای دیدن موارد بیشتر تایپ کنید… (' + limited.length + ' از ' + comboMatches.length + ')';
                assetResults.appendChild(more);
            }
            assetResults.hidden = false;
            assetSearch.setAttribute('aria-expanded', 'true');
            comboActive = -1;
            comboHighlight();
        };

        assetSearch.addEventListener('input', function () {
            // با هر ویرایش، انتخاب قبلی باطل می‌شود تا مقدار قدیمی بی‌سروصدا ثبت نشود.
            assetSelector.value = '';
            comboDirty = true;
            comboRender();
        });
        // با فوکوس، فهرست باز می‌شود؛ ولی اگر انتخابی در فیلد هست ناخواسته باز نمی‌شود
        // (برای دیدن همهٔ گزینه‌ها کافی است یک نویسه پاک/تایپ شود یا کلید ↓ زده شود).
        assetSearch.addEventListener('focus', function () { if (assetSearch.value.trim() === '') comboRender(); });
        assetSearch.addEventListener('keydown', function (event) {
            var count = assetResults.querySelectorAll('[data-combo-index]').length;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (assetResults.hidden) comboRender();
                event.preventDefault();
                comboActive = event.key === 'ArrowDown'
                    ? Math.min(count - 1, comboActive + 1)
                    : Math.max(0, comboActive - 1);
                comboHighlight();
                return;
            }
            if (event.key === 'Enter') {
                if (!assetResults.hidden) {
                    var node = comboActive >= 0
                        ? assetResults.querySelector('[data-combo-index="' + comboActive + '"]')
                        : null;
                    // ۱.۳۷.۲ (درخواست کاربر): اگر جست‌وجو فقط یک سیستم را برگردانده باشد،
                    // همان با یک Enter انتخاب می‌شود (بدون نیاز به ↑/↓).
                    if (!node) {
                        var assetRows = assetResults.querySelectorAll('[data-combo-id]');
                        if (assetRows.length === 1) node = assetRows[0];
                    }
                    if (node) {
                        event.preventDefault();
                        if (node.hasAttribute('data-combo-clear')) {
                            comboPick(null);
                        } else {
                            var pickedId = node.getAttribute('data-combo-id');
                            comboPick(comboCatalogue.filter(function (item) { return item.id === pickedId; })[0] || null);
                        }
                        return;
                    }
                    // بیش از یک نتیجه و هیچ ردیف فعالی: Enter فرم را نمی‌فرستد تا کاربر با ↑/↓
                    // (یا کلیک) یکی را انتخاب کند.
                    event.preventDefault();
                    return;
                }
                return;
            }
            if (event.key === 'Escape') { comboClose(); return; }
            if (event.key === 'Tab') { comboClose(); }
        });
        document.addEventListener('click', function (event) {
            if (!assetResults.hidden && !assetCombo.contains(event.target)) comboClose();
        });
        if (assetSelector.value) {
            var initial = comboCatalogue.filter(function (item) { return item.id === assetSelector.value; })[0];
            // سیستم فعلی/خودکار: متن در فیلد دیده می‌شود ولی کاربر تا تایپ نکند انتخاب دست‌نخورده می‌ماند.
            if (initial) assetSearch.value = initial.text.replace(/\s*\(سیستم فعلی\)\s*$/, '');
            assetSearch.removeAttribute('data-picked');
            comboDirty = false;
        }
        var ticketForm = assetSearch.closest('form');
        if (ticketForm) {
            ticketForm.addEventListener('submit', function (event) {
                // ۱.۳۷.۲: در حوزهٔ خدمات کامپیوتری انتخاب «سیستم مرتبط» الزامی است.
                if (getGroup() === 'it' && assetSelector.value === '') {
                    event.preventDefault();
                    var errNode = document.querySelector('[data-asset-error]');
                    if (errNode) {
                        errNode.textContent = 'برای ثبت تیکت در حوزهٔ خدمات کامپیوتری، انتخاب «سیستم مرتبط» الزامی است؛ از فهرست زیر انتخاب کنید.';
                        errNode.hidden = false;
                    }
                    if (assetField) assetField.classList.add('field-invalid');
                    assetSearch.setAttribute('aria-invalid', 'true');
                    if (assetResults.hidden) comboRender();
                    assetSearch.focus();
                    return;
                }
                // اگر کاربر متنی تایپ کرده ولی نتیجه‌ای انتخاب نکرده، سیستمِ قدیمی ثبت نشود.
                if (comboDirty && !assetSearch.hasAttribute('data-picked')) assetSelector.value = '';
            });
        }
    }
    var jalaliPicker = document.querySelector('[data-jalali-picker]');
    if (false && jalaliPicker) { /* replaced by jalali-calendar.js */
        var jalaliInput = jalaliPicker.querySelector('input[name="holiday_date"]') || jalaliPicker.querySelector('input[data-jalali-input]');
        var jalaliCalendar = jalaliPicker.querySelector('.jalali-calendar');
        var monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        var weekNames = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
        var pad = function (value) { return String(value).padStart(2, '0'); };
        var jalaliToGregorian = function (jy, jm, jd) {
            jy += 1595;
            var days = -355668 + (365 * jy) + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd;
            days += jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186;
            var gy = 400 * Math.floor(days / 146097);
            days %= 146097;
            if (days > 36524) {
                gy += 100 * Math.floor(--days / 36524);
                days %= 36524;
                if (days >= 365) days += 1;
            }
            gy += 4 * Math.floor(days / 1461);
            days %= 1461;
            if (days > 365) {
                gy += Math.floor((days - 1) / 365);
                days = (days - 1) % 365;
            }
            var gd = days + 1;
            var leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
            var monthDays = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            var gm = 1;
            while (gm <= 12 && gd > monthDays[gm]) gd -= monthDays[gm++];
            return [gy, gm, gd];
        };
        var gregorianToJalali = function (gy, gm, gd) {
            var gDays = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            var jy = 979;
            gy -= 1600;
            gm -= 1;
            gd -= 1;
            var days = 365 * gy + Math.floor((gy + 3) / 4) - Math.floor((gy + 99) / 100) + Math.floor((gy + 399) / 400);
            for (var i = 0; i < gm; i++) days += gDays[i + 1];
            if (gm > 1 && ((gy + 1600) % 4 === 0 && ((gy + 1600) % 100 !== 0 || (gy + 1600) % 400 === 0))) days += 1;
            days += gd - 79;
            jy += 33 * Math.floor(days / 12053);
            days %= 12053;
            jy += 4 * Math.floor(days / 1461);
            days %= 1461;
            if (days > 365) {
                jy += Math.floor((days - 1) / 365);
                days = (days - 1) % 365;
            }
            var jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
            var jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
            return [jy, jm, jd];
        };
        var monthLength = function (year, month) {
            if (month <= 6) return 31;
            if (month <= 11) return 30;
            var start = jalaliToGregorian(year, 12, 1);
            var next = jalaliToGregorian(year + 1, 1, 1);
            return Math.round((Date.UTC(next[0], next[1] - 1, next[2]) - Date.UTC(start[0], start[1] - 1, start[2])) / 86400000);
        };
        var parseJalali = function (value) {
            var match = String(value || '').replace(/-/g, '/').match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
            return match ? [Number(match[1]), Number(match[2]), Number(match[3])] : null;
        };
        var now = new Date();
        var current = gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
        var parsed = parseJalali(jalaliInput.value);
        var viewYear = parsed ? parsed[0] : current[0];
        var viewMonth = parsed ? parsed[1] : current[1];
        var renderCalendar = function () {
            var firstGregorian = jalaliToGregorian(viewYear, viewMonth, 1);
            var firstDay = new Date(firstGregorian[0], firstGregorian[1] - 1, firstGregorian[2]);
            var startIndex = (firstDay.getDay() + 1) % 7;
            var html = '<div class="jalali-calendar-head"><button type="button" data-calendar-prev aria-label="ماه قبل">‹</button><strong>' + monthNames[viewMonth - 1] + ' ' + viewYear + '</strong><button type="button" data-calendar-next aria-label="ماه بعد">›</button></div><div class="jalali-weekdays">';
            weekNames.forEach(function (name) { html += '<span>' + name + '</span>'; });
            html += '</div><div class="jalali-days">';
            for (var blank = 0; blank < startIndex; blank++) html += '<span></span>';
            for (var day = 1; day <= monthLength(viewYear, viewMonth); day++) {
                var weekdayIndex = (startIndex + day - 1) % 7;
                var weekend = weekdayIndex === 5 || weekdayIndex === 6;
                var selected = jalaliInput.value === viewYear + '/' + pad(viewMonth) + '/' + pad(day);
                html += '<button type="button" class="jalali-day' + (weekend ? ' fixed-weekend' : '') + (selected ? ' selected' : '') + '" data-jalali-day="' + day + '"' + (weekend ? ' disabled title="تعطیلی ثابت"' : '') + '>' + day + '</button>';
            }
            html += '</div><small class="jalali-calendar-note">پنج‌شنبه و جمعه تعطیل ثابت هستند.</small>';
            jalaliCalendar.innerHTML = html;
            jalaliCalendar.querySelector('[data-calendar-prev]').addEventListener('click', function () { viewMonth -= 1; if (viewMonth < 1) { viewMonth = 12; viewYear -= 1; } renderCalendar(); });
            jalaliCalendar.querySelector('[data-calendar-next]').addEventListener('click', function () { viewMonth += 1; if (viewMonth > 12) { viewMonth = 1; viewYear += 1; } renderCalendar(); });
            jalaliCalendar.querySelectorAll('[data-jalali-day]').forEach(function (button) {
                button.addEventListener('click', function () { jalaliInput.value = viewYear + '/' + pad(viewMonth) + '/' + pad(Number(button.getAttribute('data-jalali-day'))); jalaliCalendar.hidden = true; });
            });
        };
        var trigger = jalaliPicker.querySelector('.calendar-trigger');
        trigger.addEventListener('click', function () { var value = parseJalali(jalaliInput.value); if (value) { viewYear = value[0]; viewMonth = value[1]; } renderCalendar(); jalaliCalendar.hidden = !jalaliCalendar.hidden; });
        jalaliInput.addEventListener('focus', function () { renderCalendar(); jalaliCalendar.hidden = false; });
        document.addEventListener('click', function (event) { if (!jalaliPicker.contains(event.target)) jalaliCalendar.hidden = true; });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var panelsRoot = document.querySelector('.profile-panels');
    if (!panelsRoot) return;
    var tabs = panelsRoot.parentNode.querySelectorAll('.profile-tab');
    var panels = panelsRoot.querySelectorAll('.profile-panel');
    var activate = function (key) {
        tabs.forEach(function (tab) { tab.classList.toggle('active', tab.getAttribute('data-tab') === key); });
        panels.forEach(function (panel) { panel.classList.toggle('active', panel.getAttribute('data-panel') === key); });
        panelsRoot.setAttribute('data-active', key);
        try { history.replaceState(null, '', '#' + key); } catch (error) {}
    };
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activate(tab.getAttribute('data-tab'));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
    var initial = panelsRoot.getAttribute('data-active') || 'overview';
    var hash = (window.location.hash || '').replace('#', '');
    if (hash) {
        var hasHash = false;
        panels.forEach(function (panel) { if (panel.getAttribute('data-panel') === hash) hasHash = true; });
        if (hasHash) initial = hash;
    }
    activate(initial);
});

document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.querySelector('[data-select-all]');
    if (!selectAll) { return; }
    var name = selectAll.getAttribute('data-select-all');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('input[type="checkbox"][name="' + name + '"]'));
    // ۱.۳۷: با فیلتر زنده، «انتخاب همه» فقط ردیف‌های دیده‌شده را انتخاب می‌کند.
    var visibleBoxes = function () {
        return boxes.filter(function (box) {
            var row = box.closest('[data-asset-row]');
            return !row || !row.hidden;
        });
    };
    var sync = function () {
        var visible = visibleBoxes();
        var checked = visible.filter(function (box) { return box.checked; }).length;
        selectAll.checked = visible.length > 0 && checked === visible.length;
        selectAll.indeterminate = checked > 0 && checked < visible.length;
    };
    selectAll.addEventListener('change', function () {
        visibleBoxes().forEach(function (box) { box.checked = selectAll.checked; });
        sync();
    });
    boxes.forEach(function (box) { box.addEventListener('change', sync); });
    sync();
    document.addEventListener('asset-live-filtered', sync);
});

/* فیلتر زندهٔ «شناسنامه‌های فنی سیستم» (۱.۳۷)
   دکمهٔ «اعمال فیلتر» حذف شده است: با هر نویسه‌ای که در کادر جست‌وجو تایپ شود یا با
   تغییر «وضعیت شبکه»، فهرست پایین همان لحظه فیلتر می‌شود و شمارنده به‌روز می‌شود. */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-asset-live-filter]');
    var input = document.querySelector('[data-asset-live-q]');
    var netSelect = document.querySelector('[data-asset-live-network]');
    if (!form || (!input && !netSelect)) { return; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-asset-row]'));
    var counter = document.querySelector('[data-asset-live-count]');
    var emptyNote = document.querySelector('[data-asset-live-empty]');
    var fa = new Intl.NumberFormat('fa-IR');
    var normalize = function (value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
            .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/[\u064A\u0649]/g, '\u06CC')
            .replace(/\u0643/g, '\u06A9')
            .replace(/\u200C/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    };
    var apply = function () {
        var query = input ? normalize(input.value) : '';
        var network = netSelect ? netSelect.value : 'all';
        var shown = 0;
        rows.forEach(function (row) {
            var haystack = normalize(row.getAttribute('data-asset-search') || row.textContent);
            var matchesQuery = query === '' || haystack.indexOf(query) !== -1;
            var matchesNetwork = network === 'all' || (row.getAttribute('data-asset-net') || '') === network;
            var visible = matchesQuery && matchesNetwork;
            row.hidden = !visible;
            if (visible) shown++;
        });
        if (counter) {
            counter.textContent = shown === rows.length
                ? (fa.format(rows.length) + ' سیستم')
                : ('نمایش ' + fa.format(shown) + ' از ' + fa.format(rows.length) + ' سیستم');
        }
        if (emptyNote) emptyNote.hidden = shown > 0 || rows.length === 0;
        document.dispatchEvent(new CustomEvent('asset-live-filtered', { detail: { visible: shown } }));
    };
    if (input) {
        input.addEventListener('input', apply);
        input.addEventListener('search', apply);
        input.addEventListener('keydown', function (event) { if (event.key === 'Escape') { input.value = ''; apply(); } });
    }
    if (netSelect) netSelect.addEventListener('change', apply);
    form.addEventListener('submit', function (event) { event.preventDefault(); apply(); });
    apply();
});

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('assetSelectionForm');
    if (!form) { return; }
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="asset_ids[]"]'));
    var count = document.querySelector('[data-asset-selection-count]');
    var actions = Array.prototype.slice.call(document.querySelectorAll('[data-asset-selection-action]'));
    var selectAll = form.querySelector('[data-select-all]');
    function updateSelection() {
        var selected = boxes.filter(function (box) { return box.checked; }).length;
        if (count) {
            count.textContent = selected > 900
                ? 'بیش از سقف ۹۰۰ سیستم انتخاب شده؛ تعداد را کمتر کنید'
                : new Intl.NumberFormat('fa-IR').format(selected) + ' سیستم انتخاب شده';
        }
        actions.forEach(function (action) {
            var maximum = Number(action.getAttribute('data-max-selection')) || 900;
            var scanConfigured = action.getAttribute('data-scan-configured') !== '0';
            action.disabled = !scanConfigured || selected === 0 || selected > maximum;
        });
        form.dispatchEvent(new CustomEvent('asset-selection-changed', { detail: { selected: selected } }));
    }
    boxes.forEach(function (box) { box.addEventListener('change', updateSelection); });
    if (selectAll) { selectAll.addEventListener('change', updateSelection); }
    updateSelection();
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.settings-tabs').forEach(function (tabs) {
        tabs.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                tabs.querySelectorAll('a').forEach(function (item) { item.classList.remove('active'); });
                link.classList.add('active');
            });
        });
    });
});

document.addEventListener('DOMContentLoaded', function () {
    var nodeType = document.querySelector('#org-node-type');
    var nodeFields = document.querySelectorAll('.org-node-field');
    var syncOrgFields = function () {
        if (!nodeType) return;
        var type = nodeType.value;
        nodeFields.forEach(function (field) {
            var target = field.getAttribute('data-node');
            var show = target === 'unit' ? (type === 'deputy' || type === 'department') : (target === type);
            field.hidden = !show;
        });
    };
    if (nodeType) { nodeType.addEventListener('change', syncOrgFields); syncOrgFields(); }

    var hiddenForm = document.querySelector('#org-hidden-form');
    if (hiddenForm) {
        document.querySelectorAll('[data-org-del]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm('این مورد غیرفعال شود؟')) return;
                document.querySelector('#org-hidden-action').value = 'delete_org_unit';
                document.querySelector('#org-hidden-unit').value = btn.getAttribute('data-org-del');
                hiddenForm.submit();
            });
        });
        document.querySelectorAll('[data-org-unassign]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm('این کارشناس از اداره جدا شود؟')) return;
                document.querySelector('#org-hidden-action').value = 'save_org_node';
                document.querySelector('#org-hidden-node').value = 'member';
                document.querySelector('#org-hidden-user').value = btn.getAttribute('data-org-unassign');
                document.querySelector('#org-hidden-member-unit').value = '0';
                hiddenForm.submit();
            });
        });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form');
        if (!form) return;
        var submitter = event.submitter || null;
        var action = form.querySelector('[name="action"]');
        var actionValue = submitter && submitter.name === 'action' ? submitter.value : (action ? action.value : '');
        var isDelete = actionValue && /delete|remove|destroy/i.test(actionValue);
        // ۱.۳۷.۷ — تأیید می‌تواند روی خود دکمه هم نوشته شود (data-confirm روی button).
        var submitterConfirm = submitter && submitter.hasAttribute('data-confirm') ? submitter.getAttribute('data-confirm') : '';
        if (!form.hasAttribute('data-confirm') && !isDelete && !submitterConfirm) return;
        var message = submitterConfirm || form.getAttribute('data-confirm') || 'این مورد حذف شود؟';
        if (!window.confirm(message)) event.preventDefault();
    });

    var serviceFilter = document.querySelector('[data-service-filter]');
    if (serviceFilter) {
        serviceFilter.addEventListener('change', function () {
            var form = serviceFilter.form;
            if (form && typeof form.requestSubmit === 'function') form.requestSubmit();
            else if (form) form.submit();
        });
    }

    var modalLayer = document.querySelector('[data-service-modal-layer]');
    if (modalLayer) {
        var modals = Array.prototype.slice.call(modalLayer.querySelectorAll('.service-modal'));
        var lastModalTrigger = null;
        var closeModal = function () {
            modals.forEach(function (modal) { modal.hidden = true; });
            modalLayer.hidden = true;
            document.body.classList.remove('service-modal-active');
            if (lastModalTrigger) lastModalTrigger.focus();
        };
        document.querySelectorAll('[data-modal-open]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                var name = button.getAttribute('data-modal-open');
                var target = modals.find(function (modal) { return modal.getAttribute('data-modal') === name; });
                if (!target) return;
                lastModalTrigger = button;
                modals.forEach(function (modal) { modal.hidden = modal !== target; });
                modalLayer.hidden = false;
                document.body.classList.add('service-modal-active');
                var first = target.querySelector('input:not([type="hidden"]), select, textarea, button');
                if (first) first.focus();
            });
        });
        modalLayer.querySelectorAll('[data-modal-close]').forEach(function (button) {
            button.addEventListener('click', closeModal);
        });
        modalLayer.addEventListener('click', function (event) {
            if (event.target === modalLayer) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modalLayer.hidden) closeModal();
        });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var tabsNav = document.querySelector('[data-service-tabs]');
    if (!tabsNav) return;
    var links = tabsNav.querySelectorAll('[data-service-tab]');
    var panels = Array.prototype.slice.call(document.querySelectorAll('.service-tab-panels .service-tab-panel'));
    var activate = function (name, updateUrl) {
        var target = document.getElementById(name) ? name : (panels[0] ? panels[0].id : '');
        panels.forEach(function (panel) { panel.hidden = panel.id !== target; });
        links.forEach(function (link) {
            link.classList.toggle('active', link.getAttribute('data-service-tab') === target);
        });
        if (updateUrl && window.history && window.history.pushState) window.history.pushState({}, '', '#' + target);
    };
    tabsNav.addEventListener('click', function (event) {
        var link = event.target.closest('[data-service-tab]');
        if (!link) return;
        event.preventDefault();
        activate(link.getAttribute('data-service-tab'), true);
    });
    var fromHash = window.location.hash.replace(/^#/, '');
    var requestedTab = new URLSearchParams(window.location.search).get('tab');
    activate(requestedTab || fromHash || 'tab-create', false);
});

document.addEventListener('DOMContentLoaded', function () {
    var dropdowns = Array.prototype.slice.call(document.querySelectorAll('.navbar .nav-dropdown'));
    if (!dropdowns.length) return;
    var hoverEnabled = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var syncItem = function (details) {
        var item = details.closest('.has-submenu');
        if (item) item.classList.toggle('open', details.open);
    };
    dropdowns.forEach(function (details) {
        var item = details.closest('.has-submenu');
        details.addEventListener('toggle', function () {
            syncItem(details);
            if (details.open) {
                dropdowns.forEach(function (other) {
                    if (other !== details && other.open) other.open = false;
                });
            }
        });
        if (hoverEnabled && item) {
            item.addEventListener('mouseenter', function () { details.open = true; });
            item.addEventListener('mouseleave', function () { details.open = false; });
        }
    });
    document.addEventListener('click', function (event) {
        if (event.target.closest('.navbar')) return;
        dropdowns.forEach(function (details) { details.open = false; });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        var openMenu = document.querySelector('.navbar .nav-dropdown[open]');
        if (!openMenu) return;
        var summary = openMenu.querySelector('summary');
        openMenu.open = false;
        if (summary) summary.focus();
    });
});


/* پینگ سیستم‌های انتخاب‌شده: ارسال تکه‌تکه (۴۰ تایی) با نمایش پیشرفت و به‌روزرسانی هر ردیف بدون بازخوانی صفحه */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('assetSelectionForm');
    var button = document.querySelector('[data-ping-chunk]');
    if (!form || !button || !window.fetch) { return; }
    var progress = document.querySelector('[data-ping-progress]');
    var csrfInput = form.querySelector('input[name="csrf"]');
    var fa = new Intl.NumberFormat('fa-IR');
    var running = false;

    function setBadge(id, online) {
        var row = form.querySelector('[data-asset-row="' + id + '"]');
        var badge = row ? row.querySelector('[data-net-badge]') : null;
        if (!badge) { return; }
        badge.className = 'inventory-badge ' + (online ? 'online' : 'offline');
        badge.textContent = online ? 'آنلاین' : 'آفلاین';
    }
    function refreshOnlineTotal() {
        var total = document.querySelector('[data-online-total]');
        if (total) { total.textContent = String(form.querySelectorAll('[data-net-badge].online').length); }
    }

    button.addEventListener('click', function (event) {
        event.preventDefault();
        if (running || button.disabled) { return; }
        var ids = Array.prototype.slice.call(form.querySelectorAll('input[name="asset_ids[]"]:checked')).map(function (box) { return box.value; });
        if (!ids.length || !csrfInput) { return; }
        var size = Number(button.getAttribute('data-ping-chunk')) || 40;
        var chunks = [];
        for (var i = 0; i < ids.length; i += size) { chunks.push(ids.slice(i, i + size)); }
        var done = 0, online = 0, failed = '';
        running = true;
        button.disabled = true;
        if (progress) { progress.hidden = false; progress.textContent = 'در حال پینگ… ۰ از ' + fa.format(ids.length); }

        function next(index) {
            if (index >= chunks.length || failed) { return Promise.resolve(); }
            var body = new FormData();
            body.append('csrf', csrfInput.value);
            body.append('action', 'ping_assets');
            body.append('ajax', '1');
            chunks[index].forEach(function (id) { body.append('asset_ids[]', id); });
            return fetch('index.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) { return response.json().catch(function () { return { ok: false, error: 'پاسخ نامعتبر از سرور (کد ' + response.status + ')' }; }); })
                .then(function (json) {
                    if (!json || !json.ok) { failed = (json && json.error) || 'خطا در پینگ'; return; }
                    Object.keys(json.states || {}).forEach(function (id) { setBadge(id, !!json.states[id]); });
                    done += chunks[index].length;
                    online += Number(json.online) || 0;
                    refreshOnlineTotal();
                    if (progress) { progress.textContent = 'در حال پینگ… ' + fa.format(done) + ' از ' + fa.format(ids.length); }
                })
                .catch(function () { failed = 'ارتباط با سرور برقرار نشد.'; })
                .then(function () { return next(index + 1); });
        }
        next(0).then(function () {
            running = false;
            button.disabled = false;
            if (progress) {
                progress.textContent = failed
                    ? ('پینگ متوقف شد: ' + failed)
                    : ('پینگ تمام شد: ' + fa.format(done) + ' سیستم بررسی شد؛ ' + fa.format(online) + ' آنلاین و ' + fa.format(done - online) + ' آفلاین.');
            }
        });
    });
});
