/* سفارش غذای مهمان (فقط مدیران و بالاتر): از دکمهٔ تقویم در یک پاپ‌آپ باز می‌شود و بعد از ثبت بسته می‌شود. */
(function () {
  'use strict';

  var host = document.getElementById('food-guest-app');
  if (!host) return;

  var apiBase = (host.dataset.api || '').replace(/&amp;/g, '&');
  var csrf = host.dataset.csrf || '';
  var selfName = host.dataset.selfName || '';
  var state = {
    items: [],
    myDeputy: null,
    loadError: '',
    formError: '',
    saving: false,
    form: { request_date: '', organization: '', guest_count: '', requester_name: selfName, note: '' }
  };
  var dialog = null;

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  }
  function faDigits(value) { return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; }); }
  function normDigits(value) {
    return String(value == null ? '' : value).replace(/[۰-۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); })
      .replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); });
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
    try { payload = text ? JSON.parse(text) : {}; } catch (e) { throw new Error('پاسخ معتبر از سرور دریافت نشد (کد ' + response.status + ').'); }
    if (!response.ok) throw new Error(payload.error || ('خطای سرویس ' + response.status));
    return payload || {};
  }

  /** پیام کوتاه روی صفحه (بیرون از پاپ‌آپ تا بعد از بسته شدن هم دیده شود). */
  function toast(message, bad) {
    var node = document.createElement('div');
    node.className = 'alert ' + (bad ? 'danger' : 'success');
    node.setAttribute('role', bad ? 'alert' : 'status');
    node.textContent = message;
    node.style.cssText = 'position:fixed;bottom:22px;left:50%;transform:translateX(-50%);z-index:10000;min-width:260px;max-width:92vw;box-shadow:0 8px 24px rgba(0,0,0,.2)';
    document.body.appendChild(node);
    window.setTimeout(function () { node.remove(); }, 4000);
  }

  /** مقادیر فعلی فرم را قبل از بازسازی نگه می‌دارد تا با خطا یا تغییر وضعیت پاک نشوند. */
  function readForm() {
    var form = dialog && dialog.querySelector('#fg-form');
    if (!form) return;
    var fd = new FormData(form);
    state.form = {
      request_date: String(fd.get('request_date') || ''),
      organization: String(fd.get('organization') || ''),
      guest_count: String(fd.get('guest_count') || ''),
      requester_name: String(fd.get('requester_name') || ''),
      note: String(fd.get('note') || '')
    };
  }

  function render() {
    var f = state.form;
    var deputyBox = state.myDeputy
      ? '<div class="alert info" role="status">معاونت درخواست‌کننده: <b>' + esc(state.myDeputy.name) + '</b> (از چارت سازمانی شما خوانده می‌شود)</div>'
      : '<div class="alert danger" role="alert">معاونت شما در چارت سازمانی پیدا نشد؛ ثبت سفارش مهمان غیرفعال است. با مدیر سامانه هماهنگ کنید.</div>';
    var rows = state.items.map(function (r) {
      var action = r.editable && r.status === 'active'
        ? '<button type="button" class="button secondary" data-fg-action="cancel" data-id="' + r.id + '">لغو</button>'
        : '<span class="muted">—</span>';
      var status = r.status === 'cancelled' ? '<span class="muted">لغو شده</span>' : 'فعال';
      return '<tr>'
        + '<td>' + esc(faDigits(r.request_jalali)) + '</td>'
        + '<td>' + esc(r.deputy_name || '—') + '</td>'
        + '<td>' + esc(r.organization) + '</td>'
        + '<td>' + esc(faDigits(r.guest_count)) + '</td>'
        + '<td>' + esc(r.requester_name) + '</td>'
        + '<td>' + status + '</td>'
        + '<td>' + action + '</td></tr>';
    }).join('');
    var table = state.items.length
      ? '<div class="table-wrap"><table class="ticket-table" style="width:100%;border-collapse:collapse"><thead><tr>'
        + '<th>تاریخ</th><th>معاونت</th><th>سازمان / شرکت</th><th>تعداد</th><th>درخواست‌دهنده</th><th>وضعیت</th><th></th>'
        + '</tr></thead><tbody>' + rows + '</tbody></table></div>'
      : '<p class="muted">درخواست فعالی برای روزهای آینده ثبت نشده است.</p>';
    dialog.innerHTML = ''
      + '<div class="fo-modal-inner">'
      + '<div class="fo-modal-head"><div><h2 id="fg-title">سفارش غذای مهمان</h2>'
      + '</div>'
      + '<button type="button" class="button secondary" data-fg-action="close">بستن</button></div>'
      + (state.loadError ? '<div class="alert danger" role="alert">' + esc(state.loadError) + '</div>' : '')
      + deputyBox
      + (state.formError ? '<div class="alert danger" role="alert">' + esc(state.formError) + '</div>' : '')
      + '<form id="fg-form" class="form-grid" novalidate>'
      + '<label>تاریخ مورد نیاز (شمسی)<input type="text" inputmode="numeric" autocomplete="off" data-jalali name="request_date" value="' + esc(f.request_date) + '" required></label>'
      + '<label>سازمان / شرکت مهمان<input type="text" name="organization" maxlength="190" value="' + esc(f.organization) + '" required></label>'
      + '<label>تعداد مهمان<input type="text" inputmode="numeric" name="guest_count" maxlength="3" value="' + esc(f.guest_count) + '" required></label>'
      + '<label>نام درخواست‌دهنده<input type="text" name="requester_name" maxlength="150" value="' + esc(f.requester_name) + '" required></label>'
      + '<label>یادداشت (اختیاری)<input type="text" name="note" maxlength="500" value="' + esc(f.note) + '"></label>'
      + '<div><button type="submit" class="button primary" data-fg-action="save"' + (state.saving || !state.myDeputy ? ' disabled' : '') + '>ثبت درخواست مهمان</button></div>'
      + '</form>'
      + '<h3 style="margin-top:20px">درخواست‌های ثبت‌شدهٔ مهمان (از امروز به بعد)</h3>'
      + table
      + '</div>';
    if (window.ItsmJalali && typeof window.ItsmJalali.enhanceAll === 'function') window.ItsmJalali.enhanceAll(dialog);
  }

  function ensureDialog() {
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.id = 'fg-dialog';
    dialog.className = 'fo-modal';
    dialog.setAttribute('aria-labelledby', 'fg-title');
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) { dialog.close(); return; }
      var btn = event.target.closest('[data-fg-action]');
      if (!btn) return;
      var act = btn.dataset.fgAction;
      if (act === 'close') dialog.close();
      if (act === 'cancel') cancel(Number(btn.dataset.id || 0));
    });
    dialog.addEventListener('submit', function (event) {
      if (!event.target || event.target.id !== 'fg-form') return;
      event.preventDefault();
      save();
    });
    document.body.appendChild(dialog);
    return dialog;
  }

  async function load() {
    state.loadError = '';
    try {
      var data = await request('guest-requests');
      state.items = data.items || [];
      state.myDeputy = data.my_deputy || null;
    } catch (e) {
      state.loadError = e.message;
    }
    readForm();
    render();
  }

  async function save() {
    if (state.saving) return;
    readForm();
    var f = state.form;
    var body = {
      request_date: normDigits(f.request_date).trim(),
      organization: f.organization.trim(),
      guest_count: normDigits(f.guest_count).trim(),
      requester_name: f.requester_name.trim(),
      note: f.note.trim()
    };
    state.formError = '';
    if (!body.request_date) { state.formError = 'تاریخ را وارد کنید.'; return render(); }
    if (!body.organization) { state.formError = 'نام سازمان یا شرکت را وارد کنید.'; return render(); }
    if (!/^\d{1,3}$/.test(body.guest_count) || Number(body.guest_count) < 1 || Number(body.guest_count) > 500) {
      state.formError = 'تعداد مهمان باید عددی بین ۱ تا ۵۰۰ باشد.'; return render();
    }
    if (!body.requester_name) { state.formError = 'نام درخواست‌دهنده را وارد کنید.'; return render(); }
    state.saving = true;
    render();
    try {
      var res = await request('guest-request', null, { method: 'POST', body: JSON.stringify(body) });
      state.saving = false;
      state.form = { request_date: '', organization: '', guest_count: '', requester_name: selfName, note: '' };
      dialog.close();
      toast(res.message || 'درخواست غذای مهمان ثبت شد.', false);
    } catch (e) {
      state.saving = false;
      state.formError = e.message;
      render();
    }
  }

  async function cancel(id) {
    if (!window.confirm('این درخواست غذای مهمان لغو شود؟')) return;
    try {
      var res = await request('guest-request-cancel', null, { method: 'POST', body: JSON.stringify({ id: id }) });
      toast(res.message || 'لغو شد.', false);
      await load();
    } catch (e) {
      toast(e.message, true);
    }
  }

  /** از دکمهٔ «سفارش غذای مهمان» تقویم صدا زده می‌شود. */
  function open() {
    ensureDialog();
    state.formError = '';
    render();
    if (!dialog.open) {
      try { dialog.showModal(); } catch (e) { dialog.setAttribute('open', 'open'); }
    }
    load();
  }

  window.FoodGuest = { open: open };
})();
