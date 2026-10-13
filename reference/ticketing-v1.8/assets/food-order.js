/* صفحهٔ کارمند: ثبت سفارش برای خود/نیابت، بدون افشای شناسه‌های کارکنان در جست‌وجو. */
(function () {
  'use strict';

  var root = document.getElementById('food-order-app');
  if (!root) return;

  var Cal = window.FoodOrderCalendar;
  var selfId = Number(root.dataset.selfId || 0);
  var selfName = root.dataset.selfName || 'کاربر';
  var today = root.dataset.today || '';
  var todayJalali = root.dataset.todayJalali || '';
  var hasNational = root.dataset.hasNational === '1';
  var canProxy = root.dataset.canProxy === '1';
  var apiBase = (root.dataset.api || '').replace(/&amp;/g, '&');
  var csrf = root.dataset.csrf || '';
  var current = todayJalali.match(/^(\d{4})\/(\d{2})\/(\d{2})$/);
  var state = {
    year: current ? Number(current[1]) : 1405,
    month: current ? Number(current[2]) : 1,
    monthData: null,
    orders: [],
    error: '',
    modalDate: '',
    recipientReady: false,
    flowStage: 'choice',
    flowDismissed: false,
    flowError: '',
    flowSaving: false,
    flowRequestId: 0,
    targetMode: 'self',
    targetId: selfId,
    targetName: selfName,
    listFrom: '',
    listTo: '',
    listRange: null,
    listError: '',
    proxyOrders: null,
    proxyLoading: false,
    proxyError: '',
    nationalCode: '',
    proxyCancelingOrderId: 0,
    proxyCancelConfirmingOrderId: 0,
    employeeChoices: [],
    employeeChoicesLoaded: false,
    employeeChoicesLoading: false,
    dropdownOpen: true,
    highlightedIndex: -1,
    searchQuery: '',
    loading: true,
    dataLoaded: false,
    pageNotice: null,
  };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
    });
  }
  function faDigits(value) { return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; }); }
  function normalizedDigits(value) {
    return String(value == null ? '' : value).replace(/[۰-۹٠-٩]/g, function (d) {
      var s = '۰۱۲۳۴۵۶۷۸۹'; var a = '٠١٢٣٤٥٦٧٨٩';
      var p = s.indexOf(d); return p >= 0 ? String(p) : String(a.indexOf(d));
    }).replace(/\D/g, '');
  }
  function normalizeSearch(value) {
    return String(value == null ? '' : value).toLocaleLowerCase('fa')
      .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\s]+/g, ' ').trim();
  }
  function employeeMatches() {
    var query = normalizeSearch(state.searchQuery);
    return (state.employeeChoices || []).filter(function (person) {
      return !query || normalizeSearch(person.name).indexOf(query) !== -1;
    });
  }
  function monthKey() { return String(state.year).padStart(4, '0') + '-' + String(state.month).padStart(2, '0'); }
  function monthLabel() { return Cal.months[state.month - 1] + ' ' + faDigits(state.year); }
  function isoToJalali(iso) {
    try {
      var parts = Cal.jalaliDateFromIso(iso);
      return parts ? String(parts[0]).padStart(4, '0') + '/' + String(parts[1]).padStart(2, '0') + '/' + String(parts[2]).padStart(2, '0') : '';
    } catch (e) { return ''; }
  }
  function holidayTitle(iso, day) {
    if (day && day.holiday_title) return day.holiday_title;
    var list = window.ITSM_HOLIDAYS || [];
    for (var i = 0; i < list.length; i++) {
      var item = list[i] || {};
      if (String(item.date || '').slice(0, 10) === iso || String(item.jalali || '').replace(/-/g, '/') === isoToJalali(iso)) return item.title || 'تعطیل رسمی';
    }
    return '';
  }
  function isPast(iso) {
    try { return new Date(iso + 'T00:00:00Z') < new Date(today + 'T00:00:00Z'); } catch (e) { return false; }
  }
  async function request(route, params, options) {
    var query = params ? new URLSearchParams(params).toString() : '';
    var url = apiBase + encodeURIComponent(route) + (query ? '&' + query : '');
    var opts = Object.assign({ method: 'GET', credentials: 'same-origin', headers: {} }, options || {});
    opts.headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
    if (opts.method !== 'GET' && csrf) opts.headers['X-CSRF-Token'] = csrf;
    var response = await fetch(url, opts);
    var text = await response.text();
    var payload;
    try { payload = text ? JSON.parse(text) : {}; } catch (e) { throw new Error('پاسخ JSON معتبر از سرور دریافت نشد (کد HTTP ' + response.status + ').'); }
    if (!response.ok) throw new Error(payload.error || payload.message || ('خطای سرویس ' + response.status));
    return payload || {};
  }
  function toast(message, bad) {
    var old = root.querySelector('.fo-inline-toast');
    if (old) old.remove();
    var node = document.createElement('div');
    node.className = 'alert ' + (bad ? 'danger' : 'success') + ' fo-inline-toast';
    node.setAttribute('role', 'status'); node.textContent = message;
    root.insertBefore(node, root.firstChild);
    window.setTimeout(function () { node.remove(); }, 4800);
  }

  async function loadMonth() {
    state.loading = true; state.error = '';
    render();
    try {
      if (state.targetMode === 'proxy') {
        if (!state.targetId || normalizedDigits(state.nationalCode).length !== 10) throw new Error('برای مشاهدهٔ سفارش این شخص، ابتدا هویت او را دوباره تأیید کنید.');
        state.monthData = await request('proxy-month', null, { method: 'POST', body: JSON.stringify({ month: monthKey(), employee_id: state.targetId, national_code: normalizedDigits(state.nationalCode) }) });
      } else {
        state.monthData = await request('month', { month: monthKey() });
      }
    } catch (e) {
      state.error = e.message || 'تقویم بارگذاری نشد.';
      state.monthData = null;
    } finally {
      state.loading = false;
      render();
    }
  }

  // فهرست سفارش‌ها: در حالت «خودم» از my-orders، در حالت «دیگری» از proxy-orders، هر دو با بازهٔ انتخابی.
  async function loadMyOrders() {
    if (state.targetMode === 'proxy') return loadProxyOrders();
    state.listError = '';
    try {
      var params = {};
      if (state.listFrom) params.from = state.listFrom;
      if (state.listTo) params.to = state.listTo;
      var payload = await request('my-orders', params);
      state.orders = Array.isArray(payload.items) ? payload.items : [];
      state.listRange = payload.range || state.listRange;
    } catch (e) {
      state.orders = [];
      state.listError = e.message || 'سفارش‌های شما بارگذاری نشد.';
    }
    render();
  }

  async function loadProxyOrders() {
    if (!state.recipientReady || !state.targetId) return;
    var code = normalizedDigits(state.nationalCode);
    state.proxyOrders = null;
    state.proxyError = '';
    if (code.length !== 10) {
      state.proxyOrders = [];
      state.proxyError = 'برای مشاهدهٔ سفارش این شخص، ابتدا هویت او را دوباره تأیید کنید.';
      render();
      return;
    }
    state.proxyLoading = true;
    render();
    try {
      var payload = await request('proxy-orders', null, {
        method: 'POST',
        body: JSON.stringify({ employee_id: state.targetId, national_code: code, from: state.listFrom, to: state.listTo })
      });
      state.proxyOrders = Array.isArray(payload.items) ? payload.items : [];
      state.listRange = payload.range || state.listRange;
    } catch (e) {
      state.proxyOrders = [];
      state.proxyError = e.message || 'سفارش‌های این شخص بارگذاری نشد.';
    } finally {
      state.proxyLoading = false;
      render();
    }
  }

  // بازهٔ وارد‌شده در فیلدهای شمسی را می‌خواند و فهرست را دوباره می‌گیرد. خالی = پیش‌فرض (امروز تا پایان ماه).
  function applyListRange() {
    var fromInput = root.querySelector('[data-fo-list-from]');
    var toInput = root.querySelector('[data-fo-list-to]');
    state.listFrom = fromInput ? fromInput.value.trim() : '';
    state.listTo = toInput ? toInput.value.trim() : '';
    loadMyOrders();
  }

  function renderListFilter() {
    if (state.targetMode === 'proxy' && !state.recipientReady) return '';
    var shownFrom = state.listFrom || (state.listRange ? isoToJalali(state.listRange.from) : '');
    var shownTo = state.listTo || (state.listRange ? isoToJalali(state.listRange.to) : '');
    return '<div class="card fo-list-filter" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:14px">'
      + '<label>از تاریخ (شمسی)<input type="text" inputmode="numeric" autocomplete="off" data-jalali data-fo-list-from value="' + esc(faDigits(shownFrom)) + '" placeholder="۱۴۰۵/۰۷/۰۱" style="display:block;margin-top:4px"></label>'
      + '<label>تا تاریخ (شمسی)<input type="text" inputmode="numeric" autocomplete="off" data-jalali data-fo-list-to value="' + esc(faDigits(shownTo)) + '" placeholder="۱۴۰۵/۰۷/۳۰" style="display:block;margin-top:4px"></label>'
      + '<button class="button" type="button" data-fo-action="apply-list-range">نمایش بازه</button>'
      + '<button class="button secondary" type="button" data-fo-action="reset-list-range">ماه جاری (از امروز)</button>'
      + '<small class="field-help" style="flex-basis:100%">پیش‌فرض: از امروز تا پایان ماه جاری. غذای روزهای گذشته خودکار از این فهرست خارج می‌شود.</small>'
      + (state.listError ? '<div class="alert danger" style="flex-basis:100%">' + esc(state.listError) + '</div>' : '')
      + '</div>';
  }

  function dayStatus(day, holiday, cell) {
    if (holiday) return 'تعطیل رسمی';
    if (!day || !day.calendar_id || !Array.isArray(day.items) || day.items.length === 0) return 'بدون برنامه';
    if (day.order_status === 'closed') return 'سفارش بسته';
    if (day.order_status === 'open') return 'سفارش باز';
    return 'دارای غذا';
  }

  function guestOrderButton() {
    // فقط وقتی سفارش برای خود کاربر است؛ در حالت نیابتی (سفارش برای پرسنل) دیده نمی‌شود
    if (root.dataset.guestAllowed !== '1' || state.targetMode !== 'self') return '';
    return '<button type="button" class="button" data-fo-action="guest-order">سفارش غذای مهمان</button>';
  }

  function renderCalendar() {
    if (!state.monthData && state.loading) return '<div class="card fo-calendar-card"><div class="fo-loading-panel"><strong>در حال بارگذاری تقویم…</strong></div></div>';
    var map = state.monthData && state.monthData.days ? state.monthData.days : {};
    var cells = Cal.monthCells(state.year, state.month);
    var headings = Cal.weekdays.map(function (name) { return '<div class="fo-weekday" role="columnheader">' + esc(name) + '</div>'; }).join('');
    var content = cells.map(function (cell) {
      var day = map[cell.iso] || null;
      var holiday = holidayTitle(cell.iso, day);
      var todayFlag = cell.iso === today;
      var items = day && Array.isArray(day.items) ? day.items : [];
      var status = dayStatus(day, holiday, cell);
      var classes = ['fo-day'];
      var adjacentAttrs = cell.isCurrentMonth ? '' : ' disabled aria-disabled="true" tabindex="-1"';
      if (!cell.isCurrentMonth) classes.push('is-adjacent');
      if (cell.isWeekend) classes.push('is-weekend');
      if (holiday) classes.push('is-holiday');
      if (todayFlag) classes.push('is-today');
      if (day && day.order_status === 'closed') classes.push('is-closed');
      var selectedOrder = day && day.my_order ? day.my_order : null;
      var orderOwnerLabel = state.targetMode === 'proxy' ? ('سفارش برای ' + state.targetName) : 'سفارش شما';
      if (selectedOrder) classes.push('has-order');
      var tip = [cell.jalali, status, holiday ? holiday : '', items.map(function (x) { return x.food_name; }).join('، '), selectedOrder ? (orderOwnerLabel + ': ' + selectedOrder.food_name) : ''].filter(Boolean).join(' • ');
      var badges = '<span class="fo-day-status ' + (day && day.order_status === 'open' ? 'is-open' : day && day.order_status === 'closed' ? 'is-closed' : '') + '">' + esc(status) + '</span>';
      if (todayFlag) badges += '<span class="fo-day-status">امروز</span>';
      var orderMarker = selectedOrder ? '<span class="fo-day-order-marker" title="' + esc(orderOwnerLabel + ': ' + selectedOrder.food_name) + '">✓ سفارش</span>' : '';
      var meals = items.map(function (item) { return '<span class="fo-day-meal">' + esc(item.food_name) + '</span>'; }).join('');
      if (selectedOrder) meals += '<span class="fo-day-my-order">✓ ' + esc(orderOwnerLabel) + ': ' + esc(selectedOrder.food_name) + '</span>';
      return '<button type="button" class="' + classes.join(' ') + '" data-fo-day="' + esc(cell.iso) + '" data-current-month="' + (cell.isCurrentMonth ? '1' : '0') + '" data-has-food="' + (items.length ? '1' : '0') + '" data-has-order="' + (selectedOrder ? '1' : '0') + '" data-tooltip="' + esc(tip) + '" aria-label="' + esc(cell.jalali + '، ' + status + (holiday ? '، ' + holiday : '') + (items.length ? '، ' + items.map(function (x) { return x.food_name; }).join('، ') : '') + (selectedOrder ? '، ' + orderOwnerLabel + ': ' + selectedOrder.food_name : '')) + '"' + adjacentAttrs + '><span class="fo-day-number-row"><span class="fo-day-number">' + faDigits(cell.day) + '</span>' + (holiday ? '<span class="fo-day-status is-holiday">تعطیل</span>' : '') + '</span>' + badges + orderMarker + '<span class="fo-day-meals">' + meals + '</span></button>';
    }).join('');
    return '<div class="card fo-calendar-card"><div class="fo-calendar-toolbar"><h2>تقویم شمسی</h2><div class="fo-month-label">' + esc(monthLabel()) + '</div><div class="fo-calendar-actions">' + guestOrderButton() + '<button type="button" class="button secondary" data-fo-action="prev">ماه قبل</button><button type="button" class="button secondary" data-fo-action="today">امروز</button><button type="button" class="button secondary" data-fo-action="next">ماه بعد</button></div></div>' + (state.error ? '<div class="alert danger" role="alert">' + esc(state.error) + '</div>' : '') + (state.loading ? '<div class="fo-modal-notice">در حال بارگذاری ماه…</div>' : '') + '<div class="fo-calendar-grid" role="grid" aria-label="تقویم سفارش غذا">' + headings + content + '</div><div class="fo-legend"><span><i class="legend-open"></i> سفارش باز</span><span><i class="legend-closed"></i> سفارش بسته</span><span><i class="legend-holiday"></i> تعطیل رسمی / پنجشنبه و جمعه</span><span><i class="legend-today"></i> امروز</span><span><i class="legend-order"></i> سفارش ثبت‌شده</span></div></div>';
  }

  function renderOrders() {
    var rows = state.orders || [];
    if (!rows.length) return '<div class="card fo-empty">هنوز سفارشی برای حساب شما ثبت نشده است.</div>';
    return '<div class="card fo-my-orders"><div class="table-wrap"><table class="ticket-table" style="width:100%;border-collapse:collapse"><thead><tr class="table-head"><th>ردیف</th><th>نام و نام خانوادگی</th><th>نوع غذا</th><th>تاریخ غذا</th><th>تاریخ رزرو</th><th>ساعت رزرو</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>' + rows.map(function (row, index) {
      var active = row.status === 'active';
      var canCancel = active && !isPast(row.food_date);
      return '<tr class="table-row"><td>' + faDigits(index + 1) + '</td><td>' + esc(row.full_name || selfName) + '</td><td>' + esc(row.food_name || '—') + '</td><td>' + esc(faDigits(isoToJalali(row.food_date))) + '</td><td>' + esc(faDigits(isoToJalali(row.reserve_date))) + '</td><td>' + esc(faDigits((row.reserve_time || '').slice(0, 5))) + '</td><td><span class="fo-order-state ' + (active ? '' : 'cancelled') + '">' + (active ? 'فعال' : 'لغوشده') + '</span></td><td>' + (canCancel ? '<button class="button secondary" type="button" data-fo-action="cancel" data-order-id="' + Number(row.id) + '">لغو سفارش</button>' : '—') + '</td></tr>';
    }).join('') + '</tbody></table></div></div>';
  }

  function renderProxyCancelControl(orderId, buttonClass) {
    var id = Number(orderId || 0);
    var busy = Number(state.proxyCancelingOrderId) === id;
    var confirming = Number(state.proxyCancelConfirmingOrderId) === id;
    if (!id) return '';
    if (busy) return '<button class="' + buttonClass + '" type="button" disabled>در حال لغو…</button>';
    if (confirming) {
      return '<span class="fo-proxy-cancel-confirm"><button class="button danger-button" type="button" data-fo-action="confirm-cancel-proxy" data-order-id="' + id + '">تأیید لغو</button><button class="button secondary" type="button" data-fo-action="dismiss-cancel-proxy" data-order-id="' + id + '">انصراف</button></span>';
    }
    return '<button class="' + buttonClass + '" type="button" data-fo-action="cancel-proxy" data-order-id="' + id + '">لغو سفارش</button>';
  }

  function renderProxyOrders() {
    if (state.targetMode !== 'proxy' || !state.recipientReady) return '';
    var rows = (state.proxyOrders || []).map(function (order) {
      return {
        id: Number(order.id || 0),
        food_date: order.food_date || '',
        food_name: order.food_name || '',
        reserve_date: order.reserve_date || '',
        reserve_time: order.reserve_time || '',
        status: order.status || 'active',
        day_status: '',
      };
    });
    var content = '';
    if (state.proxyLoading) {
      content = '<div class="card fo-empty">در حال بارگذاری سفارش‌ها…</div>';
    } else if (state.proxyError) {
      content = '<div class="card fo-empty">' + esc(state.proxyError) + '</div>';
    } else if (!rows.length) {
      content = '<div class="card fo-empty">در این بازه برای این شخص سفارشی ثبت نشده است.</div>';
    } else {
      content = '<div class="card fo-my-orders fo-proxy-orders"><div class="table-wrap"><table class="ticket-table" style="width:100%;border-collapse:collapse"><thead><tr class="table-head"><th>ردیف</th><th>نام پرسنل</th><th>نوع غذا</th><th>تاریخ غذا</th><th>تاریخ رزرو</th><th>ساعت رزرو</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>' + rows.map(function (row, index) {
        var reserveDate = row.reserve_date ? faDigits(isoToJalali(row.reserve_date)) : '—';
        var reserveTime = row.reserve_time ? faDigits(row.reserve_time.slice(0, 5)) : '—';
        var active = row.status === 'active';
        var canCancel = active && !isPast(row.food_date) && row.id > 0;
        var cancelButton = canCancel ? renderProxyCancelControl(row.id, 'button secondary') : '—';
        return '<tr class="table-row"><td>' + faDigits(index + 1) + '</td><td>' + esc(state.targetName || '—') + '</td><td>' + esc(row.food_name || '—') + '</td><td>' + esc(faDigits(isoToJalali(row.food_date))) + '</td><td>' + esc(reserveDate) + '</td><td>' + esc(reserveTime) + '</td><td><span class="fo-order-state ' + (active ? '' : 'cancelled') + '">' + (active ? 'فعال' : 'لغوشده') + '</span></td><td>' + cancelButton + '</td></tr>';
      }).join('') + '</tbody></table></div></div>';
    }
    return '<div class="fo-section-title"><div><h2>سفارش‌های این ماه</h2></div></div>' + content;
  }

  function currentDay() {
    return state.monthData && state.monthData.days ? state.monthData.days[state.modalDate] || null : null;
  }

  function modalReadOnlyMessage(day, holiday) {
    if (isPast(state.modalDate)) return 'تاریخ گذشته است و فقط می‌توانید سفارش قبلی خود را ببینید.';
    if (holiday) return 'این روز تعطیل رسمی است؛ تعطیلی مانع سفارش نیست.';
    if (!day || !day.calendar_id || !day.items || !day.items.length) return 'برای این تاریخ هنوز برنامهٔ غذایی ثبت نشده است.';
    if (day.order_status === 'closed') return 'سفارش‌گیری این روز بسته شده است.';
    if (day.order_status !== 'open') return 'وضعیت سفارش این روز مشخص نیست.';
    return '';
  }

  function renderEmployeeChoices() {
    if (state.employeeChoicesLoading) return '<div class="fo-combo-message">در حال بارگذاری فهرست پرسنل…</div>';
    if (!state.employeeChoicesLoaded) return '<div class="fo-combo-message">برای دریافت فهرست، چند لحظه صبر کنید.</div>';
    var people = employeeMatches();
    if (!people.length) return '<div class="fo-combo-message">پرسنلی با این نام پیدا نشد.</div>';
    return people.map(function (person, index) {
      var selected = Number(person.id) === Number(state.targetId);
      var highlighted = index === state.highlightedIndex;
      return '<button type="button" class="fo-search-result' + (highlighted ? ' is-highlighted' : '') + '" role="option" aria-selected="' + (selected ? 'true' : 'false') + '" id="fo-employee-option-' + index + '" data-fo-flow-person-id="' + Number(person.id) + '" data-fo-flow-person-name="' + esc(person.name) + '">' + esc(person.name) + '</button>';
    }).join('');
  }

  function renderRecipientDialog() {
    if (!state.flowStage) return '';
    if (state.flowStage === 'choice') {
      var proxyChoice = canProxy
        ? '<button type="button" class="fo-person-choice" data-fo-flow-action="other"><span class="fo-choice-icon" aria-hidden="true">👥</span><strong>برای شخص دیگری</strong><small>نام پرسنل و کد ملی او را وارد می‌کنم</small></button>'
        : '<button type="button" class="fo-person-choice" disabled aria-disabled="true"><span class="fo-choice-icon" aria-hidden="true">👥</span><strong>برای شخص دیگری</strong><small>برای ثبت نیابتی باید مجوز مربوط را داشته باشید</small></button>';
      return '<dialog class="fo-modal fo-recipient-dialog" id="fo-recipient-dialog" aria-labelledby="fo-recipient-title"><div class="fo-modal-inner"><div class="fo-modal-head"><div><h2 id="fo-recipient-title">این سفارش برای چه کسی است؟</h2><p>قبل از ورود به تقویم، شخصی را که غذا برای او سفارش می‌دهید مشخص کنید.</p></div><button class="fo-modal-close" type="button" data-fo-flow-action="dismiss" aria-label="بستن">×</button></div><div class="fo-person-choices"><button type="button" class="fo-person-choice" data-fo-flow-action="self"><span class="fo-choice-icon" aria-hidden="true">👤</span><strong>برای خودم</strong><small>' + esc(selfName) + '</small></button>' + proxyChoice + '</div><div class="fo-modal-notice">با انتخاب «برای دیگری»، کد ملی فقط در سمت سرور تطبیق داده می‌شود و در فهرست نام‌ها نمایش داده نخواهد شد.</div></div></dialog>';
    }
    if (state.flowStage !== 'proxy') return '';
    var expanded = state.dropdownOpen ? 'true' : 'false';
    var activeDescendant = state.highlightedIndex >= 0 ? ' aria-activedescendant="fo-employee-option-' + state.highlightedIndex + '"' : '';
    var selectorText = state.targetId > 0 ? state.targetName : 'برای انتخاب پرسنل کلیک کنید';
    var selector = '<button type="button" class="fo-person-select-trigger" data-fo-flow-action="toggle-users" aria-expanded="' + expanded + '"' + (state.flowSaving ? ' disabled' : '') + '><span>' + esc(selectorText) + '</span><span class="fo-select-caret" aria-hidden="true">⌄</span></button>';
    var searchPanel = state.dropdownOpen
      ? '<div class="fo-combobox"><input type="search" id="fo-search-name" role="combobox" aria-autocomplete="list" aria-controls="fo-employee-results" aria-expanded="true"' + activeDescendant + ' autocomplete="off" placeholder="نام پرسنل را جست‌وجو کنید" value="' + esc(state.searchQuery) + '"' + (state.flowSaving ? ' disabled' : '') + '><div class="fo-search-results" id="fo-employee-results" role="listbox">' + renderEmployeeChoices() + '</div></div>'
      : '';
    var codeField = state.targetId > 0
      ? '<div class="fo-proxy-code" id="fo-proxy-code-block"><label class="fo-form-field">کد ملی این شخص (۱۰ رقم)<input type="text" id="fo-national-code" inputmode="numeric" autocomplete="off" maxlength="10" value="' + esc(state.nationalCode) + '" placeholder="کد ملی را وارد کنید"' + (state.flowSaving ? ' disabled' : '') + '></label><div class="fo-modal-notice">کد ملی فقط برای تطبیق سمت سرور استفاده می‌شود؛ در فهرست نام‌ها نمایش داده نمی‌شود.</div></div>'
      : '<div class="fo-modal-notice">پس از انتخاب پرسنل، کد ملی او را وارد کنید.</div>';
    var error = state.flowError ? '<div class="fo-modal-notice error" id="fo-flow-error" role="alert">' + esc(state.flowError) + '</div>' : (state.flowSaving ? '<div class="fo-modal-notice" role="status">در حال بررسی اطلاعات…</div>' : '');
    var canContinue = state.targetId > 0 && normalizedDigits(state.nationalCode).length === 10 && !state.flowSaving && !state.dropdownOpen;
    return '<dialog class="fo-modal fo-recipient-dialog" id="fo-recipient-dialog" aria-labelledby="fo-recipient-title"><div class="fo-modal-inner"><div class="fo-modal-head"><div><h2 id="fo-recipient-title">اطلاعات شخص سفارش‌گیرنده</h2><p>یک نفر را از فهرست انتخاب کنید و کد ملی را وارد کنید.</p></div><button class="fo-modal-close" type="button" data-fo-flow-action="dismiss" aria-label="بستن">×</button></div><div class="fo-modal-notice">سفارش برای شخص دیگری ثبت می‌شود. کد ملی فقط برای تطبیق سمت سرور استفاده خواهد شد.</div><label class="fo-form-field">پرسنل سفارش‌گیرنده</label><div class="fo-person-picker">' + selector + searchPanel + '</div>' + codeField + error + '<div class="fo-modal-actions"><button type="button" class="button secondary" data-fo-flow-action="back"' + (state.flowSaving ? ' disabled' : '') + '>بازگشت</button><button type="button" class="button" data-fo-flow-action="confirm-proxy"' + (canContinue ? '' : ' disabled') + '>' + (state.flowSaving ? 'در حال بررسی…' : 'تأیید و ورود به تقویم') + '</button></div></div></dialog>';
  }

  function renderRecipientBanner() {
    if (!state.recipientReady) return '';
    var modeText = state.targetMode === 'self' ? 'سفارش شخصی' : 'ثبت سفارش به نیابت';
    return '<div class="fo-recipient-banner"><div class="fo-recipient-avatar" aria-hidden="true">' + (state.targetMode === 'self' ? '👤' : '👥') + '</div><div class="fo-recipient-details"><span>' + modeText + '</span><strong>سفارش برای: ' + esc(state.targetName) + '</strong></div><button type="button" class="button secondary fo-recipient-change" data-fo-action="change-recipient">تغییر شخص</button></div>';
  }

  function renderModal() {
    if (!state.modalDate || !state.recipientReady) return '';
    var day = currentDay();
    var holiday = holidayTitle(state.modalDate, day);
    var dateLabel = isoToJalali(state.modalDate);
    var readOnly = modalReadOnlyMessage(day, holiday);
    var items = day && Array.isArray(day.items) ? day.items : [];
    var canOrderDay = !readOnly && day && day.order_status === 'open' && !isPast(state.modalDate);
    var existingOrder = day && day.my_order ? day.my_order : null;
    var selfOrder = state.targetMode === 'self' ? existingOrder : null;
    var showMeals = state.targetMode === 'self' ? hasNational : (state.targetId > 0 && normalizedDigits(state.nationalCode).length === 10);
    var radios = showMeals ? items.map(function (item, index) {
      var checked = existingOrder ? Number(existingOrder.food_id) === Number(item.food_id) : index === 0;
      return '<label class="fo-meal-option"><input type="radio" name="fo-food" value="' + Number(item.item_id) + '" ' + (checked ? 'checked' : '') + '><span><strong>' + esc(item.food_name) + '</strong></span></label>';
    }).join('') : '';
    var recipientInfo = '<div class="fo-modal-notice fo-modal-recipient">سفارش برای <strong>' + esc(state.targetName) + '</strong></div>';
    var ownOrderHtml = selfOrder
      ? '<div class="fo-modal-notice">سفارش فعال شما: <strong>' + esc(selfOrder.food_name) + '</strong></div>'
      : (state.targetMode === 'proxy' && existingOrder ? '<div class="fo-modal-notice">سفارش فعال این شخص: <strong>' + esc(existingOrder.food_name) + '</strong></div>' : '');
    var message = '';
    if (readOnly) message = '<div class="fo-modal-notice ' + (isPast(state.modalDate) || (day && day.order_status === 'closed') ? 'warn' : '') + '">' + esc(readOnly) + '</div>';
    if (state.targetMode === 'self' && !hasNational) message = '<div class="fo-modal-notice error">کد ملی شما در سامانه ثبت نیست؛ ثبت سفارش برای خودتان غیرفعال است.</div>';
    var saveAllowed = Boolean(state.recipientReady && canOrderDay && showMeals && items.length);
    var cancelHtml = '';
    if (state.targetMode === 'self' && selfOrder && !isPast(state.modalDate) && day && day.order_status === 'open') {
      cancelHtml = '<button type="button" class="button danger-button" data-fo-action="cancel-current" data-order-id="' + Number(selfOrder.id) + '">لغو سفارش</button>';
    } else if (state.targetMode === 'proxy' && existingOrder && Number(existingOrder.id || 0) > 0 && !isPast(state.modalDate) && day) {
      cancelHtml = renderProxyCancelControl(existingOrder.id, 'button danger-button');
    }
    return '<dialog class="fo-modal" id="fo-order-dialog" aria-labelledby="fo-modal-title"><div class="fo-modal-inner"><div class="fo-modal-head"><div><h2 id="fo-modal-title">انتخاب غذای روز</h2><p>' + esc(faDigits(dateLabel)) + (holiday ? ' • ' + esc(holiday) : '') + '</p></div><button class="fo-modal-close" type="button" data-fo-action="close" aria-label="بستن">×</button></div>' + recipientInfo + message + ownOrderHtml + (canOrderDay && items.length ? '<div class="fo-section-title"><div><h2>غذای موردنظر را انتخاب کنید</h2><p>برای هر شخص در هر روز فقط یک سفارش ثبت می‌شود.</p></div></div><div class="fo-meal-options">' + radios + '</div>' : '') + '<div class="fo-status-msg" id="fo-modal-error" aria-live="polite"></div><div class="fo-modal-actions">' + (saveAllowed ? '<button type="button" class="button" data-fo-action="save-order">' + (selfOrder ? 'ثبت تغییر سفارش' : 'ثبت سفارش') + '</button>' : '') + cancelHtml + '<button type="button" class="button secondary" data-fo-action="close">بستن</button></div></div></dialog>';
  }

  function render() {
    var page = root.closest ? root.closest('.food-order-page') : null;
    if (page) {
      page.classList.toggle('is-flow-locked', !state.recipientReady);
      page.classList.toggle('is-flow-dismissed', !state.recipientReady && state.flowDismissed);
    }
    var pageNotice = state.pageNotice && state.pageNotice.message
      ? '<div class="alert ' + (state.pageNotice.type === 'danger' ? 'danger' : 'success') + ' fo-page-notice" role="' + (state.pageNotice.type === 'danger' ? 'alert' : 'status') + '"><span>' + esc(state.pageNotice.message) + '</span><button type="button" class="fo-page-notice-close" data-fo-action="dismiss-notice" aria-label="بستن پیام">×</button></div>'
      : '';
    var body = Cal
      ? pageNotice + renderRecipientBanner() + renderCalendar() + (state.targetMode === 'self' ? renderListFilter() + '<div class="fo-section-title"><div><h2>سفارش‌های من</h2></div></div>' + renderOrders() : renderListFilter() + renderProxyOrders()) + renderModal()
      : pageNotice + '<div class="alert danger">تقویم شمسی مشترک بارگذاری نشده است. صفحه را تازه‌سازی کنید.</div>';
    root.innerHTML = body + renderRecipientDialog();
    if (window.ItsmJalali && typeof window.ItsmJalali.enhanceAll === 'function') window.ItsmJalali.enhanceAll(root);
    var recipientDialog = root.querySelector('#fo-recipient-dialog');
    var foodDialog = root.querySelector('#fo-order-dialog');
    var dialog = recipientDialog || foodDialog;
    if (dialog && !dialog.open) {
      try { dialog.showModal(); } catch (e) { dialog.setAttribute('open', 'open'); }
    }
    if (dialog) {
      dialog.addEventListener('click', function (event) {
        if (event.target === dialog && dialog.id === 'fo-recipient-dialog') event.stopPropagation();
        else if (event.target === dialog && dialog.id === 'fo-order-dialog') closeModal();
      });
      dialog.addEventListener('cancel', function (event) {
        if (dialog.id === 'fo-recipient-dialog') event.preventDefault();
        else closeModal();
      });
    }
  }

  function setMonth(year, month) {
    state.year = Number(year); state.month = Number(month); state.modalDate = '';
    state.proxyCancelConfirmingOrderId = 0;
    loadMonth();
  }

  function openDay(iso, isCurrentMonth) {
    if (!state.recipientReady || !isCurrentMonth) return;
    if (!Cal.jalaliDateFromIso(iso)) return;
    state.proxyCancelConfirmingOrderId = 0;
    state.modalDate = iso;
    render();
  }

  function closeModal() {
    var dialog = root.querySelector('#fo-order-dialog');
    if (dialog && dialog.open) dialog.close();
    state.proxyCancelConfirmingOrderId = 0;
    state.modalDate = '';
    render();
  }

  function setModalError(message) {
    var el = root.querySelector('#fo-modal-error');
    if (el) el.textContent = message || '';
  }

  function focusSearchInput(selectAll) {
    var input = root.querySelector('#fo-search-name');
    if (!input) return;
    input.focus();
    if (selectAll) {
      try { input.select(); } catch (e) { /* browser input */ }
    } else {
      try { input.setSelectionRange(input.value.length, input.value.length); } catch (e) { /* browser input */ }
    }
  }

  function activateRecipient() {
    state.proxyCancelConfirmingOrderId = 0;
    state.recipientReady = true;
    state.flowStage = '';
    state.flowDismissed = false;
    state.flowError = '';
    state.flowSaving = false;
    state.flowRequestId++;
    state.modalDate = '';
    state.monthData = null;
    state.loading = true;
    state.dataLoaded = true;
    render();
    var jobs = [loadMonth(), loadMyOrders()];
    Promise.all(jobs);
  }

  function startRecipientChoice() {
    state.proxyCancelConfirmingOrderId = 0;
    state.recipientReady = false;
    state.flowStage = 'choice';
    state.flowDismissed = false;
    state.flowError = '';
    state.flowSaving = false;
    state.flowRequestId++;
    state.modalDate = '';
    state.targetMode = 'self';
    state.targetId = selfId;
    state.targetName = selfName;
    state.nationalCode = '';
    state.searchQuery = '';
    state.dropdownOpen = true;
    state.highlightedIndex = -1;
    render();
  }

  function dismissRecipientFlow() {
    state.proxyCancelConfirmingOrderId = 0;
    state.flowRequestId++;
    state.recipientReady = false;
    state.flowStage = '';
    state.flowDismissed = true;
    state.flowError = '';
    state.flowSaving = false;
    state.modalDate = '';
    state.targetMode = 'self';
    state.targetId = selfId;
    state.targetName = selfName;
    state.nationalCode = '';
    state.searchQuery = '';
    state.dropdownOpen = false;
    state.highlightedIndex = -1;
    render();
  }

  function beginProxyChoice() {
    if (!canProxy) return;
    state.proxyCancelConfirmingOrderId = 0;
    state.flowStage = 'proxy';
    state.flowDismissed = false;
    state.flowError = '';
    state.flowSaving = false;
    state.targetMode = 'proxy';
    state.targetId = 0;
    state.targetName = '';
    state.nationalCode = '';
    state.searchQuery = '';
    state.dropdownOpen = false;
    state.highlightedIndex = -1;
    render();
    if (!state.employeeChoicesLoaded) loadEmployeeChoices();
  }

  async function loadEmployeeChoices() {
    if (!canProxy || state.employeeChoicesLoaded || state.employeeChoicesLoading) return;
    state.employeeChoicesLoading = true;
    state.flowError = '';
    render();
    try {
      var payload = await request('search-users', { q: '' });
      state.employeeChoices = Array.isArray(payload.items) ? payload.items : [];
      state.employeeChoicesLoaded = true;
    } catch (e) {
      state.flowError = e.message || 'فهرست پرسنل بارگذاری نشد.';
    } finally {
      state.employeeChoicesLoading = false;
      if (state.flowStage === 'proxy' && !state.recipientReady) {
        render();
        if (state.dropdownOpen && state.employeeChoicesLoaded) focusSearchInput(false);
      }
    }
  }

  function selectProxyPerson(id, name) {
    if (state.flowStage !== 'proxy' || !id) return;
    state.targetId = Number(id);
    state.targetName = String(name || '');
    state.searchQuery = state.targetName;
    state.nationalCode = '';
    state.dropdownOpen = false;
    state.highlightedIndex = -1;
    state.flowError = '';
    render();
    var codeInput = root.querySelector('#fo-national-code');
    if (codeInput) codeInput.focus();
  }

  function updateProxySearch(input) {
    var value = String(input.value || '');
    var cursor = input.selectionStart;
    if (state.targetId > 0 && normalizeSearch(value) !== normalizeSearch(state.targetName)) {
      state.targetId = 0;
      state.targetName = '';
      state.nationalCode = '';
    }
    state.searchQuery = value;
    state.dropdownOpen = true;
    state.highlightedIndex = -1;
    state.flowError = '';
    render();
    var next = root.querySelector('#fo-search-name');
    if (next) {
      next.focus();
      try { next.setSelectionRange(Math.min(cursor, next.value.length), Math.min(cursor, next.value.length)); } catch (e) { /* search caret */ }
    }
  }

  async function confirmProxyRecipient() {
    if (state.flowStage !== 'proxy' || state.flowSaving || state.dropdownOpen) return;
    var nationalCode = normalizedDigits(state.nationalCode);
    if (!state.targetId) { state.flowError = 'ابتدا نام پرسنل را از فهرست انتخاب کنید.'; render(); focusSearchInput(false); return; }
    if (nationalCode.length !== 10) { state.flowError = 'کد ملی باید دقیقاً ۱۰ رقم باشد.'; render(); var invalidInput = root.querySelector('#fo-national-code'); if (invalidInput) invalidInput.focus(); return; }
    var targetId = state.targetId;
    var requestId = ++state.flowRequestId;
    state.flowSaving = true;
    state.flowError = '';
    render();
    try {
      var result = await request('verify-proxy', null, { method: 'POST', body: JSON.stringify({ employee_id: targetId, national_code: nationalCode }) });
      if (requestId !== state.flowRequestId || state.flowStage !== 'proxy') return;
      if (!result.person || Number(result.person.id) !== targetId) throw new Error('هویت فرد انتخاب‌شده تأیید نشد.');
      state.targetId = targetId;
      state.targetName = String(result.person.name || state.targetName);
      state.nationalCode = nationalCode;
      activateRecipient();
    } catch (e) {
      if (requestId === state.flowRequestId && state.flowStage === 'proxy') state.flowError = e.message || 'تطبیق اطلاعات انجام نشد.';
    } finally {
      if (requestId === state.flowRequestId && state.flowStage === 'proxy' && !state.recipientReady) {
        state.flowSaving = false;
        render();
        var retryInput = root.querySelector('#fo-national-code');
        if (retryInput) retryInput.focus();
      }
    }
  }

  async function saveOrder() {
    var day = currentDay();
    if (!day || !Array.isArray(day.items) || !day.items.length) {
      setModalError('برای این تاریخ برنامهٔ غذای قابل سفارشی پیدا نشد. صفحه را تازه‌سازی کنید.');
      return;
    }
    var selected = root.querySelector('input[name="fo-food"]:checked');
    if (!selected) { setModalError('یک غذا را انتخاب کنید.'); toast('یک غذا را انتخاب کنید.', true); return; }
    var isProxy = state.targetMode === 'proxy';
    var recipientName = state.targetName;
    var selectedOption = selected.closest ? selected.closest('.fo-meal-option') : null;
    var selectedFoodName = selectedOption ? selectedOption.textContent.trim() : 'غذای انتخاب‌شده';
    var body = { employee_id: isProxy ? state.targetId : selfId, food_date: state.modalDate, calendar_item_id: Number(selected.value) };
    if (isProxy) {
      body.national_code = normalizedDigits(state.nationalCode);
      if (!body.employee_id) { setModalError('پرسنل سفارش‌گیرنده انتخاب نشده است.'); toast('پرسنل سفارش‌گیرنده انتخاب نشده است.', true); return; }
      if (body.national_code.length !== 10) { setModalError('کد ملی باید ۱۰ رقم باشد.'); toast('کد ملی باید ۱۰ رقم باشد.', true); return; }
    }
    var button = root.querySelector('[data-fo-action="save-order"]');
    if (button) button.disabled = true;
    setModalError('در حال ثبت سفارش…');
    try {
      var response = await request('order', null, { method: 'POST', body: JSON.stringify(body) });
      var notice = response.message || 'سفارش ثبت شد.';
      if (isProxy) {
        var foodName = response.food_name || selectedFoodName;
        var jalaliDate = isoToJalali(state.modalDate);
        notice = (response.unchanged ? 'این غذا قبلاً برای ' : 'غذای انتخاب‌شده برای ') + recipientName + (response.unchanged ? ' ثبت شده بود: ' : ' ثبت شد: ') + foodName;
        if (jalaliDate) notice += ' — تاریخ: ' + faDigits(jalaliDate);
        if (response.order_id) notice += ' — کد سفارش: ' + faDigits(response.order_id);
      }
      state.pageNotice = { message: notice, type: 'success' };
      closeModal();
      var refresh = [loadMonth(), loadMyOrders()];
      await Promise.all(refresh);
    } catch (e) {
      var errorMessage = e.message || 'ثبت سفارش ناموفق بود.';
      state.pageNotice = { message: errorMessage, type: 'danger' };
      setModalError(errorMessage);
      if (button) button.disabled = false;
    }
  }

  function requestProxyCancelConfirmation(orderId) {
    if (state.targetMode !== 'proxy' || !state.recipientReady || !state.targetId || normalizedDigits(state.nationalCode).length !== 10) {
      state.pageNotice = { message: 'برای لغو، هویت گیرندهٔ سفارش را دوباره تأیید کنید.', type: 'danger' };
      render();
      return;
    }
    var id = Number(orderId || 0);
    if (!id || state.proxyCancelingOrderId) return;
    state.proxyCancelConfirmingOrderId = id;
    render();
  }

  function dismissProxyCancelConfirmation(orderId) {
    if (Number(state.proxyCancelConfirmingOrderId) !== Number(orderId)) return;
    state.proxyCancelConfirmingOrderId = 0;
    render();
  }

  async function cancelProxyOrder(orderId) {
    var id = Number(orderId || 0);
    if (state.targetMode !== 'proxy' || !state.recipientReady || !state.targetId || normalizedDigits(state.nationalCode).length !== 10) {
      state.pageNotice = { message: 'برای لغو، هویت گیرندهٔ سفارش را دوباره تأیید کنید.', type: 'danger' };
      state.proxyCancelConfirmingOrderId = 0;
      render();
      return;
    }
    if (!id || state.proxyCancelingOrderId || Number(state.proxyCancelConfirmingOrderId) !== id) return;
    var modalErrorMessage = '';
    state.proxyCancelConfirmingOrderId = 0;
    state.proxyCancelingOrderId = id;
    render();
    try {
      var response = await request('cancel-proxy', null, { method: 'POST', body: JSON.stringify({
        order_id: id,
        employee_id: Number(state.targetId),
        national_code: normalizedDigits(state.nationalCode),
      }) });
      state.pageNotice = { message: response.message || 'سفارش فرد تأییدشده لغو شد.', type: 'success' };
      state.modalDate = '';
      await Promise.all([loadMonth(), loadMyOrders()]);
    } catch (e) {
      var errorMessage = e.message || 'لغو سفارش نیابتی انجام نشد.';
      state.pageNotice = { message: errorMessage, type: 'danger' };
      if (state.modalDate) modalErrorMessage = errorMessage;
    } finally {
      state.proxyCancelingOrderId = 0;
      render();
      if (modalErrorMessage && state.modalDate) setModalError(modalErrorMessage);
    }
  }

  async function cancelOrder(orderId) {
    if (!orderId || !window.confirm('سفارش شما لغو شود؟ رکورد برای حفظ سوابق حذف فیزیکی نمی‌شود.')) return;
    try {
      var response = await request('cancel', null, { method: 'POST', body: JSON.stringify({ order_id: Number(orderId) }) });
      state.pageNotice = { message: response.message || 'سفارش لغو شد.', type: 'success' };
      state.modalDate = '';
      await Promise.all([loadMonth(), loadMyOrders()]);
    } catch (e) {
      var errorMessage = e.message || 'لغو سفارش انجام نشد.';
      state.pageNotice = { message: errorMessage, type: 'danger' };
      setModalError(errorMessage);
    }
  }

  var page = root.closest ? root.closest('.food-order-page') : null;
  if (page) page.addEventListener('click', function (event) {
    if (!state.recipientReady && state.flowDismissed) {
      event.preventDefault();
      event.stopPropagation();
      startRecipientChoice();
    }
  });

  root.addEventListener('click', function (event) {
    var person = event.target.closest('[data-fo-flow-person-id]');
    if (person && state.flowStage === 'proxy') {
      event.preventDefault(); event.stopPropagation();
      selectProxyPerson(Number(person.dataset.foFlowPersonId || 0), person.dataset.foFlowPersonName || '');
      return;
    }
    var flowAction = event.target.closest('[data-fo-flow-action]');
    if (flowAction) {
      event.preventDefault(); event.stopPropagation();
      var flowAct = flowAction.dataset.foFlowAction;
      if (flowAct === 'dismiss') dismissRecipientFlow();
      if (flowAct === 'self') {
        state.targetMode = 'self'; state.targetId = selfId; state.targetName = selfName; state.nationalCode = '';
        activateRecipient();
      }
      if (flowAct === 'other') beginProxyChoice();
      if (flowAct === 'toggle-users') {
        if (state.flowSaving) return;
        state.dropdownOpen = !state.dropdownOpen;
        state.highlightedIndex = -1;
        if (state.dropdownOpen) state.searchQuery = '';
        render();
        if (state.dropdownOpen) {
          focusSearchInput(false);
          if (!state.employeeChoicesLoaded) loadEmployeeChoices();
        }
        return;
      }
      if (flowAct === 'back') startRecipientChoice();
      if (flowAct === 'confirm-proxy') confirmProxyRecipient();
      return;
    }
    if (!state.recipientReady) return;
    var action = event.target.closest('[data-fo-action]');
    if (action) {
      var act = action.dataset.foAction;
      if (act === 'guest-order') { if (window.FoodGuest) window.FoodGuest.open(); return; }
      if (act === 'prev') { var p = Cal.shiftMonth(state.year, state.month, -1); setMonth(p[0], p[1]); }
      if (act === 'next') { var n = Cal.shiftMonth(state.year, state.month, 1); setMonth(n[0], n[1]); }
      if (act === 'today') {
        var j = todayJalali.match(/^(\d{4})\/(\d{2})\/(\d{2})$/);
        if (j) setMonth(Number(j[1]), Number(j[2]));
      }
      if (act === 'close') closeModal();
      if (act === 'dismiss-notice') { state.pageNotice = null; render(); }
      if (act === 'change-recipient') startRecipientChoice();
      if (act === 'save-order') saveOrder();
      if (act === 'cancel' || act === 'cancel-current') cancelOrder(Number(action.dataset.orderId || 0));
      if (act === 'cancel-proxy') requestProxyCancelConfirmation(Number(action.dataset.orderId || 0));
      if (act === 'confirm-cancel-proxy') cancelProxyOrder(Number(action.dataset.orderId || 0));
      if (act === 'dismiss-cancel-proxy') dismissProxyCancelConfirmation(Number(action.dataset.orderId || 0));
      if (act === 'apply-list-range') applyListRange();
      if (act === 'reset-list-range') { state.listFrom = ''; state.listTo = ''; loadMyOrders(); }
      return;
    }
    var dayButton = event.target.closest('[data-fo-day]');
    if (dayButton) openDay(dayButton.dataset.foDay, dayButton.dataset.currentMonth === '1');
  });

  root.addEventListener('focusin', function (event) {
    var input = event.target;
    if (!input || input.id !== 'fo-search-name' || state.flowStage !== 'proxy') return;
    state.dropdownOpen = true;
    state.highlightedIndex = -1;
    if (state.targetId > 0) { state.searchQuery = ''; try { input.select(); } catch (e) { /* browser input */ } }
    input.setAttribute('aria-expanded', 'true');
    var list = root.querySelector('#fo-employee-results');
    if (list) { list.hidden = false; list.innerHTML = renderEmployeeChoices(); }
    if (!state.employeeChoicesLoaded) loadEmployeeChoices();
  });

  root.addEventListener('input', function (event) {
    if (event.target && event.target.id === 'fo-search-name') {
      updateProxySearch(event.target);
      return;
    }
    if (event.target && event.target.id === 'fo-national-code' && state.flowStage === 'proxy') {
      var input = event.target;
      var newValue = normalizedDigits(input.value).slice(0, 10);
      state.nationalCode = newValue;
      if (input.value !== newValue) input.value = newValue;
      state.flowError = '';
      var oldError = root.querySelector('#fo-flow-error'); if (oldError) oldError.remove();
      var button = root.querySelector('[data-fo-flow-action="confirm-proxy"]');
      if (button) button.disabled = !(state.targetId > 0 && newValue.length === 10 && !state.flowSaving && !state.dropdownOpen);
    }
  });

  root.addEventListener('keydown', function (event) {
    var input = event.target;
    if (input && input.id === 'fo-national-code' && state.flowStage === 'proxy') {
      if (event.key === 'Enter') {
        event.preventDefault(); event.stopPropagation();
        if (!state.flowSaving && !state.dropdownOpen) confirmProxyRecipient();
      }
      return;
    }
    if (!input || input.id !== 'fo-search-name' || state.flowStage !== 'proxy') return;
    var people = employeeMatches();
    if (event.key === 'Escape') {
      event.preventDefault(); event.stopPropagation();
      state.dropdownOpen = false; state.highlightedIndex = -1;
      render();
      var trigger = root.querySelector('[data-fo-flow-action="toggle-users"]');
      if (trigger) trigger.focus();
      return;
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (!people.length) return;
      event.preventDefault();
      state.dropdownOpen = true;
      var delta = event.key === 'ArrowDown' ? 1 : -1;
      state.highlightedIndex = Math.max(0, Math.min(people.length - 1, state.highlightedIndex + delta));
      input.setAttribute('aria-expanded', 'true');
      input.setAttribute('aria-activedescendant', 'fo-employee-option-' + state.highlightedIndex);
      var listbox = root.querySelector('#fo-employee-results');
      if (listbox) { listbox.hidden = false; listbox.innerHTML = renderEmployeeChoices(); }
      return;
    }
    if (event.key === 'Enter' && state.dropdownOpen) {
      var selectedIndex = state.highlightedIndex;
      if (selectedIndex < 0 && people.length === 1) selectedIndex = 0;
      if (selectedIndex >= 0 && people[selectedIndex]) {
        event.preventDefault(); event.stopPropagation();
        selectProxyPerson(people[selectedIndex].id, people[selectedIndex].name);
      }
    }
  });

  render();
})();
