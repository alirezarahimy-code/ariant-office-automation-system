/**
 * مدیریت گروه‌های غذا — بخش «تنظیمات سیستم ← گروه‌های غذا»
 * ۱.۳۲: شناسایی نمایندهٔ گروه با L_UID (نه کارت RFID) + لیست غیبت روزانه داخل همین فرم.
 * هیچ صفحهٔ جداگانهٔ غیبت وجود ندارد؛ غیبت، بخشی از همین فرم مدیریت گروه/کارکنان است.
 */
(function () {
  'use strict';
  if (typeof window === 'undefined') return;

  const S = {
    loaded: false, loading: false, error: '', groups: [], free: [], runs: [],
    editing: null, filter: '',
    // ویرایشگر غیبت (داخل همین صفحه)
    absenceError: '',
    absence: null,   // {group_id, date, locked, editable, can_override, members:[], summary:{}}
    absenceLoading: false,
  };

  const esc = (x) => String(x == null ? '' : x).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const say = (m) => { try { if (typeof toast === 'function') toast(m); } catch (e) { /* ignore */ } };
  const box = () => document.getElementById('food-groups-root');
  const callApi = (path, opts) => (typeof api === 'function' ? api(path, opts) : Promise.reject(new Error('اتصال به سرور در دسترس نیست.')));
  const todayIso = () => {
    try {
      const f = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit' });
      return f.format(new Date());
    } catch (e) { return new Date().toISOString().slice(0, 10); }
  };

  function draw() {
    const el = box();
    if (!el) return;
    el.innerHTML = body();
    // تقویم شمسی مشترک سامانه (assets/jalali-calendar.js) روی فیلد تاریخ غیبت فعال می‌شود
    try {
      if (window.ItsmJalali && typeof window.ItsmJalali.enhanceAll === 'function') {
        window.ItsmJalali.enhanceAll(el);
      }
    } catch (e) { /* تقویم اختیاری است؛ ورودی دستی «۱۴۰۵/۰۷/۱۴» هم کار می‌کند */ }
  }

  function load() {
    if (S.loading) return;
    S.loading = true;
    callApi('/api/food-groups').then((x) => {
      apply(x);
      S.loaded = true;
      S.error = '';
    }).catch((e) => {
      S.error = (e && e.message) || 'خطا در دریافت گروه‌ها';
    }).then(() => { S.loading = false; draw(); });
  }

  function apply(x) {
    S.groups = (x && x.groups) || [];
    S.free = (x && x.free_users) || [];
    S.runs = (x && x.runs) || [];
  }

  function userLabel(u) {
    return esc(u.name || '—') + ' <small style="color:var(--muted)">(' + esc(u.pc) + ')</small>'
      + (u.active ? '' : ' <span class="status bad">غیرفعال</span>')
      + (u.nat ? '' : ' <span class="status warn" title="بدون کد ملی، سفارش تطبیق داده نمی‌شود">بدون کد ملی</span>');
  }

  function form() {
    const g = S.editing;
    if (!g) return '';
    const sel = new Set((g.members || []).map(Number));
    const q = (S.filter || '').trim();
    // فقط کاربران آزاد + اعضای فعلیِ همین گروه (اعضای گروه‌های دیگر نمایش داده نمی‌شوند)
    const mine = (S.groups.find((x) => x.id === g.id) || { members: [] }).members;
    const pool = [...mine, ...S.free].filter((u, i, a) => a.findIndex((v) => v.id === u.id) === i)
      .filter((u) => !q || (u.name + ' ' + u.pc + ' ' + u.nat).indexOf(q) >= 0);
    const rows = pool.map((u) => '<label style="display:flex;gap:8px;align-items:center;padding:4px 0">'
      + '<input type="checkbox" data-fg-member value="' + u.id + '"' + (sel.has(u.id) ? ' checked' : '') + '> <span>' + userLabel(u) + '</span></label>').join('')
      || '<p class="muted">کاربر آزادی برای انتخاب وجود ندارد.</p>';
    return '<div class="card form-card" id="fg-form"><div class="form-section-head"><h2>' + (g.id ? 'ویرایش گروه' : 'گروه جدید') + '</h2></div>'
      + '<div class="form-grid">'
      + '<label class="field">نام گروه<input id="fg-title" value="' + esc(g.title) + '" maxlength="190"></label>'
      + '<label class="field">L_UID نمایندهٔ گروه (شناسهٔ اصلی)<input id="fg-uid" value="' + esc(g.l_uid || '') + '" maxlength="80" dir="ltr" placeholder="مثلاً 0100"></label>'
      + '<label class="field"><span>وضعیت</span><select id="fg-active"><option value="1"' + (g.active ? ' selected' : '') + '>فعال</option><option value="0"' + (g.active ? '' : ' selected') + '>غیرفعال</option></select></label>'
      + '<label class="field full">توضیحات<input id="fg-desc" value="' + esc(g.description || '') + '" maxlength="500"></label>'
      + '</div>'
      + '<p class="muted" style="margin:6px 0">شناسایی نماینده فقط و فقط با <b>L_UID</b> انجام می‌شود (L_UID → گروه → اعضا → سفارش → غیبت امروز → واجد شرایط‌ها → فیش). شمارهٔ کارت (C_CARD) در این سامانه هیچ نقشی ندارد و از این فرم حذف شده است؛ گروه‌هایی که L_UID ندارند باید L_UID آن‌ها ثبت شود. تعداد فیش را سامانه محاسبه می‌کند.</p>'
      + '<div class="form-section-head" style="margin-top:14px"><h2>اعضای گروه</h2><span class="muted">فقط کاربران آزاد؛ هر کاربر فقط در یک گروه</span></div>'
      + '<input id="fg-filter" placeholder="جست‌وجو (نام، کد پرسنلی، کد ملی)" value="' + esc(S.filter) + '" style="margin:6px 0;width:100%">'
      + '<div id="fg-members" style="max-height:320px;overflow:auto;border:1px solid var(--line,#ddd);border-radius:8px;padding:6px 10px">' + rows + '</div>'
      + '<div class="actions" style="margin-top:12px"><button class="btn primary" data-fg="save">ذخیره گروه</button><button class="btn ghost" data-fg="cancel">انصراف</button></div></div>';
  }

  function list() {
    if (!S.groups.length) return '<p class="muted">هنوز گروهی تعریف نشده است.</p>';
    return '<div class="table-wrap"><table class="table"><thead><tr><th>نام گروه</th><th>L_UID نماینده</th><th>اعضا</th><th>غایب امروز</th><th>وضعیت</th><th></th></tr></thead><tbody>'
      + S.groups.map((g) => '<tr><td>' + esc(g.title) + (g.description ? '<small style="display:block;color:var(--muted)">' + esc(g.description) + '</small>' : '') + '</td>'
        + '<td dir="ltr">' + (g.l_uid ? esc(g.l_uid) : '<span class="status bad">تعریف نشده</span>') + '</td>'
        + '<td>' + g.members.length + ' نفر</td>'
        + '<td>' + ((g.today && g.today.absent) || 0) + ' نفر' + (g.today && g.today.locked ? ' <span class="status warn" title="لیست غیبت امروز قفل شده است">🔒</span>' : '') + '</td>'
        + '<td><span class="status ' + (g.active ? 'ok' : 'bad') + '">' + (g.active ? 'فعال' : 'غیرفعال') + '</span></td>'
        + '<td><button class="btn primary" data-fg="absence" data-id="' + g.id + '">غیبت امروز</button> '
        + '<button class="btn ghost" data-fg="edit" data-id="' + g.id + '">ویرایش</button> '
        + '<button class="btn orange" data-fg="delete" data-id="' + g.id + '">حذف</button></td></tr>').join('')
      + '</tbody></table></div>';
  }

  // ───────── تاریخ شمسی: نمایش شمسی، ذخیرهٔ میلادی (تاریخ API همیشه Y-m-d است) ─────────
  function isoToday() {
    try { return isoDateFor(todayJalali()) || new Date().toISOString().slice(0, 10); } catch (e) { return new Date().toISOString().slice(0, 10); }
  }
  /** ISO میلادی → «۱۴۰۵/۰۷/۱۴» (برای نمایش و ورودی) */
  function jalaliLabel(iso) {
    const v = isoDateFor(iso);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v || '')) return '';
    try {
      return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: '2-digit', day: '2-digit' })
        .format(new Date(v + 'T00:00:00'));
    } catch (e) {
      return v;
    }
  }
  /** «۱۴۰۵/۰۷/۱۴» یا «1405/7/14» → ISO میلادی Y-m-d ('' اگر نامعتبر) */
  function isoFromJalaliInput(value) {
    const raw = (typeof normalizeDigits === 'function' ? normalizeDigits(String(value == null ? '' : value)) : String(value == null ? '' : value)).trim();
    if (!raw) return '';
    // دام: «1405-07-14» شمسی است نه سال ۱۴۰۵ میلادی؛ تشخیص با بازهٔ سال
    const parts = raw.match(/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/);
    if (parts && Number(parts[1]) >= 1200 && Number(parts[1]) <= 1600) {
      const jalaliIso = isoDateFor(parts[1] + '/' + parts[2] + '/' + parts[3]);
      return /^\d{4}-\d{2}-\d{2}$/.test(jalaliIso || '') ? jalaliIso : '';
    }
    const iso = isoDateFor(raw);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso || '')) return '';
    const year = Number(iso.slice(0, 4));
    return (year >= 1900 && year <= 2100) ? iso : '';
  }
  /** ادغام پاسخ API با وضعیت قبلی؛ group_id هرگز گم نشود (باگ «گروه یا تاریخ معتبر نیست») */
  function mergeAbsence(prev, payload, fallbackGroupId) {
    const p = prev || {};
    const x = payload || {};
    const gid = Number((x.group_id || (x.group && x.group.id) || p.group_id || fallbackGroupId || 0)) || 0;
    const out = Object.assign({}, p, x);
    out.group_id = gid;
    out.date = (x.date || p.date || '').toString().slice(0, 10);
    return out;
  }

  /** ویرایشگر غیبت روزانه — داخل همین صفحه (بدون مسیر/فرم جداگانه) */
  function absencePanel() {
    const a = S.absence;
    if (!a) return '';
    if (S.absenceLoading) return '<div class="card section-gap"><p class="muted">در حال بارگذاری وضعیت غیبت…</p></div>';
    if (S.absenceError) {
      return '<div class="card section-gap" id="fg-absence"><div class="form-section-head"><h2>غیبت روزانه</h2></div>'
        + '<p style="color:var(--red)">' + esc(S.absenceError) + '</p>'
        + '<div class="actions"><button class="btn ghost" data-fg="abs-retry">تلاش دوباره</button>'
        + '<button class="btn ghost" data-fg="abs-close">بستن</button></div></div>';
    }
    const date = a.date || isoToday();
    const dateJalali = jalaliLabel(date);
    const locked = !!a.locked;
    const editable = !!a.editable;
    const s = a.summary || {};
    const rows = (a.members || []).map((m) => {
      const disabled = (!editable || locked && !a.can_override) ? ' disabled' : '';
      return '<label style="display:flex;gap:8px;align-items:center;padding:4px 0">'
        + '<input type="checkbox" data-fg-absent value="' + m.id + '"' + (m.absent ? ' checked' : '') + disabled + '> '
        + '<span>' + esc(m.name) + ' <small style="color:var(--muted)">(' + esc(m.pc) + ')</small>'
        + (m.active ? '' : ' <span class="status bad">غیرفعال</span>')
        + (m.national_code ? '' : ' <span class="status warn">بدون کد ملی</span>') + '</span></label>';
    }).join('') || '<p class="muted">این گروه عضوی ندارد.</p>';
    return '<div class="card section-gap" id="fg-absence"><div class="form-section-head">'
      + '<h2>غیبت روزانه — ' + esc(a.group ? a.group.title : '') + (dateJalali ? ' <small style="color:var(--muted)">(' + esc(dateJalali) + ')</small>' : '') + '</h2>'
      + '<span class="status ' + (locked ? 'warn' : 'ok') + '">' + esc(a.lock_label || (locked ? '🔒 قفل شده' : 'قابل ویرایش')) + '</span>'
      + '</div>'
      + '<div class="form-grid">'
      + '<label class="field">تاریخ (شمسی)'
      + '<span style="display:flex;gap:6px;align-items:center">'
      + '<input id="fg-abs-date" data-jalali value="' + esc(dateJalali) + '" placeholder="۱۴۰۵/۰۷/۱۴" dir="ltr" autocomplete="off" inputmode="numeric" style="flex:1">'
      + '<button type="button" class="btn ghost" data-fg="abs-today">امروز</button>'
      + '</span>'
      + '<small class="muted">تاریخ شمسی؛ مثال: ۱۴۰۵/۰۷/۱۴ — معادل میلادیِ ذخیره‌شده: <span dir="ltr">' + esc(date) + '</span></small>'
      + '</label>'
      + '<label class="field full">دلیل اصلاح (در صورت قفل بودن الزامی)<input id="fg-abs-reason" maxlength="300" placeholder="مثلاً: اصلاح اشتباه ثبت غیبت"></label>'
      + '</div>'
      + '<div class="grid" style="margin:8px 0">'
      + '<div class="card stat"><span class="label">تاریخ</span><strong>' + esc(jalaliLabel(s.date || date) || s.date || date) + '</strong></div>'
      + '<div class="card stat"><span class="label">اعضا</span><strong>' + Number(s.total || 0) + '</strong></div>'
      + '<div class="card stat"><span class="label">حاضر</span><strong>' + Number(s.present || 0) + '</strong></div>'
      + '<div class="card stat"><span class="label">غایب</span><strong>' + Number(s.absent || 0) + '</strong></div>'
      + '<div class="card stat"><span class="label">فیش متوقف‌شده</span><strong>' + Number(a.held_tickets || 0) + '</strong></div>'
      + '</div>'
      + '<p class="muted">غیبت به‌صورت رکورد همان تاریخ ذخیره می‌شود؛ نبودِ رکورد یعنی «حاضر». با نخستین تردد معتبر نماینده، لیست این تاریخ قفل می‌شود (هیچ قاعده‌ای بر اساس ساعت وجود ندارد). '
      + 'اگر عضو غایب‌شده فیشِ چاپ‌نشده داشته باشد، فیشش خودکار <b>متوقف</b> می‌شود تا چاپ نشود و اگر بعداً «حاضر» شود، خودکار به صف چاپ برمی‌گردد.</p>'
      + '<div style="max-height:320px;overflow:auto;border:1px solid var(--line,#ddd);border-radius:8px;padding:6px 10px">' + rows + '</div>'
      + '<div class="actions" style="margin-top:12px">'
      + '<button class="btn primary" data-fg="abs-save"' + (editable ? '' : ' disabled') + '>ذخیرهٔ غیبت این تاریخ</button>'
      + '<button class="btn ghost" data-fg="abs-clear"' + (editable ? '' : ' disabled') + '>همه حاضر</button>'
      + '<button class="btn ghost" data-fg="abs-close">بستن</button>'
      + '</div></div>';
  }

  const KIND = { first: 'اجرای اول', repeat: 'تکرار', inactive: 'گروه غیرفعال' };
  const TRIGGER = { uid: 'L_UID' };
  function runs() {
    if (!S.runs.length) return '';
    return '<div class="card section-gap"><div class="form-section-head"><h2>آخرین اجراهای گروهی</h2></div><div class="table-wrap"><table class="table"><thead><tr><th>گروه</th><th>تاریخ/ساعت</th><th>ماشه</th><th>نوع</th><th>چاپ</th><th>غایب</th><th>قبلاً فیش</th><th>بدون سفارش</th><th>سایر</th><th>خطا</th></tr></thead><tbody>'
      + S.runs.map((r) => '<tr><td>' + esc(r.group_title || '—') + '</td><td>' + esc(r.punch_date) + ' ' + esc(r.punch_time || '') + '</td>'
        + '<td dir="ltr">' + esc(TRIGGER[r.trigger_kind] || r.trigger_kind || '—') + (r.trigger_uid ? ' <small style="color:var(--muted)">' + esc(r.trigger_uid) + '</small>' : '') + '</td>'
        + '<td>' + esc(KIND[r.run_kind] || r.run_kind) + (r.status === 'processing' ? ' <span class="status warn">ناتمام</span>' : '') + '</td>'
        + '<td>' + Number(r.printed_count) + '</td><td>' + Number(r.absent_count || 0) + '</td><td>' + Number(r.repeat_count) + '</td><td>' + Number(r.no_food_count) + '</td><td>' + Number(r.skipped_count) + '</td><td>' + Number(r.error_count) + '</td></tr>').join('')
      + '</tbody></table></div></div>';
  }

  function body() {
    if (S.error) return '<div class="card"><p style="color:var(--red)">' + esc(S.error) + '</p><button class="btn ghost" data-fg="reload">تلاش دوباره</button></div>';
    if (!S.loaded) return '<div class="card"><p class="muted">در حال بارگذاری…</p></div>';
    return (S.editing ? form() : '<div class="card"><div class="form-section-head"><h2>گروه‌های تعریف‌شده</h2><button class="btn primary" data-fg="new">＋ گروه جدید</button></div>' + list() + '</div>')
      + absencePanel()
      + (S.editing ? '' : runs());
  }

  function html() {
    const out = '<section><div class="heading"><div><h2>مدیریت گروه‌های غذا</h2><p>نمایندهٔ هر گروه با <b>L_UID</b> شناسایی می‌شود؛ با تردد نماینده، فیش اعضای واجد شرایط (دارای سفارش امروز، غیرغایب، بدون فیش قبلی) در صف چاپ قرار می‌گیرد. غیبت هر روز مستقل ثبت می‌شود و با نخستین تردد نمایندهٔ همان روز قفل می‌شود.</p></div></div>'
      + '<div id="food-groups-root">' + body() + '</div></section>';
    // کاربر فقط «مشاهده» دارد: ظاهر فرم‌ها غیرفعال می‌شود (سرور هم POST را با food.groups می‌بندد).
    if (window.FOOD_TICKET_CAN_GROUPS_EDIT === false) {
      return '<section class="fg-readonly"><style>.fg-readonly button,.fg-readonly input:not([type=search]),.fg-readonly select,.fg-readonly textarea{pointer-events:none;opacity:.6}</style>' + out.slice('<section>'.length);
    }
    return out;
  }

  function collect() {
    return {
      id: S.editing ? S.editing.id : 0,
      title: (document.getElementById('fg-title') || {}).value || '',
      l_uid: (document.getElementById('fg-uid') || {}).value || '',
      description: (document.getElementById('fg-desc') || {}).value || '',
      active: ((document.getElementById('fg-active') || {}).value || '1') === '1',
      members: Array.from(document.querySelectorAll('[data-fg-member]:checked')).map((c) => Number(c.value)),
    };
  }

  function loadAbsence(groupId, date) {
    const gid = Number(groupId) || 0;
    const isoDate = /^\d{4}-\d{2}-\d{2}$/.test(date || '') ? date : isoToday();
    if (gid <= 0) { say('ابتدا یک گروه را انتخاب کنید.'); return; }
    S.absence = mergeAbsence(S.absence, { group_id: gid, date: isoDate, members: [], summary: {}, locked: false, editable: true }, gid);
    S.absenceError = '';
    S.absenceLoading = true;
    draw();
    callApi('/api/absence-status?group_id=' + encodeURIComponent(gid) + '&date=' + encodeURIComponent(isoDate))
      .then((x) => { S.absence = mergeAbsence(S.absence, x, gid); })
      .catch((e) => {
        S.absenceError = (e && e.message) || 'خطا در دریافت وضعیت غیبت';
        S.absence = mergeAbsence(S.absence, { members: [] }, gid);
      })
      .then(() => { S.absenceLoading = false; draw(); });
  }

  document.addEventListener('input', (e) => {
    if (e.target && e.target.id === 'fg-filter' && S.editing) {
      const keep = collect();
      S.editing = Object.assign({}, S.editing, keep, { members: keep.members.concat(((S.editing.members) || []).filter((id) => !document.querySelector('[data-fg-member][value="' + id + '"]'))) });
      S.filter = e.target.value;
      draw();
      const f = document.getElementById('fg-filter');
      if (f) { f.focus(); f.setSelectionRange(f.value.length, f.value.length); }
      return;
    }
  });

  document.addEventListener('change', (e) => {
    if (e.target && e.target.id === 'fg-abs-date' && S.absence) {
      const iso = isoFromJalaliInput(e.target.value);
      if (!iso) {
        say('تاریخ شمسی معتبر نیست؛ نمونهٔ درست: ۱۴۰۵/۰۷/۱۴');
        draw();
        return;
      }
      loadAbsence(S.absence.group_id, iso);
    }
  });

  // تقویم شمسی مقدار را برنامه‌نویسی‌شده می‌نویسد و رویداد change نمی‌فرستد؛
  // بعد از هر کلیک روی روزهای تقویم، مقدار فیلد خوانده و همان تاریخ بارگذاری می‌شود.
  document.addEventListener('click', (e) => {
    if (!e.target.closest || !e.target.closest('[data-jalali-picker]')) return;
    window.setTimeout(() => {
      const el = document.getElementById('fg-abs-date');
      if (!el || !S.absence) return;
      const iso = isoFromJalaliInput(el.value);
      if (iso && iso !== S.absence.date) loadAbsence(S.absence.group_id, iso);
    }, 0);
  }, true);

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-fg]');
    if (!b) return;
    e.preventDefault();
    const act = b.dataset.fg;
    const id = Number(b.dataset.id || 0);
    if (act === 'reload') { S.error = ''; S.loaded = false; draw(); load(); return; }
    if (act === 'new') { S.filter = ''; S.editing = { id: 0, title: '', l_uid: '', description: '', active: true, members: [] }; draw(); return; }
    if (act === 'edit') {
      const g = S.groups.find((x) => x.id === id);
      if (g) { S.filter = ''; S.editing = { id: g.id, title: g.title, l_uid: g.l_uid, description: g.description, active: g.active, members: g.members.map((m) => m.id) }; S.absence = null; draw(); }
      return;
    }
    if (act === 'cancel') { S.editing = null; draw(); return; }
    if (act === 'delete') {
      const g = S.groups.find((x) => x.id === id);
      if (!g || !window.confirm('گروه «' + g.title + '» حذف شود؟ اعضای آن آزاد می‌شوند.')) return;
      callApi('/api/food-groups', { method: 'POST', body: JSON.stringify({ action: 'delete', id }) })
        .then((x) => { apply(x); say('گروه حذف شد.'); draw(); }).catch((err) => say(err.message || 'حذف انجام نشد.'));
      return;
    }
    if (act === 'save') {
      const payload = collect();
      // اعضای فیلترشده (نامرئی در لیست) هم حفظ شوند
      const visible = new Set(Array.from(document.querySelectorAll('[data-fg-member]')).map((c) => Number(c.value)));
      ((S.editing && S.editing.members) || []).forEach((m) => { if (!visible.has(Number(m)) && payload.members.indexOf(Number(m)) < 0) payload.members.push(Number(m)); });
      if (!payload.title.trim()) { say('نام گروه را وارد کنید.'); return; }
      if (!payload.l_uid.trim()) { say('L_UID نمایندهٔ گروه را وارد کنید (شناسهٔ کارت در این سامانه نقشی ندارد).'); return; }
      callApi('/api/food-groups', { method: 'POST', body: JSON.stringify(payload) })
        .then((x) => { apply(x); S.editing = null; say('گروه ذخیره شد.'); draw(); }).catch((err) => say(err.message || 'ذخیره انجام نشد.'));
    }
    if (act === 'absence') { loadAbsence(id, (S.absence && S.absence.date) || isoToday()); return; }
    if (act === 'abs-today') { if (S.absence) loadAbsence(S.absence.group_id, isoToday()); return; }
    if (act === 'abs-retry') { if (S.absence) loadAbsence(S.absence.group_id, S.absence.date || isoToday()); return; }
    if (act === 'abs-close') { S.absence = null; draw(); return; }
    if (act === 'abs-save') {
      if (!S.absence) return;
      const absent = [];
      const present = [];
      (S.absence.members || []).forEach((m) => {
        const el = document.querySelector('[data-fg-absent][value="' + m.id + '"]');
        if (!el) return;
        const checked = !!el.checked;
        if (checked && !m.absent) absent.push(m.id);
        if (!checked && m.absent) present.push(m.id);
      });
      const reason = (document.getElementById('fg-abs-reason') || {}).value || '';
      const dateEl = document.getElementById('fg-abs-date');
      const dateIso = dateEl ? isoFromJalaliInput(dateEl.value) : S.absence.date;
      if (dateEl && !dateIso) { say('تاریخ شمسی معتبر نیست؛ نمونهٔ درست: ۱۴۰۵/۰۷/۱۴'); return; }
      if (dateIso && dateIso !== S.absence.date) {
        say('تاریخ تغییر کرد؛ وضعیت همان تاریخ بارگذاری شد. دوباره «ذخیرهٔ غیبت این تاریخ» را بزنید.');
        loadAbsence(S.absence.group_id, dateIso);
        return;
      }
      if ((absent.length || present.length) === 0) { say('تغییری برای ذخیره وجود ندارد.'); return; }
      if (S.absence.locked && !S.absence.can_override) { say('این تاریخ قفل شده است؛ اصلاح با دسترسی سطح بالاتر انجام می‌شود.'); return; }
      if (S.absence.locked && !reason.trim()) { say('برای اصلاح بعد از قفل، دلیل را وارد کنید.'); return; }
      callApi('/api/absence-save', { method: 'POST', body: JSON.stringify({ group_id: S.absence.group_id, date: S.absence.date, absent, present, reason }) })
        .then((x) => {
          S.absence = mergeAbsence(S.absence, x, S.absence.group_id);
          S.absenceError = '';
          var msg = 'غیبت ذخیره شد (' + absent.length + ' غایب، ' + present.length + ' حاضر).';
          if (x && Number(x.tickets_held || 0) > 0) msg += ' — ' + Number(x.tickets_held) + ' فیش چاپ‌نشده متوقف شد.';
          if (x && Number(x.tickets_released || 0) > 0) msg += ' — ' + Number(x.tickets_released) + ' فیش به صف چاپ برگشت.';
          say(msg);
          draw();
          load(); // به‌روزرسانی ستون «غایب امروز» در لیست گروه‌ها
        })
        .catch((err) => say(err.message || 'ذخیره غیبت انجام نشد.'));
    }
    if (act === 'abs-clear') {
      if (!S.absence) return;
      const reason = (document.getElementById('fg-abs-reason') || {}).value || '';
      const clearDateEl = document.getElementById('fg-abs-date');
      const clearIso = clearDateEl ? isoFromJalaliInput(clearDateEl.value) : S.absence.date;
      if (clearDateEl && !clearIso) { say('تاریخ شمسی معتبر نیست؛ نمونهٔ درست: ۱۴۰۵/۰۷/۱۴'); return; }
      if (clearIso && clearIso !== S.absence.date) {
        say('تاریخ تغییر کرد؛ وضعیت همان تاریخ بارگذاری شد؛ دوباره «همه حاضر» را بزنید.');
        loadAbsence(S.absence.group_id, clearIso);
        return;
      }
      if (!window.confirm('همهٔ اعضای این گروه برای این تاریخ «حاضر» شوند؟')) return;
      callApi('/api/absence-clear', { method: 'POST', body: JSON.stringify({ group_id: S.absence.group_id, date: S.absence.date, reason }) })
        .then((x) => { S.absence = mergeAbsence(S.absence, x, S.absence.group_id); say('همهٔ اعضا حاضر شدند.'); draw(); load(); })
        .catch((err) => say(err.message || 'عملیات انجام نشد.'));
    }
  });

  window.FoodGroupsUI = {
    html,
    mount() {
      if (!window.FOOD_TICKET_API_BASE) return; // فقط در پنل یکپارچهٔ PHP
      if (!S.loaded && !S.loading) load();
    },
  };
})();
