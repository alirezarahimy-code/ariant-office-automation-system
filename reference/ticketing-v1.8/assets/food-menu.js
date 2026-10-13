/* مدیر برنامهٔ غذایی در پنل چاپ فیش. */
(function (global) {
  'use strict';

  var Cal = global.FoodOrderCalendar;
  var canMenu = global.FOOD_TICKET_CAN_MENU_EDIT === true;
  var canClose = global.FOOD_TICKET_CAN_ORDER_CLOSE === true;
  var isPrimary = global.FOOD_TICKET_IS_PRIMARY_ADMIN === true;
  var apiBase = String(global.FOOD_TICKET_API_BASE || '');
  var csrf = String(global.FOOD_TICKET_CSRF || '');
  var root = null;
  var state = {
    mounted: false,
    tab: canMenu ? 'calendar' : 'orders',
    year: 1405,
    month: 1,
    selectedDate: '',
    ordersDate: '',
    statisticsFrom: '',
    statisticsTo: '',
    statisticsMode: 'food',
    statistics: [],
    statisticsLoaded: false,
    statisticsRequestId: 0,
    monthData: null,
    catalog: [],
    orders: [],
    ordersLoaded: false,
    status: null,
    loading: false,
    loadingOrders: false,
    loadingStatistics: false,
    error: '',
    notice: '',
    pendingImpact: null,
    pendingImpactExpected: 0,
    pendingImpactMessage: '',
    activeRequest: 0,
  };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
    });
  }
  function faDigits(value) { return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; }); }
  function asciiDigits(value) {
    return String(value == null ? '' : value).replace(/[۰-۹٠-٩]/g, function (d) {
      var p = '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); return p >= 0 ? String(p) : String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    });
  }
  function isoFromJalali(value) {
    var normalized = asciiDigits(value).trim().replace(/[.-]/g, '/');
    var match = normalized.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if (!match || !Cal) return null;
    try {
      var year = Number(match[1]), month = Number(match[2]), day = Number(match[3]);
      if (month < 1 || month > 12 || day < 1 || day > Cal.monthLength(year, month)) return null;
      return Cal.isoDate(year, month, day);
    } catch (e) { return null; }
  }
  function jalaliFromIso(iso) {
    if (!Cal || !iso) return '';
    try {
      var d = Cal.jalaliDateFromIso(iso);
      return d ? String(d[0]).padStart(4, '0') + '/' + String(d[1]).padStart(2, '0') + '/' + String(d[2]).padStart(2, '0') : '';
    } catch (e) { return ''; }
  }
  function normalizeIsoDate(value) {
    var normalized = asciiDigits(value).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(normalized)) {
      try { return Cal && Cal.jalaliDateFromIso(normalized) ? normalized : null; } catch (e) { return null; }
    }
    return isoFromJalali(normalized);
  }
  function jalaliMonthFromIso(iso) {
    if (!Cal || !iso) return null;
    try { var d = Cal.jalaliDateFromIso(iso); return d ? [Number(d[0]), Number(d[1])] : null; } catch (e) { return null; }
  }
  function localTodayIso() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function monthKey() { return String(state.year).padStart(4, '0') + '-' + String(state.month).padStart(2, '0'); }
  function monthTitle() { return Cal.months[state.month - 1] + ' ' + faDigits(state.year); }
  function getDay(iso) { return state.monthData && state.monthData.days ? state.monthData.days[iso] || null : null; }
  function activeOrderCount(day) {
    if (!day || !Array.isArray(day.items)) return 0;
    return day.items.reduce(function (sum, item) { return sum + Number(item.order_count || 0); }, 0);
  }
  function holiday(iso, day) {
    if (day && day.holiday_title) return day.holiday_title;
    var list = global.ITSM_HOLIDAYS || [];
    var jalali = jalaliFromIso(iso);
    for (var i = 0; i < list.length; i++) {
      var item = list[i] || {};
      if (String(item.date || '').slice(0, 10) === iso || String(item.jalali || '').replace(/-/g, '/') === jalali) return item.title || 'تعطیل رسمی';
    }
    return '';
  }
  function buildUrl(route, params) {
    var query = params ? new URLSearchParams(params).toString() : '';
    var base = apiBase;
    if (!base) base = 'index.php?page=food-ticket&food_api=';
    return base + encodeURIComponent(String(route).replace(/^\/+/, '').replace(/^api\//, '')) + (query ? '&' + query : '');
  }
  async function request(route, params, options) {
    var opts = Object.assign({ method: 'GET', credentials: 'same-origin', headers: {} }, options || {});
    opts.headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
    if (opts.method !== 'GET' && csrf) opts.headers['X-CSRF-Token'] = csrf;
    var response = await fetch(buildUrl(route, params), opts);
    var text = await response.text();
    var payload = {};
    try { payload = text ? JSON.parse(text) : {}; } catch (e) {
      var parseError = new Error(response.ok ? 'پاسخ نامعتبر از سرور دریافت شد.' : ('خطای سرور ' + response.status));
      parseError.status = response.status; throw parseError;
    }
    if (!response.ok) {
      var error = new Error(payload.error || payload.message || ('خطای سرویس ' + response.status));
      error.status = response.status; error.payload = payload; throw error;
    }
    return payload || {};
  }
  function notify(message, bad) {
    state.notice = String(message || '');
    state.error = bad ? state.notice : '';
    if (!bad) state.notice = String(message || '');
    render();
  }

  function navTabs() {
    var tabs = [];
    if (canMenu) tabs.push(['calendar', 'برنامهٔ روزانه'], ['catalog', 'بانک غذا']);
    if (canClose) tabs.push(['orders', 'سفارش‌های روز'], ['statistics', 'آمار سفارش‌ها']);
    return '<div class="fm-tabs" role="tablist">' + tabs.map(function (tab) {
      return '<button class="fm-tab ' + (state.tab === tab[0] ? 'active' : '') + '" type="button" role="tab" aria-selected="' + (state.tab === tab[0] ? 'true' : 'false') + '" data-fm-tab="' + tab[0] + '">' + tab[1] + '</button>';
    }).join('') + '</div>';
  }

  function renderCalendar() {
    if (!state.monthData && state.loading) return '<div class="fm-card fm-spinner">در حال خواندن برنامهٔ ماه…</div>';
    var days = state.monthData && state.monthData.days ? state.monthData.days : {};
    var cells = Cal.monthCells(state.year, state.month);
    var weekdayHeaders = Cal.weekdays.map(function (name) { return '<div class="fo-weekday" role="columnheader">' + esc(name) + '</div>'; }).join('');
    var cellsHtml = cells.map(function (cell) {
      var day = days[cell.iso] || null;
      var holidayName = holiday(cell.iso, day);
      var meals = day && Array.isArray(day.items) ? day.items : [];
      var orderState = day && day.order_status ? day.order_status : '';
      var count = activeOrderCount(day);
      var status = holidayName ? 'تعطیل رسمی' : (orderState === 'closed' ? 'سفارش بسته' : orderState === 'open' && meals.some(function (x) { return x.food_active !== false; }) ? 'سفارش باز' : (meals.length ? 'غذا غیرفعال' : 'بدون برنامه'));
      var classes = ['fo-day'];
      var adjacentAttrs = cell.isCurrentMonth ? '' : ' disabled aria-disabled="true" tabindex="-1"';
      if (!cell.isCurrentMonth) classes.push('is-adjacent');
      if (cell.isWeekend) classes.push('is-weekend');
      if (holidayName) classes.push('is-holiday');
      if (cell.iso === (state.status && state.status.today ? state.status.today : '')) classes.push('is-today');
      if (orderState === 'closed') classes.push('is-closed');
      if (count > 0) classes.push('has-order');
      if (state.selectedDate === cell.iso) classes.push('fm-day is-selected');
      var tip = [cell.jalali, status, holidayName, meals.map(function (x) { return x.food_name + (x.food_active === false ? ' (غیرفعال)' : ''); }).join('، '), count ? faDigits(count) + ' سفارش فعال' : ''].filter(Boolean).join(' • ');
      var mealText = meals.map(function (item) { return '<span class="fo-day-meal">' + esc(item.food_name) + (item.food_active === false ? ' · غیرفعال' : '') + '</span>'; }).join('');
      if (count) mealText += '<span class="fo-day-count">' + faDigits(count) + ' سفارش فعال</span>';
      var orderMarker = count ? '<span class="fo-day-order-marker" title="' + faDigits(count) + ' سفارش فعال">✓ ' + faDigits(count) + ' سفارش</span>' : '';
      return '<button class="' + classes.join(' ') + '" type="button" data-fm-day="' + esc(cell.iso) + '" data-fm-current="' + (cell.isCurrentMonth ? '1' : '0') + '" data-has-food="' + (meals.length ? '1' : '0') + '" data-has-order="' + (count ? '1' : '0') + '" data-tooltip="' + esc(tip) + '" aria-label="' + esc(cell.jalali + '، ' + status + (holidayName ? '، ' + holidayName : '') + (meals.length ? '، ' + meals.map(function (x) { return x.food_name; }).join('، ') : '') + (count ? '، ' + faDigits(count) + ' سفارش فعال' : '')) + '"' + adjacentAttrs + '><span class="fo-day-number-row"><span class="fo-day-number">' + faDigits(cell.day) + '</span>' + (holidayName ? '<span class="fo-day-status is-holiday">تعطیل</span>' : '') + '</span><span class="fo-day-status ' + (orderState === 'open' ? 'is-open' : orderState === 'closed' ? 'is-closed' : '') + '">' + esc(status) + '</span>' + orderMarker + '<span class="fo-day-meals">' + mealText + '</span></button>';
    }).join('');
    return '<div class="fm-grid"><div class="fm-card fm-calendar-card"><div class="fm-toolbar"><div><h3>برنامهٔ غذایی — ' + esc(monthTitle()) + '</h3></div><div class="fm-actions"><button class="btn secondary" type="button" data-fm-action="month-prev">ماه قبل</button><button class="btn secondary" type="button" data-fm-action="month-today">امروز</button><button class="btn secondary" type="button" data-fm-action="month-next">ماه بعد</button></div></div><div class="fo-calendar-grid" role="grid" aria-label="تقویم برنامه غذایی">' + weekdayHeaders + cellsHtml + '</div><div class="fo-legend"><span><i class="legend-open"></i> روز باز</span><span><i class="legend-closed"></i> روز بسته</span><span><i class="legend-holiday"></i> تعطیل رسمی / پنجشنبه و جمعه</span><span><i class="legend-order"></i> سفارش ثبت‌شده</span><span><i class="legend-today"></i> امروز</span></div></div>' + renderDayEditor() + '</div>';
  }

  function renderDayEditor() {
    if (!state.selectedDate) return '<div class="fm-card"><div class="fm-empty">یک روز از تقویم انتخاب کنید.</div></div>';
    var iso = state.selectedDate;
    var day = getDay(iso);
    var scheduled = day && Array.isArray(day.items) ? day.items : [];
    var scheduledIds = scheduled.map(function (item) { return Number(item.food_id); });
    var title = jalaliFromIso(iso);
    var inactiveSchedule = scheduled.filter(function (item) { return item.food_active === false; });
    var dayReadOnly = state.status && state.status.today && iso < state.status.today;
    var impactPending = Boolean(state.pendingImpact && state.pendingImpact.date === iso);
    var editorLocked = Boolean(state.daySaving || impactPending || (day && day.order_status === 'closed' && !canClose) || (dayReadOnly && !isPrimary));
    var lockedMessage = day && day.order_status === 'closed' && !canClose ? 'این روز بسته است؛ برای تغییر برنامهٔ روز بسته مجوز food.order_close لازم است.' : (dayReadOnly && !isPrimary ? 'روز گذشته فقط‌خواندنی است؛ فقط ادمین اصلی می‌تواند برنامه‌اش را اصلاح کند.' : '');
    var choices = (state.catalog || []).map(function (food) {
      var included = scheduledIds.indexOf(Number(food.id)) >= 0;
      var inactive = !food.active;
      var label = food.food_name + (inactive ? ' · غیرفعال' : '');
      var disabled = editorLocked || (inactive && !included);
      return '<label class="fm-food-check"><input type="checkbox" name="food_ids" value="' + Number(food.id) + '" ' + (included ? 'checked' : '') + (disabled ? ' disabled' : '') + '><span>' + esc(label) + (inactive && included ? ' (فقط حفظ/حذف از برنامه)' : '') + '</span></label>';
    }).join('');
    var selectedStatus = day && day.order_status ? day.order_status : 'open';
    var statusControl = canClose
      ? '<fieldset class="fm-field fm-status-choice"><legend>وضعیت سفارش‌گیری</legend><label class="fm-radio"><input type="radio" name="order_status" value="open" ' + (selectedStatus === 'open' ? 'checked' : '') + (editorLocked ? ' disabled' : '') + '>باز</label><label class="fm-radio"><input type="radio" name="order_status" value="closed" ' + (selectedStatus === 'closed' ? 'checked' : '') + (editorLocked ? ' disabled' : '') + '>بسته</label></fieldset>'
      : '<div class="fm-alert">وضعیت روز: ' + esc(selectedStatus === 'closed' ? 'بسته' : 'باز') + ' — برای تغییر وضعیت دسترسی food.order_close لازم است.</div>';
    var impactButtonsDisabled = state.daySaving ? ' disabled' : '';
    var pending = state.pendingImpact && state.pendingImpact.date === iso ? '<div class="fm-confirm-box"><strong>' + esc(state.pendingImpactMessage || 'این تغییر سفارش فعال را لغو می‌کند.') + '</strong><span>برای انجام تغییر و ثبت لغوها، تأیید کنید. حذف فیزیکی سفارش انجام نمی‌شود.</span><button class="btn danger" type="button" data-fm-action="confirm-impact"' + impactButtonsDisabled + '>تأیید و ادامه</button><button class="btn secondary" type="button" data-fm-action="cancel-impact"' + impactButtonsDisabled + '>بازگشت</button></div>' : '';
    var message = state.dayError ? '<div class="fm-alert error">' + esc(state.dayError) + '</div>' : '';
    var itemsLabel = state.catalog.length ? '<div class="fm-food-checks">' + choices + '</div>' : '<div class="fm-empty">ابتدا از تب «بانک غذا» حداقل یک غذا ثبت کنید.</div>';
    return '<div class="fm-card"><div class="fm-toolbar"><div><h3>ویرایش برنامهٔ ' + esc(faDigits(title)) + '</h3><span class="fm-small">برای هر روز حداکثر سه گزینهٔ غذا می‌توانید ثبت کنید.</span></div>' + (canClose ? '<button class="btn secondary" type="button" data-fm-action="show-orders">سفارش‌های این روز</button>' : '') + '</div>' + (day && day.holiday_title ? '<div class="fm-alert warn">تعطیل رسمی: ' + esc(day.holiday_title) + ' — در صورت تصمیم سازمان، سفارش باز می‌تواند برقرار باشد.</div>' : '') + (lockedMessage ? '<div class="fm-alert warn">' + esc(lockedMessage) + '</div>' : '') + (inactiveSchedule.length ? '<div class="fm-alert warn">این برنامه شامل غذای غیرفعال است. آن را می‌توان برای حفظ سوابق نگه داشت یا پس از تأیید حذف کرد؛ سفارش جدید برای آن غذا ثبت نمی‌شود.</div>' : '') + message + pending + '<form id="fm-day-form" class="fm-day-editor"><div class="fm-field fm-food-selection"><div class="fm-food-selection-head"><strong>غذاهای این روز</strong><span class="fm-small">برای آینده؛ بدون تغییر سفارش‌های گذشته</span></div>' + itemsLabel + '</div>' + (canClose ? statusControl : '') + '<label class="fm-field">یادداشت کوتاه<input name="note" maxlength="255" value="' + esc(day && day.note ? day.note : '') + '" placeholder="اختیاری" ' + (editorLocked ? 'disabled' : '') + '></label><div class="fm-actions"><button class="btn primary" type="submit" ' + (editorLocked ? 'disabled' : '') + '>ذخیره برنامه</button><button class="btn secondary" type="button" data-fm-action="reload-month">بازخوانی</button></div></form></div>';
  }

  function renderCatalog() {
    var rows = (state.catalog || []).map(function (food) {
      return '<tr><td><form class="fm-name-form" data-fm-catalog-edit><input type="hidden" name="id" value="' + Number(food.id) + '"><input type="text" name="food_name" maxlength="190" value="' + esc(food.food_name) + '" aria-label="نام غذا"><select name="active" aria-label="وضعیت"><option value="1" ' + (food.active ? 'selected' : '') + '>فعال</option><option value="0" ' + (!food.active ? 'selected' : '') + '>غیرفعال</option></select><button class="btn secondary" type="submit">ذخیره</button></form></td><td>' + (food.active ? '<span class="fm-order-state">فعال</span>' : '<span class="fm-order-state cancelled">غیرفعال</span>') + '</td></tr>';
    }).join('');
    return '<div class="fm-card"><div class="fm-toolbar"><div><h3>بانک غذا</h3><span class="fm-small">غیرفعال‌سازی به‌جای حذف فیزیکی انجام می‌شود. غذای استفاده‌شده در تقویم قابل تغییر نام نیست تا سابقهٔ سفارش حفظ شود.</span></div></div><form id="fm-catalog-add" class="fm-catalog-form"><label class="fm-field">نام غذای جدید<input type="text" name="food_name" maxlength="190" required placeholder="مثلاً چلو مرغ"></label><button class="btn primary" type="submit">افزودن غذا</button></form><div class="fm-table-wrap fm-manager-data-warning"><table class="fm-rows"><thead><tr><th>نام غذا و ویرایش</th><th>وضعیت فعلی</th></tr></thead><tbody>' + (rows || '<tr><td colspan="2" class="fm-empty">غذایی ثبت نشده است.</td></tr>') + '</tbody></table></div></div>';
  }

  function renderOrders() {
    var fallbackDate = state.ordersDate || state.selectedDate || (state.status && state.status.today) || localTodayIso();
    var inputDate = jalaliFromIso(fallbackDate) || '';
    var dateLabel = faDigits(inputDate || '—');
    var actionsEnabled = state.ordersLoaded && !state.loadingOrders;
    var rows = state.orders || [];
    var tableRows = rows.map(function (row, index) {
      var active = row.status === 'active';
      var recordType = row.is_proxy ? 'نیابتی' : 'شخصی';
      return '<tr><td>' + faDigits(index + 1) + '</td><td>' + esc(faDigits(row.national_code || '—')) + '</td><td>' + esc(faDigits(row.employee_number || '—')) + '</td><td>' + esc(row.full_name || '—') + '</td><td>' + esc(row.food_name || '—') + '</td><td>' + esc(faDigits(jalaliFromIso(row.food_date))) + '</td><td>' + esc(faDigits(jalaliFromIso(row.reserve_date))) + ' ' + esc(faDigits((row.reserve_time || '').slice(0, 5))) + '</td><td><span class="fm-order-state ' + (active ? '' : 'cancelled') + '">' + (active ? 'فعال' : 'لغوشده') + '</span></td><td>' + recordType + '</td></tr>';
    }).join('');
    var daily = '<section class="fm-card fm-daily-orders"><div class="fm-toolbar"><div><h3>سفارش‌های روز</h3><span class="fm-small">تاریخ: ' + esc(dateLabel) + ' — فهرست سفارش‌های تاریخ انتخاب‌شده</span></div></div><form id="fm-orders-date" class="fm-order-date"><label class="fm-field">تاریخ روز شمسی<input type="text" name="date" data-jalali inputmode="numeric" placeholder="۱۴۰۵/۰۷/۰۱" value="' + esc(faDigits(inputDate)) + '" required></label><button class="btn primary" type="submit">مشاهده</button><button class="btn secondary" type="button" data-fm-action="orders-today">امروز</button><button class="btn secondary" type="button" data-fm-action="orders-export"' + (actionsEnabled ? '' : ' disabled') + '>استخراج Excel</button><button class="btn secondary" type="button" data-fm-action="orders-print"' + (actionsEnabled ? '' : ' disabled') + '>چاپ</button></form>' + (state.loadingOrders ? '<div class="fm-spinner">در حال بارگذاری سفارش‌ها…</div>' : '<div class="fm-table-wrap fm-manager-data-warning"><table class="fm-rows"><thead><tr><th>ردیف</th><th>کد ملی</th><th>کد پرسنلی</th><th>نام</th><th>غذا</th><th>تاریخ غذا</th><th>ثبت رزرو</th><th>وضعیت</th><th>نوع ثبت</th></tr></thead><tbody>' + (tableRows || '<tr><td colspan="9" class="fm-empty">برای این روز سفارشی پیدا نشد.</td></tr>') + '</tbody></table></div>') + '</section>';
    return daily;
  }

  function renderStatistics() {
    var fallbackDate = state.selectedDate || (state.status && state.status.today) || localTodayIso();
    var from = jalaliFromIso(state.statisticsFrom || fallbackDate) || '';
    var to = jalaliFromIso(state.statisticsTo || fallbackDate) || '';
    var mode = state.statisticsMode === 'employee' ? 'employee' : 'food';
    var modeTitle = mode === 'employee' ? 'آمار سفارش نفرات' : 'آمار نوع غذا';
    var rangeText = from === to ? faDigits(from) : faDigits(from) + ' تا ' + faDigits(to);
    var actionsEnabled = state.statisticsLoaded && !state.loadingStatistics;
    var result = '';
    if (state.loadingStatistics) {
      result = '<div class="fm-spinner">در حال محاسبهٔ آمار…</div>';
    } else if (!state.statisticsLoaded) {
      result = '<div class="fm-empty">برای مشاهده، بازهٔ موردنظر را انتخاب کنید.</div>';
    } else if (mode === 'employee') {
      var people = state.statistics || [];
      var peopleRows = [];
      for (var line = 0; line < Math.ceil(people.length / 3); line++) {
        var cells = '';
        for (var group = 0; group < 3; group++) {
          var person = people[line * 3 + group];
          if (person) {
            cells += '<td>' + faDigits(line * 3 + group + 1) + '</td><td>' + esc(person.full_name || '—') + '</td><td>' + faDigits(Number(person.count || 0)) + '</td>';
          } else {
            cells += '<td class="fm-statistics-empty-cell"></td><td class="fm-statistics-empty-cell"></td><td class="fm-statistics-empty-cell"></td>';
          }
        }
        peopleRows.push('<tr>' + cells + '</tr>');
      }
      result = '<div class="fm-table-wrap fm-statistics-results fm-statistics-results--people"><table class="fm-rows"><thead><tr><th>ردیف</th><th>نام پرسنل</th><th>تعداد</th><th>ردیف</th><th>نام پرسنل</th><th>تعداد</th><th>ردیف</th><th>نام پرسنل</th><th>تعداد</th></tr></thead><tbody>' + (peopleRows.join('') || '<tr><td colspan="9" class="fm-empty">در بازهٔ انتخاب‌شده سفارش فعالی ثبت نشده است.</td></tr>') + '</tbody></table></div>';
    } else {
      var foodRows = (state.statistics || []).map(function (item, index) {
        return '<tr><td>' + faDigits(index + 1) + '</td><td>' + esc(item.food_name || '—') + '</td><td>' + faDigits(Number(item.count || 0)) + '</td></tr>';
      }).join('');
      result = '<div class="fm-table-wrap fm-statistics-results"><table class="fm-rows"><thead><tr><th>ردیف</th><th>نوع غذا</th><th>تعداد</th></tr></thead><tbody>' + (foodRows || '<tr><td colspan="3" class="fm-empty">در بازهٔ انتخاب‌شده سفارشی ثبت نشده است.</td></tr>') + '</tbody></table></div>';
    }
    return '<section class="fm-card fm-statistics"><div class="fm-toolbar"><div><h3>' + modeTitle + '</h3><span class="fm-small">بازه: ' + esc(rangeText) + '</span></div></div><form id="fm-statistics-date" class="fm-statistics-form"><div class="fm-stats-modes" role="radiogroup" aria-label="نوع آمار"><label class="fm-stats-mode"><input type="radio" name="statistics_mode" value="food"' + (mode === 'food' ? ' checked' : '') + ' required><span>آمار نوع غذا</span></label><label class="fm-stats-mode"><input type="radio" name="statistics_mode" value="employee"' + (mode === 'employee' ? ' checked' : '') + ' required><span>آمار سفارش نفرات</span></label></div><div class="fm-order-date"><label class="fm-field">از تاریخ شمسی<input type="text" name="from" data-jalali inputmode="numeric" placeholder="۱۴۰۵/۰۷/۰۱" value="' + esc(faDigits(from)) + '" required></label><label class="fm-field">تا تاریخ شمسی<input type="text" name="to" data-jalali inputmode="numeric" placeholder="۱۴۰۵/۰۷/۳۰" value="' + esc(faDigits(to)) + '" required></label><button class="btn primary" type="submit">مشاهده</button><button class="btn secondary" type="button" data-fm-action="statistics-export"' + (actionsEnabled ? '' : ' disabled') + '>خروجی اکسل</button><button class="btn secondary" type="button" data-fm-action="statistics-print"' + (actionsEnabled ? '' : ' disabled') + '>پرینت</button></div></form>' + result + '</section>';
  }

  function render() {
    if (!root || !root.isConnected) root = document.getElementById('food-menu-root');
    if (!root) return;
    if (!canMenu && !canClose) {
      root.innerHTML = '<div class="fm-alert error">مجوز برنامهٔ غذایی یا مشاهدهٔ سفارش‌ها برای این حساب تعریف نشده است.</div>';
      return;
    }
    if (!Cal) {
      root.innerHTML = '<div class="fm-alert error">تقویم شمسی سامانه بارگذاری نشد. صفحه را تازه‌سازی کنید.</div>';
      return;
    }
    var panel = state.tab === 'calendar' && canMenu ? renderCalendar() : state.tab === 'catalog' && canMenu ? renderCatalog() : state.tab === 'orders' && canClose ? renderOrders() : state.tab === 'statistics' && canClose ? renderStatistics() : '<div class="fm-card fm-empty">این بخش با مجوزهای فعلی در دسترس نیست.</div>';
    var notices = (state.error ? '<div class="fm-alert error">' + esc(state.error) + '</div>' : '') + (state.notice && !state.error ? '<div class="fm-alert good">' + esc(state.notice) + '</div>' : '');
    root.innerHTML = '<div class="fm-intro"><div><h2>برنامه غذایی</h2></div></div>' + notices + navTabs() + panel;
    if (global.ItsmJalali && typeof global.ItsmJalali.enhanceAll === 'function') global.ItsmJalali.enhanceAll(root);
  }

  async function loadStatus() {
    if (!canMenu && !canClose) return null;
    try {
      state.status = await request('food-menu/status');
      if (!state.selectedDate && state.status.today) state.selectedDate = state.status.today;
      return state.status;
    } catch (e) {
      state.status = null;
      state.error = e.message || 'وضعیت سفارش بارگذاری نشد.';
      return null;
    }
  }
  async function loadCatalog() {
    if (!canMenu) return;
    try {
      var result = await request('food-menu/catalog');
      state.catalog = Array.isArray(result.items) ? result.items : [];
    } catch (e) { state.catalog = []; state.error = e.message || 'بانک غذا بارگذاری نشد.'; }
  }
  async function loadMonth() {
    if (!canMenu) return;
    state.loading = true;
    try {
      state.monthData = await request('food-menu/month', { month: monthKey() });
    } catch (e) { state.error = e.message || 'تقویم بارگذاری نشد.'; state.monthData = null; }
    finally { state.loading = false; render(); }
  }
  async function loadOrders(date) {
    if (!canClose) return;
    var selected = normalizeIsoDate(date || state.ordersDate || state.selectedDate);
    if (!selected) return;
    state.ordersDate = selected;
    state.selectedDate = selected;
    state.ordersLoaded = false;
    state.loadingOrders = true;
    render();
    try {
      var result = await request('food-menu/orders', { date: selected });
      state.orders = Array.isArray(result.items) ? result.items : [];
      state.ordersLoaded = true;
      state.error = '';
    } catch (e) { state.orders = []; state.ordersLoaded = false; state.error = e.message || 'سفارش‌های روز بارگذاری نشد.'; }
    finally { state.loadingOrders = false; render(); }
  }

  async function loadStatistics(fromDate, toDate, mode) {
    if (!canClose) return;
    var from = normalizeIsoDate(fromDate || state.statisticsFrom || state.selectedDate);
    var to = normalizeIsoDate(toDate || state.statisticsTo || from);
    var selectedMode = String(mode || state.statisticsMode || 'food');
    if (!from || !to || from > to || (selectedMode !== 'food' && selectedMode !== 'employee')) return;
    state.statisticsFrom = from;
    state.statisticsTo = to;
    state.statisticsMode = selectedMode;
    state.statistics = [];
    state.statisticsLoaded = false;
    state.loadingStatistics = true;
    var requestId = ++state.statisticsRequestId;
    render();
    try {
      var result = await request('food-menu/statistics', { from: from, to: to, mode: selectedMode });
      if (requestId !== state.statisticsRequestId) return;
      state.statistics = Array.isArray(result.items) ? result.items : [];
      state.statisticsLoaded = true;
      state.error = '';
    } catch (e) {
      if (requestId !== state.statisticsRequestId) return;
      state.statistics = [];
      state.error = e.message || 'آمار سفارش‌ها بارگذاری نشد.';
    } finally {
      if (requestId === state.statisticsRequestId) {
        state.loadingStatistics = false;
        render();
      }
    }
  }
  async function refreshAll() {
    state.error = ''; state.notice = '';
    await loadStatus();
    if (!state.selectedDate) state.selectedDate = (state.status && state.status.today) || localTodayIso();
    if (!state.ordersDate) state.ordersDate = state.selectedDate;
    if (!state.statisticsFrom) state.statisticsFrom = state.selectedDate;
    if (!state.statisticsTo) state.statisticsTo = state.selectedDate;
    var initialMonth = jalaliMonthFromIso(state.selectedDate);
    if (initialMonth && !state.monthData) { state.year = initialMonth[0]; state.month = initialMonth[1]; }
    var jobs = [];
    if (canMenu) { jobs.push(loadCatalog()); jobs.push(loadMonth()); }
    if (canClose && state.tab === 'orders') jobs.push(loadOrders(state.ordersDate || state.selectedDate));
    await Promise.all(jobs);
    render();
  }

  async function saveDay(confirmed, expectedImpact) {
    if (!canMenu || state.daySaving) return;
    var form = root.querySelector('#fm-day-form');
    if (!form) return;
    var body;
    if (confirmed && state.pendingImpact) {
      // Confirmation applies to the exact plan shown in the impact warning;
      // do not rebuild it from disabled/possibly edited form controls.
      body = Object.assign({}, state.pendingImpact);
      body.confirm_impact = true;
      body.expected_impact = Number(expectedImpact || 0);
    } else {
      var formData = new FormData(form);
      var foodIds = Array.from(form.querySelectorAll('input[name="food_ids"]:checked')).map(function (input) { return Number(input.value); });
      var statusField = form.querySelector('[name="order_status"]:checked');
      body = {
        date: state.selectedDate,
        food_ids: foodIds,
        order_status: statusField && !statusField.disabled ? String(statusField.value || '') : '',
        note: String(formData.get('note') || '').trim(),
      };
      if (confirmed) { body.confirm_impact = true; body.expected_impact = Number(expectedImpact || 0); }
    }
    state.daySaving = true;
    state.dayError = ''; state.pendingImpact = null; state.pendingImpactExpected = 0; state.pendingImpactMessage = '';
    var button = form.querySelector('[type="submit"]'); if (button) button.disabled = true;
    Array.from(form.querySelectorAll('input,select,textarea,button')).forEach(function (control) { control.disabled = true; });
    root.querySelectorAll('[data-fm-action="confirm-impact"],[data-fm-action="cancel-impact"]').forEach(function (control) { control.disabled = true; });
    try {
      var result = await request('food-menu/day', null, { method: 'POST', body: JSON.stringify(body) });
      state.notice = result.message || 'برنامهٔ روز ذخیره شد.'; state.error = ''; state.dayError = '';
      await loadMonth();
      if (canClose && state.tab === 'orders') await loadOrders(state.ordersDate || state.selectedDate);
    } catch (e) {
      if (e.payload && e.payload.confirmation_required) {
        state.pendingImpact = body;
        state.pendingImpactExpected = Number(e.payload.affected_orders || 0);
        state.pendingImpactMessage = e.payload.message || 'این تغییر سفارش‌های فعال را لغو می‌کند.';
      } else {
        state.dayError = e.message || 'ذخیرهٔ برنامه انجام نشد.';
      }
    } finally { state.daySaving = false; render(); }
  }

  async function saveCatalog(form) {
    if (!canMenu) return;
    var isNew = form.id === 'fm-catalog-add';
    var data = new FormData(form);
    var body = { id: Number(data.get('id') || 0), food_name: String(data.get('food_name') || '').trim(), active: String(data.get('active') || '1') === '1' };
    var saved = false;
    try {
      var result = await request('food-menu/catalog', null, { method: 'POST', body: JSON.stringify(body) });
      state.catalog = Array.isArray(result.items) ? result.items : [];
      state.notice = result.message || 'بانک غذا ذخیره شد.'; state.error = '';
      saved = true;
    } catch (e) { state.error = e.message || 'ذخیرهٔ غذا ناموفق بود.'; }
    render();
    if (isNew) {
      // Keep the new-food box active: cleared after a successful add, text kept on error.
      var input = global.document.querySelector('#fm-catalog-add input[name="food_name"]');
      if (input) {
        if (saved) input.value = '';
        else input.value = String(data.get('food_name') || '');
        input.focus();
      }
    }
  }


  function downloadOrders() {
    if (!canClose || state.tab !== 'orders' || !state.ordersLoaded || state.loadingOrders || !state.ordersDate) return;
    global.location.href = buildUrl('food-menu/orders-export', { date: state.ordersDate });
  }

  function printScopedReport(selector, bodyClass) {
    var doc = global.document;
    var target = root && root.querySelector(selector);
    if (!doc || !doc.body || !target || typeof global.print !== 'function') return;
    var printHost = doc.createElement('div');
    printHost.className = 'fm-print-host';
    printHost.appendChild(target.cloneNode(true));
    doc.body.appendChild(printHost);
    doc.body.classList.add(bodyClass);
    var cleaned = false;
    var cleanupTimer = null;
    function cleanup() {
      if (cleaned) return;
      cleaned = true;
      doc.body.classList.remove(bodyClass);
      if (printHost.parentNode) printHost.parentNode.removeChild(printHost);
      if (cleanupTimer !== null && typeof global.clearTimeout === 'function') global.clearTimeout(cleanupTimer);
      if (typeof global.removeEventListener === 'function') global.removeEventListener('afterprint', cleanup);
    }
    global.addEventListener('afterprint', cleanup, { once: true });
    try {
      global.print();
    } catch (error) {
      cleanup();
      throw error;
    }
    if (!cleaned) cleanupTimer = global.setTimeout(cleanup, 30000);
  }

  function printOrders() {
    if (!canClose || state.tab !== 'orders' || !state.ordersLoaded || state.loadingOrders) return;
    printScopedReport('.fm-daily-orders', 'fm-print-orders');
  }

  function downloadStatistics() {
    if (!canClose || !state.statisticsLoaded || !state.statisticsFrom || !state.statisticsTo) return;
    global.location.href = buildUrl('food-menu/export', { from: state.statisticsFrom, to: state.statisticsTo, mode: state.statisticsMode || 'food' });
  }

  function printStatistics() {
    if (!state.statisticsLoaded) return;
    printScopedReport('.fm-statistics', 'fm-print-statistics');
  }

  function selectDay(iso, currentMonth) {
    if (!currentMonth) return;
    state.selectedDate = iso;
    state.dayError = '';
    state.pendingImpact = null; state.pendingImpactExpected = 0; state.pendingImpactMessage = '';
    state.error = '';
    render();
  }
  function changeMonth(year, month, selectedIso) {
    state.year = Number(year); state.month = Number(month);
    state.selectedDate = selectedIso || Cal.isoDate(state.year, state.month, 1);
    state.error = ''; state.pendingImpact = null; state.pendingImpactExpected = 0; state.pendingImpactMessage = '';
    loadMonth();
  }
  function switchTab(tab) {
    if ((tab === 'calendar' || tab === 'catalog') && !canMenu) return;
    if ((tab === 'orders' || tab === 'statistics') && !canClose) return;
    state.tab = tab; state.error = ''; state.notice = ''; render();
    if (tab === 'orders') {
      var defaultOrderDate = state.ordersDate || state.selectedDate || (state.status && state.status.today) || localTodayIso();
      loadOrders(defaultOrderDate);
    }
    if (tab === 'statistics' && !state.statisticsLoaded) {
      loadStatistics(state.statisticsFrom || state.selectedDate, state.statisticsTo || state.selectedDate, state.statisticsMode || 'food');
    }
    if (tab === 'calendar' && !state.monthData && canMenu) loadMonth();
    if (tab === 'catalog' && !state.catalog.length && canMenu) loadCatalog().then(render);
  }

  function eventClick(event) {
    var tab = event.target.closest('[data-fm-tab]');
    if (tab) { switchTab(tab.dataset.fmTab); return; }
    var action = event.target.closest('[data-fm-action]');
    if (action) {
      var act = action.dataset.fmAction;
      if (act === 'month-prev') { var p = Cal.shiftMonth(state.year, state.month, -1); changeMonth(p[0], p[1]); }
      if (act === 'month-next') { var n = Cal.shiftMonth(state.year, state.month, 1); changeMonth(n[0], n[1]); }
      if (act === 'month-today') {
        if (state.status && state.status.today) { var td = jalaliMonthFromIso(state.status.today); if (td) changeMonth(td[0], td[1], state.status.today); }
        else { var today = new Date(); var j = global.FoodOrderCalendar.jalaliDateFromIso(localTodayIso()); changeMonth(j[0], j[1], Cal.isoDate(j[0], j[1], j[2])); }
      }
      if (act === 'reload-month') loadMonth();
      if (act === 'show-orders') {
        state.ordersDate = state.selectedDate;
        switchTab('orders');
      }
      if (act === 'confirm-impact') saveDay(true, state.pendingImpactExpected);
      if (act === 'cancel-impact') { state.pendingImpact = null; state.pendingImpactExpected = 0; state.pendingImpactMessage = ''; render(); }
      if (act === 'orders-export') downloadOrders();
      if (act === 'orders-print') printOrders();
      if (act === 'statistics-export') downloadStatistics();
      if (act === 'statistics-print') printStatistics();
      if (act === 'orders-today') {
        var todayIso = state.status && state.status.today ? state.status.today : localTodayIso();
        state.selectedDate = todayIso; state.ordersDate = todayIso;
        loadOrders(todayIso);
      }
      return;
    }
    var day = event.target.closest('[data-fm-day]');
    if (day) selectDay(day.dataset.fmDay, day.dataset.fmCurrent === '1');
  }

  function eventChange(event) {
    var input = event.target;
    if (!input || input.name !== 'statistics_mode') return;
    var selectedMode = input.value === 'employee' ? 'employee' : 'food';
    var form = input.form;
    var formData = form ? new FormData(form) : null;
    var fromDate = formData ? normalizeIsoDate(formData.get('from')) : state.statisticsFrom;
    var toDate = formData ? normalizeIsoDate(formData.get('to')) : state.statisticsTo;
    state.statisticsMode = selectedMode;
    if (fromDate) state.statisticsFrom = fromDate;
    if (toDate) state.statisticsTo = toDate;
    state.error = '';
    if (fromDate && toDate && fromDate <= toDate) {
      loadStatistics(fromDate, toDate, selectedMode);
    } else {
      state.statisticsRequestId++;
      state.statistics = [];
      state.statisticsLoaded = false;
      state.loadingStatistics = false;
      render();
    }
  }

  function eventSubmit(event) {
    var form = event.target;
    if (form.id === 'fm-day-form') {
      event.preventDefault(); saveDay(false); return;
    }
    if (form.id === 'fm-catalog-add') {
      event.preventDefault(); saveCatalog(form); return;
    }
    if (form.matches('[data-fm-catalog-edit]')) {
      event.preventDefault(); saveCatalog(form); return;
    }
    if (form.id === 'fm-orders-date') {
      event.preventDefault();
      var orderDate = normalizeIsoDate(new FormData(form).get('date'));
      if (!orderDate) { state.error = 'تاریخ روز را به‌درستی انتخاب کنید.'; render(); return; }
      state.error = ''; state.selectedDate = orderDate; state.ordersDate = orderDate; loadOrders(orderDate); return;
    }
    if (form.id === 'fm-statistics-date') {
      event.preventDefault();
      var statisticsForm = new FormData(form);
      var fromDate = normalizeIsoDate(statisticsForm.get('from'));
      var toDate = normalizeIsoDate(statisticsForm.get('to'));
      var statisticsMode = String(statisticsForm.get('statistics_mode') || state.statisticsMode || 'food');
      if (statisticsMode !== 'food' && statisticsMode !== 'employee') { state.error = 'نوع آمار را انتخاب کنید.'; render(); return; }
      if (!fromDate || !toDate) { state.error = 'تاریخ شروع و پایان را به‌درستی انتخاب کنید.'; render(); return; }
      if (fromDate > toDate) { state.error = 'تاریخ پایان باید برابر یا پس از تاریخ شروع باشد.'; render(); return; }
      var spanDays = (Date.parse(toDate + 'T00:00:00Z') - Date.parse(fromDate + 'T00:00:00Z')) / 86400000;
      if (spanDays > 366) { state.error = 'بازهٔ آمار حداکثر یک سال است.'; render(); return; }
      state.error = ''; state.statisticsFrom = fromDate; state.statisticsTo = toDate; state.statisticsMode = statisticsMode; loadStatistics(fromDate, toDate, statisticsMode); return;
    }
  }

  function mount() {
    root = document.getElementById('food-menu-root');
    if (!root || root.dataset.fmMounted === '1') return;
    root.dataset.fmMounted = '1';
    root.addEventListener('click', eventClick);
    root.addEventListener('change', eventChange);
    root.addEventListener('submit', eventSubmit);
    var current = state.selectedDate ? jalaliMonthFromIso(state.selectedDate) : null;
    if (current) { state.year = current[0]; state.month = current[1]; }
    render();
    refreshAll();
  }

  global.FoodMenuUI = { html: function () { return '<div id="food-menu-root"><div class="fm-card fm-spinner">در حال آماده‌سازی برنامهٔ غذایی…</div></div>'; }, mount: mount };
  if (typeof document !== 'undefined') document.dispatchEvent(new Event('food-menu-ready'));
  if (typeof module !== 'undefined' && module.exports) module.exports = global.FoodMenuUI;
})(typeof window !== 'undefined' ? window : globalThis);
