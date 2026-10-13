(function () {
    'use strict';

    var data = window.PERSIAN_ORG_DATA || { canManage: false, undo: false, undoLabel: '', units: [], users: [] };
    var units = data.units || [];
    var users = (data.users || []).filter(function (item) { return item.active; });
    var state = { zoom: 1, search: '', poolSearch: '', filterStat: null, modal: null, inline: null, confirm: null };
    var $ = function (selector) { return document.querySelector(selector); };
    var $$ = function (selector) { return Array.prototype.slice.call(document.querySelectorAll(selector)); };
    var esc = function (value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
        });
    };
    var initial = function (name) { return String(name || '?').trim().charAt(0) || '?'; };
    var iconFor = function (type) {
        var paths = {
            ceo: '<path d="M4 20h16M6 20V8l6-4 6 4v12M9 12h2v2H9m4 0h2v-2h-2m-4 4h2v2H9m4 0h2v-2h-2"/>',
            deputy: '<path d="M3 20h18M5 20V8l7-4 7 4v12M8 11v6m4-6v6m4-6v6M7 20h10"/>',
            department: '<path d="M3 6h7l2 2h9v10H3zM3 10h18"/>',
            manager: '<circle cx="12" cy="8" r="3"/><path d="M5 20c.7-3.6 2.9-5.5 7-5.5s6.3 1.9 7 5.5M17 5v4m-2-2h4"/>',
            expert: '<circle cx="12" cy="8" r="3"/><path d="M5 20c.7-3.6 2.9-5.5 7-5.5s6.3 1.9 7 5.5M17 15v4m-2-2h4"/>',
            pool: '<path d="M4 8h16v11H4zM7 8V5h10v3M8 12h8M8 15h5"/>',
            user: '<circle cx="12" cy="8" r="3"/><path d="M5 20c.7-3.6 2.9-5.5 7-5.5s6.3 1.9 7 5.5"/>',
            add: '<path d="M12 5v14M5 12h14"/>',
            remove: '<path d="M5 12h14"/>',
            delete: '<path d="M5 7h14M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/>',
            manage: '<path d="M5 6h14M5 12h14M5 18h14M8 6v0m5 6v0m-3 6v0"/>',
            warning: '<circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3v.1"/>',
            reset: '<path d="M5 8a8 8 0 1 1 1 8M5 8V3m0 5h5"/>',
            start: '<path d="M4 20h16M6 20V8l6-4 6 4v12M9 12h2v2H9m4 0h2v-2h-2"/>',
            export: '<path d="M12 4v12m0 0-4-4m4 4 4-4M5 20h14"/>'
        };
        return '<svg class="org-svg-icon org-svg-' + type + '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (paths[type] || paths.user) + '</svg>';
    };
    var unit = function (id) { return units.find(function (item) { return Number(item.id) === Number(id); }) || null; };
    var person = function (id) { return users.find(function (item) { return Number(item.id) === Number(id); }) || null; };
    var root = function () { return units.find(function (item) { return item.type === 'ceo'; }) || null; };
    var children = function (parentId, type) {
        return units.filter(function (item) { return Number(item.parentId) === Number(parentId) && (!type || item.type === type); })
            .sort(function (a, b) { return a.name.localeCompare(b.name, 'fa'); });
    };
    var managedUnit = function (userId) { return units.find(function (item) { return Number(item.managerId) === Number(userId); }) || null; };
    var roleOf = function (item) {
        var managed = managedUnit(item.id);
        var ceo = root();
        if (ceo && Number(ceo.managerId) === Number(item.id)) return 'ceo';
        if (managed && managed.type === 'deputy') return 'dep';
        if (managed && managed.type === 'department') return 'dept';
        return Number(item.unitId) > 0 ? 'expert' : 'pool';
    };
    var positionOf = function (item) {
        var managed = managedUnit(item.id);
        if (managed && managed.type === 'ceo') return 'مدیرعامل';
        if (managed) return 'مدیر ' + managed.name;
        if (Number(item.unitId) > 0) {
            var assigned = unit(item.unitId);
            return assigned ? 'کارشناس ' + assigned.name : 'کارشناس';
        }
        return 'آزاد';
    };
    var codeOf = function (item) { return item.code || item.username || ''; };
    var allDepartments = function () { return units.filter(function (item) { return item.type === 'department'; }); };
    var expertsIn = function (unitId) { return users.filter(function (item) { return Number(item.unitId) === Number(unitId) && roleOf(item) === 'expert'; }); };
    var poolUsers = function () { return users.filter(function (item) { return roleOf(item) === 'pool'; }); };
    var normalize = function (value) { return String(value || '').toLocaleLowerCase('fa').replace(/\s+/g, ' ').trim(); };
    var match = function (item) {
        var query = normalize(state.search);
        return !!query && (normalize(item.name).indexOf(query) !== -1 || normalize(item.code).indexOf(query) !== -1);
    };
    var toast = function (message, icon) {
        var rootEl = $('#org-toast');
        if (!rootEl) return;
        var iconMarkup = String(icon || '✓').indexOf('<svg') === 0 ? icon : esc(icon || '✓');
        rootEl.innerHTML = '<span class="org-toast-icon">' + iconMarkup + '</span><span>' + esc(message) + '</span>';
        rootEl.classList.add('show');
        window.clearTimeout(rootEl._timer);
        rootEl._timer = window.setTimeout(function () { rootEl.classList.remove('show'); }, 2000);
    };
    var submit = function (action, fields) {
        if (!data.canManage) return;
        var form = $('#org-action-form');
        if (!form) return;
        form.querySelectorAll('input:not([name="csrf"])').forEach(function (input) { input.remove(); });
        var actionField = document.createElement('input');
        actionField.type = 'hidden'; actionField.name = 'action'; actionField.value = action;
        form.appendChild(actionField);
        Object.keys(fields || {}).forEach(function (key) {
            var value = fields[key];
            var list = Array.isArray(value) ? value : [value];
            list.forEach(function (entry) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = Array.isArray(value) ? key + '[]' : key; input.value = String(entry == null ? '' : entry);
                form.appendChild(input);
            });
        });
        form.submit();
    };

    var closeConfirm = function () {
        $('#org-confirm-backdrop').classList.remove('show');
        state.confirm = null;
    };
    var askConfirm = function (options) {
        state.confirm = options.onConfirm;
        $('#org-confirm-icon').innerHTML = options.icon || iconFor('warning');
        $('#org-confirm-title').textContent = options.title || 'تأیید عملیات';
        $('#org-confirm-message').textContent = options.message || 'آیا مطمئن هستید؟';
        $('#org-confirm-details').innerHTML = options.details || '';
        $('#org-confirm-ok').textContent = options.okText || 'تأیید';
        $('#org-confirm-ok').className = 'org-btn ' + (options.danger ? 'org-btn-danger' : 'org-btn-success');
        $('#org-confirm-backdrop').classList.add('show');
    };

    var closeModal = function () {
        $('#org-modal-backdrop').classList.remove('show');
        state.modal = null;
    };
    var openModal = function (options) {
        state.modal = { title: options.title, subtitle: options.subtitle || 'از فهرست زیر انتخاب کنید', icon: options.icon || iconFor('user'), users: options.users || [], multi: !!options.multi, poolOnly: !!options.poolOnly, custom: !!options.custom, currentUser: options.currentUser || null, selected: [], search: '', onPick: options.onPick, onConfirm: options.onConfirm };
        var backdrop = $('#org-modal-backdrop');
        backdrop.classList.toggle('single', !state.modal.multi);
        $('#org-modal-title').textContent = state.modal.title;
        $('#org-modal-subtitle').textContent = state.modal.subtitle;
        $('#org-modal-icon').innerHTML = state.modal.icon;
        $('#org-modal-search').value = '';
        $('#org-modal-preview').innerHTML = state.modal.currentUser ? '<div class="org-position-preview"><span class="org-avatar-small">' + esc(initial(state.modal.currentUser.name)) + '</span><span><small>موقعیت فعلی</small><b>' + esc(state.modal.currentUser.name) + '</b><em>' + esc(positionOf(state.modal.currentUser)) + '</em></span></div>' : '';
        $('#org-modal-hint').hidden = state.modal.multi;
        $('#org-modal-backdrop').classList.add('show');
        renderModal();
        window.setTimeout(function () { $('#org-modal-search').focus(); }, 50);
    };
    var renderModal = function () {
        if (!state.modal) return;
        var query = normalize(state.modal.search);
        var list = state.modal.users.filter(function (item) { return !query || normalize(item.name).indexOf(query) !== -1 || normalize(codeOf(item)).indexOf(query) !== -1; }).slice(0, 200);
        var body = $('#org-modal-body');
        if (!list.length) {
            body.innerHTML = '<div class="org-modal-empty">?<br>موردی یافت نشد</div>';
        } else {
            body.innerHTML = list.map(function (item) {
                var selected = state.modal.selected.indexOf(Number(item.id)) !== -1;
                var tag = state.modal.custom ? '' : '<span class="org-position-tag ' + (roleOf(item) === 'pool' ? 'pool' : '') + '">' + esc(positionOf(item)) + '</span>';
                return '<div class="org-user-item ' + (selected ? 'selected' : '') + '" data-org-modal-user="' + Number(item.id) + '">' + (state.modal.multi ? '<span class="org-user-check" role="checkbox" aria-checked="' + (selected ? 'true' : 'false') + '">' + (selected ? '✓' : '') + '</span>' : '') + '<span class="org-user-avatar">' + esc(initial(item.name)) + '</span><span class="org-user-info"><b>' + esc(item.name) + '</b><small>' + esc(codeOf(item)) + '</small></span>' + tag + (state.modal.multi ? '' : '<span class="org-pick-arrow">‹</span>') + '</div>';
            }).join('');
        }
        var count = state.modal.selected.length;
        $('#org-modal-info').innerHTML = count ? '<b>' + count + ' مورد</b> انتخاب شده' : 'موردی انتخاب نشده';
        $('#org-modal-confirm').disabled = count === 0;
        $$('#org-modal-body [data-org-modal-user]').forEach(function (row) {
            row.addEventListener('click', function () {
                var id = Number(row.getAttribute('data-org-modal-user'));
                var selected = state.modal.users.find(function (item) { return Number(item.id) === id; });
                if (!selected) return;
                if (!state.modal.multi) {
                    var callback = state.modal.onPick;
                    closeModal();
                    if (callback) callback(selected);
                    return;
                }
                var index = state.modal.selected.indexOf(id);
                if (index === -1) state.modal.selected.push(id); else state.modal.selected.splice(index, 1);
                renderModal();
            });
        });
    };

    var renderLeftSidebar = function () {
        var ceo = root();
        var deputies = ceo ? children(ceo.id, 'deputy') : [];
        var departments = allDepartments();
        var expertCount = users.filter(function (item) { return roleOf(item) === 'expert'; }).length;
        var freeCount = poolUsers().length;
        var assigned = users.length - freeCount;
        var progress = users.length ? Math.round(assigned * 100 / users.length) : 0;
        var stat = function (key, icon, label, number) { return '<button class="org-stat ' + (state.filterStat === key ? 'active' : '') + '" data-org-filter="' + key + '"><span class="org-stat-icon ' + key + '">' + iconFor(icon) + '</span><span>' + label + '</span><b>' + number + '</b></button>'; };
        var quick = function (type, label, action, tone) { return '<button class="org-big-add ' + (tone || '') + '" data-org-action="' + action + '"><span class="org-action-symbol">' + iconFor(type) + '</span><span>' + label + '</span></button>'; };
        var quickUnits = quick('deputy', 'افزودن معاونت', 'add-deputy') + (deputies.length ? quick('department', 'افزودن اداره', 'quick-department', 'green') : '<p class="org-side-note">برای ساخت اداره، ابتدا یک معاونت بسازید.</p>') + (departments.length ? quick('expert', 'افزودن کارشناس', 'quick-expert', 'amber') : '<p class="org-side-note">کارشناس بعد از ساخت اداره اضافه می‌شود.</p>');
        $('#org-sidebar').innerHTML = '<section class="org-sidebar-section"><h4>آمار سازمان</h4>' + stat('ceo', 'ceo', 'مدیرعامل', ceo && ceo.managerId ? 1 : 0) + stat('dep', 'deputy', 'معاونت', deputies.length) + stat('dept', 'department', 'اداره', departments.length) + stat('expert', 'expert', 'کارشناس', expertCount) + stat('pool', 'pool', 'آزاد', freeCount) + '<div class="org-progress"><div><span>پیشرفت چیدمان</span><b>' + progress + '٪</b></div><i style="width:' + progress + '%"></i></div></section>' +
            '<section class="org-sidebar-section"><h4>افزودن سریع</h4>' + quickUnits + '</section><section class="org-sidebar-section"><h4>راهنما</h4><div class="org-help"><b>کلیک کارت</b> = تغییر مسئول<br>⋮ <b>منوی کارت</b> = عملیات بیشتر<br>↕ <b>کشیدن و رها</b> = نصب سریع<br>↶ <b>بازگشت</b> = آخرین عملیات</div></section>';
    };
    var renderPoolSidebar = function () {
        var list;
        var title = 'کاربران آزاد';
        if (state.filterStat === 'ceo') { list = root() && root().managerId ? [person(root().managerId)] : []; title = 'مدیرعامل'; }
        else if (state.filterStat === 'dep') { list = users.filter(function (item) { return roleOf(item) === 'dep'; }); title = 'مدیران معاونت'; }
        else if (state.filterStat === 'dept') { list = users.filter(function (item) { return roleOf(item) === 'dept'; }); title = 'رؤسای ادارات'; }
        else if (state.filterStat === 'expert') { list = users.filter(function (item) { return roleOf(item) === 'expert'; }); title = 'کارشناسان'; }
        else list = poolUsers();
        list = list.filter(Boolean);
        var filtered = list.filter(function (item) { return !state.poolSearch || normalize(item.name).indexOf(normalize(state.poolSearch)) !== -1 || normalize(codeOf(item)).indexOf(normalize(state.poolSearch)) !== -1; });
         $('#org-pool-sidebar').innerHTML = '<div class="org-pool-header"><div><b>' + title + '</b><span>' + list.length + '</span></div><small>' + (state.filterStat ? 'برای بازگشت، دوباره روی آمار کلیک کنید' : 'افراد را بکشید و روی موقعیت رها کنید') + '</small>' + (state.filterStat ? '<button class="org-clear-filter" data-org-filter="clear">حذف فیلتر</button>' : '') + '</div><div class="org-pool-search"><input id="org-pool-search" type="search" value="' + esc(state.poolSearch) + '" placeholder="جستجوی کاربر..."></div><div class="org-pool-list" id="org-pool-list">' + (filtered.length ? filtered.map(function (item) { return '<div class="org-pool-user" draggable="true" data-pool-user="' + item.id + '"><span class="org-pool-avatar">' + esc(initial(item.name)) + '</span><span><b>' + esc(item.name) + '</b><small>' + esc(codeOf(item)) + '</small></span></div>'; }).join('') : '<div class="org-pool-empty">—<br>' + (state.filterStat ? 'موردی نیست' : 'همه تخصیص یافتند') + '</div>') + '</div>';
        var search = $('#org-pool-search');
        if (search) search.addEventListener('input', function (event) { state.poolSearch = event.target.value; renderPoolSidebar(); });
        $$('#org-pool-list [data-pool-user]').forEach(function (item) {
            item.addEventListener('dragstart', function (event) { event.dataTransfer.setData('text/plain', JSON.stringify({ userId: Number(item.getAttribute('data-pool-user')) })); item.classList.add('dragging'); });
            item.addEventListener('dragend', function () { item.classList.remove('dragging'); });
        });
    };

    var nodeMatch = function (item) { return match(item) ? ' is-match' : ''; };
    var nodeMenuButton = function (unitId) { return data.canManage ? '<button class="org-node-menu-btn" data-org-menu="open" data-unit-id="' + unitId + '">⋮</button>' : ''; };
    var renderCeo = function () {
        var ceo = root();
        var manager = ceo && person(ceo.managerId);
        if (!manager) return '<div class="org-node org-empty-slot org-ceo-slot" data-unit="' + ceo.id + '" data-drop="ceo" data-org-action="pick-ceo"><span class="org-empty-icon">' + iconFor('ceo') + '</span><b>تعیین مدیرعامل</b><small>کلیک یا کشیدن کاربر</small>' + (data.canManage ? '<button class="org-node-add" data-org-action="add-deputy">+ افزودن معاونت</button>' : '') + '</div>';
        return '<div class="org-node org-ceo' + nodeMatch(manager) + '" data-unit="' + ceo.id + '" data-drop="ceo"><div class="org-node-menu-wrap">' + nodeMenuButton(ceo.id) + '</div><div class="org-avatar">' + esc(initial(manager.name)) + '</div><div class="org-node-name">' + esc(manager.name) + '</div><div class="org-node-title"><span class="org-title-icon">' + iconFor('manager') + '</span> مدیرعامل سازمان</div><div class="org-node-code">' + esc(codeOf(manager)) + '</div><div class="org-node-count">' + iconFor('deputy') + ' ' + children(ceo.id, 'deputy').length + ' معاونت</div>' + (data.canManage ? '<button class="org-node-add" data-org-action="add-deputy">+ افزودن معاونت</button>' : '') + '</div>';
    };
    var renderDeputy = function (item) {
        var manager = person(item.managerId);
        var extra = !manager && data.canManage ? '<button class="org-node-add" data-org-action="pick-manager" data-unit-id="' + item.id + '">' + iconFor('manager') + ' تعیین مدیر</button>' : '';
        var title = manager ? iconFor('manager') + ' مدیر ' + esc(item.name) : iconFor('deputy') + ' بدون مدیر';
        return '<div class="org-node org-deputy' + nodeMatch(item) + '" data-unit="' + item.id + '" data-drop="deputy"><div class="org-node-menu-wrap">' + nodeMenuButton(item.id) + '</div><div class="org-avatar ' + (!manager ? 'org-warning-avatar' : '') + '">' + (manager ? esc(initial(manager.name)) : iconFor('deputy')) + '</div><div class="org-node-name">' + esc(manager ? manager.name : item.name) + '</div><div class="org-node-title ' + (!manager ? 'org-warning-text' : '') + '">' + title + '</div>' + (manager ? '<div class="org-node-code">' + esc(codeOf(manager)) + '</div>' : '') + '<div class="org-node-count">' + iconFor('department') + ' ' + children(item.id, 'department').length + ' اداره</div>' + extra + (data.canManage ? '<button class="org-node-add" data-org-action="add-department" data-unit-id="' + item.id + '">+ افزودن اداره</button>' : '') + '</div>';
    };
    var renderDepartment = function (item) {
        var manager = person(item.managerId);
        var experts = expertsIn(item.id);
        var extra = !manager && data.canManage ? '<button class="org-node-add" data-org-action="pick-manager" data-unit-id="' + item.id + '">' + iconFor('manager') + ' تعیین رئیس</button>' : '';
        var title = manager ? iconFor('manager') + ' رئیس ' + esc(item.name) : iconFor('department') + ' بدون رئیس';
        return '<div class="org-node org-department' + nodeMatch(item) + '" data-unit="' + item.id + '" data-drop="department"><div class="org-node-menu-wrap">' + nodeMenuButton(item.id) + '</div><div class="org-avatar ' + (!manager ? 'org-warning-avatar' : '') + '">' + (manager ? esc(initial(manager.name)) : iconFor('department')) + '</div><div class="org-node-name">' + esc(manager ? manager.name : item.name) + '</div><div class="org-node-title ' + (!manager ? 'org-warning-text' : '') + '">' + title + '</div>' + (manager ? '<div class="org-node-code">' + esc(codeOf(manager)) + '</div>' : '') + '<div class="org-node-count">' + iconFor('expert') + ' ' + experts.length + ' کارشناس</div>' + (data.canManage ? '<button class="org-node-add" data-org-action="add-experts" data-unit-id="' + item.id + '">+ افزودن کارشناس</button>' : '') + extra + '</div>';
    };
    var renderExperts = function (item) {
        var experts = expertsIn(item.id);
        if (!experts.length) return '<ul><li><div class="org-node org-empty-slot org-empty-small" data-drop="experts" data-unit-id="' + item.id + '" data-org-action="add-experts"><span class="org-empty-icon">' + iconFor('expert') + '</span><b>افزودن کارشناس</b><small>کلیک یا کشیدن کاربر</small></div></li></ul>';
        return '<ul><li><div class="org-node org-experts-group" data-drop="experts" data-unit-id="' + item.id + '"><div class="org-node-menu-wrap">' + nodeMenuButton(item.id) + '</div><div class="org-avatar org-expert-avatar">' + iconFor('expert') + '</div><div class="org-node-name">' + experts.length + ' کارشناس</div><div class="org-node-title">' + experts.slice(0, 3).map(function (personItem) { return esc(personItem.name.split(' ')[0]); }).join(' • ') + (experts.length > 3 ? ' +' + (experts.length - 3) : '') + '</div><div class="org-node-count">مدیریت کارشناسان</div></div></li></ul>';
    };
    var renderChart = function () {
        var ceo = root();
        var wrap = $('#org-chart-wrap');
        var deputies = ceo ? children(ceo.id, 'deputy') : [];
        if (!ceo) {
            wrap.innerHTML = '<div class="org-start-screen"><div class="org-start-icon">' + iconFor('start') + '</div><h2>شروع ساختار سازمانی</h2><p>در سه مرحله ساده، ساختار سازمان را بسازید.</p><div class="org-start-steps"><span><b>۱</b> مدیرعامل</span><span><b>۲</b> معاونت و اداره</span><span><b>۳</b> کارشناسان</span></div><button class="org-btn org-btn-primary" data-org-action="pick-ceo">تعیین مدیرعامل</button><button class="org-btn org-btn-ghost" data-org-action="add-deputy">افزودن معاونت بدون مدیر</button></div>';
            return;
        }
        var html = '<div class="org-tree"><ul><li>' + renderCeo();
        if (deputies.length) {
            html += '<ul>';
            deputies.forEach(function (deputy) {
                html += '<li>' + renderDeputy(deputy);
                var departments = children(deputy.id, 'department');
                if (departments.length) {
                    html += '<ul>';
                    departments.forEach(function (department) { html += '<li>' + renderDepartment(department) + renderExperts(department) + '</li>'; });
                    html += '</ul>';
                }
                html += '</li>';
            });
            html += '</ul>';
        }
        $('#org-chart-wrap').innerHTML = html + '</li></ul></div>';
        $('#org-chart-wrap').style.transform = 'scale(' + state.zoom + ')';
        $('#org-zoom-level').textContent = Math.round(state.zoom * 100) + '٪';
        bindDropTargets();
    };

    var openManagerPicker = function (target) {
        var current = target.managerId ? person(target.managerId) : null;
        openModal({ icon: iconFor(target.type), title: (current ? 'تغییر ' : 'تعیین ') + (target.type === 'ceo' ? 'مدیرعامل' : target.type === 'deputy' ? 'مدیر «' + target.name + '»' : 'رئیس «' + target.name + '»'), subtitle: 'روی کاربر موردنظر کلیک کنید', users: users, currentUser: current, onPick: function (selected) { submit('assign_org_manager', { target_type: target.type, unit_id: target.id, user_id: selected.id }); } });
    };
    var addExperts = function (unitId) {
        var target = unit(unitId);
        if (!target) return;
        openModal({ icon: iconFor('expert'), title: 'افزودن کارشناس به «' + target.name + '»', subtitle: 'چند نفر را با تیک انتخاب کنید', users: poolUsers(), poolOnly: true, multi: true, onConfirm: function (selected) { submit('save_org_assign_users', { member_unit_id: unitId, user_ids: selected.map(function (item) { return item.id; }) }); } });
    };
    var quickAddDepartment = function () {
        var deputies = children(root() ? root().id : 0, 'deputy');
        if (!deputies.length) return toast('ابتدا معاونت بسازید', iconFor('warning'));
        if (deputies.length === 1) return showInlineAddDepartment(deputies[0].id);
        openModal({ icon: iconFor('department'), title: 'افزودن اداره', subtitle: 'معاونت مقصد را انتخاب کنید', users: deputies.map(function (item) { return { id: item.id, name: item.name, code: children(item.id, 'department').length + ' اداره', role: 'choice' }; }), custom: true, onPick: function (selected) { showInlineAddDepartment(selected.id); } });
    };
    var quickAddExpert = function () {
        var departments = allDepartments();
        if (!departments.length) return toast('ابتدا اداره بسازید', iconFor('warning'));
        if (departments.length === 1) return addExperts(departments[0].id);
        openModal({ icon: iconFor('expert'), title: 'افزودن کارشناس', subtitle: 'اداره مقصد را انتخاب کنید', users: departments.map(function (item) { var parent = unit(item.parentId); return { id: item.id, name: item.name, code: parent ? parent.name : 'اداره', role: 'choice' }; }), custom: true, onPick: function (selected) { addExperts(selected.id); } });
    };
    var showInline = function (type, parentId) {
        var anchor = type === 'deputy' ? ($('.org-node.org-ceo') || $('.org-node.org-ceo-slot')) : $('.org-node.org-deputy[data-unit="' + parentId + '"]');
        if (!anchor) return;
        $$('.org-inline-form-wrap').forEach(function (item) { item.remove(); });
        var wrapper = document.createElement('div');
        wrapper.className = 'org-inline-form-wrap';
        wrapper.innerHTML = type === 'deputy' ? '<div class="org-inline-form"><label>نام معاونت *</label><input data-inline-field="name" placeholder="معاونت فناوری"><label>کد اختیاری</label><input data-inline-field="code" placeholder="DEP-01"><div><button class="org-btn org-btn-success" data-inline-submit="deputy">✓ افزودن</button><button class="org-btn org-btn-ghost" data-inline-cancel>انصراف</button></div></div>' : '<div class="org-inline-form"><label>نام اداره *</label><input data-inline-field="name" placeholder="اداره فناوری اطلاعات"><div><button class="org-btn org-btn-success" data-inline-submit="department">✓ افزودن</button><button class="org-btn org-btn-ghost" data-inline-cancel>انصراف</button></div></div>';
        anchor.appendChild(wrapper);
        wrapper.addEventListener('click', function (event) { event.stopPropagation(); });
        wrapper.querySelector('[data-inline-field="name"]').focus();
        wrapper.querySelectorAll('input').forEach(function (input) { input.addEventListener('keydown', function (event) { if (event.key === 'Escape') wrapper.remove(); if (event.key === 'Enter') wrapper.querySelector('[data-inline-submit]').click(); }); });
        wrapper.querySelector('[data-inline-submit]').addEventListener('click', function () {
            var name = wrapper.querySelector('[data-inline-field="name"]').value.trim();
            var code = wrapper.querySelector('[data-inline-field="code"]');
            if (!name) return toast('نام الزامی است', iconFor('warning'));
            submit('save_org_node', { node_type: type, unit_name: name, unit_code: code ? code.value.trim() : '', parent_id: type === 'deputy' ? root().id : parentId, unit_manager_id: 0, is_primary_admin: 0 });
        });
        wrapper.querySelector('[data-inline-cancel]').addEventListener('click', function () { wrapper.remove(); });
    };
    // ویرایش نام، کد و (برای اداره) معاونت والد. مدیر فعلی و وضعیت مدیر اصلی دست‌نخورده می‌مانند.
    var showInlineEdit = function (target) {
        if (!target || !data.canManage) return;
        var node = $('.org-node[data-unit="' + target.id + '"]');
        if (!node) return;
        $$('.org-inline-form-wrap').forEach(function (item) { item.remove(); });
        var isDepartment = target.type === 'department';
        var parentOptions = isDepartment ? children(root() ? root().id : 0, 'deputy').map(function (item) {
            return '<option value="' + item.id + '"' + (Number(item.id) === Number(target.parentId) ? ' selected' : '') + '>' + esc(item.name) + '</option>';
        }).join('') : '';
        var wrapper = document.createElement('div');
        wrapper.className = 'org-inline-form-wrap';
        wrapper.innerHTML = '<div class="org-inline-form"><label>نام ' + (isDepartment ? 'اداره' : 'معاونت') + ' *</label><input data-inline-field="name" value="' + esc(target.name) + '">'
            + '<label>کد اختیاری</label><input data-inline-field="code" value="' + esc(target.code || '') + '">'
            + (isDepartment ? '<label>معاونت والد</label><select data-inline-field="parent">' + parentOptions + '</select>' : '')
            + '<div><button class="org-btn org-btn-success" data-inline-submit="edit">✓ ذخیره تغییرات</button><button class="org-btn org-btn-ghost" data-inline-cancel>انصراف</button></div></div>';
        node.appendChild(wrapper);
        wrapper.addEventListener('click', function (event) { event.stopPropagation(); });
        wrapper.querySelector('[data-inline-field="name"]').focus();
        wrapper.querySelectorAll('input').forEach(function (input) { input.addEventListener('keydown', function (event) { if (event.key === 'Escape') wrapper.remove(); if (event.key === 'Enter') wrapper.querySelector('[data-inline-submit]').click(); }); });
        wrapper.querySelector('[data-inline-submit]').addEventListener('click', function () {
            var name = wrapper.querySelector('[data-inline-field="name"]').value.trim();
            var code = wrapper.querySelector('[data-inline-field="code"]').value.trim();
            var parentSelect = wrapper.querySelector('[data-inline-field="parent"]');
            if (!name) return toast('نام الزامی است', iconFor('warning'));
            var fields = {
                node_type: target.type,
                unit_id: target.id,
                unit_name: name,
                unit_code: code,
                parent_id: parentSelect ? parentSelect.value : target.parentId,
                unit_manager_id: target.managerId || 0
            };
            // فقط وقتی مدیر اصلی است این فیلد را بفرست؛ ارسال مقدار 0 هم سرور را مجبور به تغییر آن می‌کرد.
            if (target.primaryAdmin) fields.is_primary_admin = 1;
            submit('save_org_node', fields);
        });
        wrapper.querySelector('[data-inline-cancel]').addEventListener('click', function () { wrapper.remove(); });
    };
    var showInlineAddDeputy = function () { if (root()) showInline('deputy', 0); else toast('ریشهٔ سازمان هنوز ساخته نشده است', iconFor('warning')); };
    var showInlineAddDepartment = function (parentId) { showInline('department', parentId); };

    var openExpertManager = function (unitId) {
        var target = unit(unitId);
        if (!target) return;
        var experts = expertsIn(unitId);
        var backdrop = document.createElement('div');
        backdrop.className = 'org-modal-backdrop show';
        backdrop.innerHTML = '<section class="org-modal org-expert-manager"><header><div class="org-modal-icon org-expert-avatar">' + iconFor('expert') + '</div><div><h2>مدیریت کارشناسان «' + esc(target.name) + '»</h2><p>' + experts.length + ' نفر</p></div><button class="org-modal-close" type="button">×</button></header><div class="org-manage-list">' + (experts.length ? experts.map(function (item) { return '<div class="org-manage-row"><span class="org-user-avatar">' + esc(initial(item.name)) + '</span><span class="org-user-info"><b>' + esc(item.name) + '</b><small>' + esc(codeOf(item)) + '</small></span><button class="org-btn org-btn-ghost org-remove-user" data-remove-user="' + item.id + '">' + iconFor('remove') + ' عزل</button></div>'; }).join('') : '<div class="org-modal-empty">کارشناسی در این اداره نیست.</div>') + '</div><footer><button class="org-btn org-btn-success" data-add-more>+ افزودن کارشناس</button></footer></section>';
        document.body.appendChild(backdrop);
        var close = function () { backdrop.remove(); };
        backdrop.addEventListener('click', function (event) { if (event.target === backdrop || event.target.closest('.org-modal-close')) close(); });
        backdrop.querySelector('[data-add-more]').addEventListener('click', function () { close(); addExperts(unitId); });
        backdrop.querySelectorAll('[data-remove-user]').forEach(function (button) {
            button.addEventListener('click', function () {
                var item = person(Number(button.getAttribute('data-remove-user')));
                if (!item) return;
                askConfirm({ icon: iconFor('expert'), title: 'عزل کارشناس', message: '«' + item.name + '» از این اداره عزل شود؟', details: 'کاربر از اداره جدا شده و در فهرست کاربران آزاد قرار می‌گیرد.', okText: 'بله، عزل کن', danger: true, onConfirm: function () { close(); submit('save_user_org', { user_id: item.id, org_unit_id: 0, manager_user_id: 0 }); } });
            });
        });
    };
    var removeAllExperts = function (unitId) {
        var experts = expertsIn(unitId);
        if (!experts.length) return;
        var target = unit(unitId);
        askConfirm({ icon: iconFor('expert'), title: 'عزل همه کارشناسان', message: experts.length + ' کارشناس از «' + target.name + '» عزل شوند؟', details: 'همهٔ آن‌ها به فهرست کاربران آزاد برمی‌گردند.', okText: 'بله، عزل کن', danger: true, onConfirm: function () { submit('remove_org_experts', { unit_id: unitId, user_ids: experts.map(function (item) { return item.id; }) }); } });
    };
    var removeUnit = function (unitId) {
        var target = unit(unitId);
        if (!target) return;
        var descendants = units.filter(function (item) { return Number(item.parentId) === Number(unitId); }).length;
        askConfirm({ icon: iconFor('delete'), title: 'حذف «' + target.name + '»', message: 'این واحد و زیرمجموعه‌های آن حذف شوند؟', details: 'واحدهای زیرمجموعه: ' + descendants + '<br>کاربران آن‌ها به فهرست آزاد برمی‌گردند.', okText: 'بله، حذف کن', danger: true, onConfirm: function () { submit('delete_org_unit', { unit_id: unitId }); } });
    };

    var renderMenu = function (button) {
        $$('.org-node-menu').forEach(function (item) { item.remove(); });
        var id = Number(button.getAttribute('data-unit-id'));
        var target = unit(id);
        if (!target) return;
        var menu = document.createElement('div');
        menu.className = 'org-node-menu';
        var hasManager = Number(target.managerId) > 0;
        var items = [];
        if (target.type === 'ceo') items = [{ icon: iconFor('manager'), label: 'تغییر مدیرعامل', action: function () { openManagerPicker(target); } }, { icon: iconFor('add'), label: 'افزودن معاونت', action: showInlineAddDeputy }, { divider: true }, { icon: iconFor('remove'), label: 'برداشتن از سمت', danger: true, action: function () { removeManager(target); } }];
        if (target.type === 'deputy') items = [{ icon: iconFor('manager'), label: hasManager ? 'تغییر مدیر' : 'تعیین مدیر', action: function () { openManagerPicker(target); } }, { icon: iconFor('add'), label: 'افزودن اداره', action: function () { showInlineAddDepartment(target.id); } }, { icon: iconFor('manage'), label: 'ویرایش معاونت', action: function () { showInlineEdit(target); } }, { divider: true }, ...(hasManager ? [{ icon: iconFor('remove'), label: 'برداشتن مدیر', danger: true, action: function () { removeManager(target); } }] : []), { icon: iconFor('delete'), label: 'حذف کل معاونت', danger: true, action: function () { removeUnit(target.id); } }];
        if (target.type === 'department') items = [{ icon: iconFor('manager'), label: hasManager ? 'تغییر رئیس' : 'تعیین رئیس', action: function () { openManagerPicker(target); } }, { icon: iconFor('manage'), label: 'ویرایش اداره', action: function () { showInlineEdit(target); } }, { icon: iconFor('expert'), label: 'افزودن کارشناس', action: function () { addExperts(target.id); } }, ...(expertsIn(target.id).length ? [{ icon: iconFor('manage'), label: 'مدیریت کارشناسان', action: function () { openExpertManager(target.id); } }, { icon: iconFor('delete'), label: 'عزل همه کارشناسان', danger: true, action: function () { removeAllExperts(target.id); } }] : []), { divider: true }, ...(hasManager ? [{ icon: iconFor('remove'), label: 'برداشتن رئیس', danger: true, action: function () { removeManager(target); } }] : []), { icon: iconFor('delete'), label: 'حذف کل اداره', danger: true, action: function () { removeUnit(target.id); } }];
        items.forEach(function (item) {
            if (item.divider) { var divider = document.createElement('div'); divider.className = 'org-menu-divider'; menu.appendChild(divider); return; }
            var row = document.createElement('button'); row.type = 'button'; row.className = 'org-menu-item' + (item.danger ? ' danger' : ''); row.innerHTML = '<span>' + item.icon + '</span><b>' + esc(item.label) + '</b>'; row.addEventListener('click', function (event) { event.stopPropagation(); menu.remove(); item.action(); }); menu.appendChild(row);
        });
        button.closest('.org-node').appendChild(menu);
        // اگر منو از پایین صفحه بیرون می‌زند، به سمت بالای کارت باز شود تا همهٔ گزینه‌ها (از جمله حذف) دیده شوند.
        var menuBox = menu.getBoundingClientRect();
        if (menuBox.bottom > window.innerHeight - 8) { menu.style.top = 'auto'; menu.style.bottom = '36px'; }
    };
    var removeManager = function (target) {
        if (!target.managerId) return;
        var manager = person(target.managerId);
        askConfirm({ icon: iconFor('manager'), title: 'عزل مدیر', message: '«' + (manager ? manager.name : 'کاربر') + '» از «' + target.name + '» عزل شود؟', details: 'خود واحد باقی می‌ماند و فقط سمت مدیر خالی می‌شود.', okText: 'بله، عزل کن', danger: true, onConfirm: function () { submit('remove_org_manager', { target_type: target.type, unit_id: target.id }); } });
    };
    var handleAction = function (element) {
        var action = element.getAttribute('data-org-action');
        var id = Number(element.getAttribute('data-unit-id') || 0);
        if (action === 'pick-ceo') return openManagerPicker(root());
        if (action === 'add-deputy') return showInlineAddDeputy();
        if (action === 'add-department') return showInlineAddDepartment(id);
        if (action === 'quick-department') return quickAddDepartment();
        if (action === 'quick-expert') return quickAddExpert();
        if (action === 'pick-manager') return openManagerPicker(unit(id));
        if (action === 'add-experts') return addExperts(id);
    };
    var bindDropTargets = function () {
        $$('#org-chart-wrap [data-drop]').forEach(function (target) {
            target.addEventListener('dragover', function (event) { event.preventDefault(); target.classList.add('drop-target'); });
            target.addEventListener('dragleave', function () { target.classList.remove('drop-target'); });
            target.addEventListener('drop', function (event) {
                event.preventDefault(); target.classList.remove('drop-target');
                var payload; try { payload = JSON.parse(event.dataTransfer.getData('text/plain')); } catch (error) { return; }
                var selected = person(payload.userId); if (!selected) return;
                var targetUnit = unit(Number(target.getAttribute('data-unit-id') || target.getAttribute('data-unit')));
                if (target.getAttribute('data-drop') === 'experts' && targetUnit) submit('save_org_assign_users', { member_unit_id: targetUnit.id, user_ids: [selected.id] });
                else if (target.getAttribute('data-drop') === 'ceo') submit('assign_org_manager', { target_type: 'ceo', unit_id: root().id, user_id: selected.id });
                else if (targetUnit) submit('assign_org_manager', { target_type: targetUnit.type, unit_id: targetUnit.id, user_id: selected.id });
            });
        });
    };

    document.addEventListener('click', function (event) {
        var modalAction = event.target.closest('[data-org-modal]');
        if (modalAction) { closeModal(); return; }
        var confirmAction = event.target.closest('[data-org-confirm]');
        if (confirmAction) { closeConfirm(); return; }
        var undoClose = event.target.closest('[data-org-undo="close"]');
        if (undoClose) { $('#org-undo-bar').classList.remove('show'); return; }
        var filter = event.target.closest('[data-org-filter]');
        if (filter) { var key = filter.getAttribute('data-org-filter'); state.filterStat = key === 'clear' || state.filterStat === key ? null : key; renderLeftSidebar(); renderPoolSidebar(); return; }
        var menuButton = event.target.closest('[data-org-menu]');
        if (menuButton) { event.stopPropagation(); renderMenu(menuButton); return; }
        var action = event.target.closest('[data-org-action]');
        if (action) { event.stopPropagation(); handleAction(action); return; }
        var tool = event.target.closest('[data-org-tool]');
        if (tool) {
            var name = tool.getAttribute('data-org-tool');
            if (name === 'export') exportData();
            if (name === 'reset') askReset();
            if (name === 'undo') {
                if (data.undo) submit('undo_org_action', {}); else toast('عملیاتی برای بازگشت وجود ندارد.', 'ℹ️');
            }
            return;
        }
        if (!event.target.closest('.org-node-menu')) $$('.org-node-menu').forEach(function (item) { item.remove(); });
    });
    document.addEventListener('click', function (event) {
        if (event.target.closest('.org-inline-form-wrap')) return;
        var node = event.target.closest('.org-node[data-unit]');
        if (node && !event.target.closest('button') && data.canManage) openManagerPicker(unit(Number(node.getAttribute('data-unit'))));
    });
    $('#org-confirm-ok').addEventListener('click', function () { var callback = state.confirm; closeConfirm(); if (callback) callback(); });
    $('#org-confirm-backdrop').addEventListener('click', function (event) { if (event.target.id === 'org-confirm-backdrop') closeConfirm(); });
    $('#org-modal-search').addEventListener('input', function (event) { if (state.modal) { state.modal.search = event.target.value; renderModal(); } });
    $('#org-modal-confirm').addEventListener('click', function () { if (!state.modal || !state.modal.selected.length) return; var selected = state.modal.selected.map(function (id) { return state.modal.users.find(function (item) { return Number(item.id) === id; }); }).filter(Boolean); var callback = state.modal.onConfirm; closeModal(); if (callback) callback(selected); });
    $('#org-modal-backdrop').addEventListener('click', function (event) { if (event.target.id === 'org-modal-backdrop') closeModal(); });
    $('#org-chart-search').addEventListener('input', function (event) { state.search = event.target.value; renderChart(); });
    $$('[data-org-zoom]').forEach(function (button) { button.addEventListener('click', function () { var action = button.getAttribute('data-org-zoom'); if (action === 'in') state.zoom = Math.min(1.4, state.zoom + .1); if (action === 'out') state.zoom = Math.max(.6, state.zoom - .1); if (action === 'reset') state.zoom = 1; renderChart(); }); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { closeModal(); closeConfirm(); $$('.org-node-menu').forEach(function (item) { item.remove(); }); } });

    var askReset = function () {
        var hasStructure = units.some(function (item) { return item.type !== 'ceo'; }) || (root() && root().managerId);
        if (!hasStructure) return toast('ساختار خالی است', 'ℹ️');
        askConfirm({ icon: iconFor('reset'), title: 'شروع مجدد', message: 'تمام ساختار پاک شود؟', details: 'معاونت‌ها، اداره‌ها و انتصاب کاربران حذف می‌شوند و کاربران به فهرست آزاد برمی‌گردند.', okText: 'بله، پاک کن', danger: true, onConfirm: function () { submit('reset_org_structure', {}); } });
    };
    var exportData = function () {
        var payload = { units: units, users: users, exportedAt: new Date().toISOString() };
        var url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' }));
        var link = document.createElement('a'); link.href = url; link.download = 'org-structure-' + Date.now() + '.json'; link.click(); URL.revokeObjectURL(url); toast('فایل JSON دانلود شد', '↓');
    };
    var renderAll = function () {
        renderLeftSidebar(); renderChart(); renderPoolSidebar();
        $$('[data-org-tool="undo"]').forEach(function (button) { button.disabled = !data.undo; });
        var undoBar = $('#org-undo-bar');
        if (undoBar) {
            window.clearTimeout(undoBar._timer);
            undoBar.classList.toggle('show', !!data.undo);
            var undoText = $('#org-undo-text');
            if (undoText) undoText.textContent = data.undoLabel || 'آخرین عملیات';
            if (data.undo) undoBar._timer = window.setTimeout(function () { undoBar.classList.remove('show'); }, 2000);
        }
    };
    renderAll();
}());
