/**
 * آزمون رفتاری پنل غذا — فرم چاپگر + کالیبراسیون + ایمپورت کارکنان (نسخهٔ ۱.۳۷.۴)
 * ============================================================================
 *   node tools/test_panel_printer_node.js
 *
 * سه ایرادی که کاربر گزارش کرد، این‌جا واقعاً بازسازی و بررسی می‌شوند:
 *   ۱) «بعد از ذخیره مقدار فیلد IP پاک می‌شود»
 *      → باید IP در فیلد بماند و در حالت صف ویندوز هم به سرور فرستاده شود.
 *   ۲) «صف ویندوز انتخاب می‌شود بعد از ذخیره برمی‌گردد به حالت شبکه و فیلد IP
 *      نشان داده نمی‌شود؛ باید چند بار سوییچ کنی» → سوئیچ باید زنده باشد.
 *   ۳) «انتخاب فعلی تیک نمی‌خورد» → رادیوی چاپگر انتخاب‌شده تیک می‌خورد.
 *
 * نیازمند jsdom:  npm install jsdom   (وگرنه SKIP می‌شود)
 */
'use strict';

const fs = require('fs');
const path = require('path');

let JSDOM = null;
for (const candidate of ['jsdom', path.join('/tmp/jstest/node_modules', 'jsdom')]) {
  try {
    JSDOM = require(candidate).JSDOM;
    break;
  } catch (e) { /* مسیر بعدی */ }
}
if (!JSDOM) {
  console.log('[SKIP] jsdom نصب نیست؛ این آزمون رد شد.');
  process.exit(0);
}

const panelPath = path.join(__dirname, '..', 'food-ticket-web', 'index.html');
const html = fs.readFileSync(panelPath, 'utf8');

let pass = 0;
let fail = 0;
function check(label, ok, detail) {
  if (ok) {
    pass++;
    console.log('[PASS] ' + label);
  } else {
    fail++;
    console.log('[FAIL] ' + label + (detail !== undefined ? ' — ' + detail : ''));
  }
}

// ── ۱.۳۷.۴: سرورِ آزمون «حقیقتِ ذخیره‌شده» را نگه می‌دارد ──
// علت ریشه‌ای ایراد کاربر این بود که پنل پس از ذخیره از پاسخ سرور رندر می‌کند؛ اگر پاسخ
// کلیدها را برنگرداند، فرم «صفر/پیش‌فرض» نشان می‌دهد. پس mock هم مثل سرور واقعی رفتار می‌کند.
const serverState = {
  printer: {
    mode: 'windows-spooler',
    mode_db: 'windows_share',
    host: '192.168.1.50',
    port: 9100,
    name: 'XP-80C',
    share: 'XP-80C',
    ready: true,
    target: 'XP-80C',
  },
};
const SAVED = serverState; // سازگاری با نام قبلی

/** تبدیل مقدار ذخیره‌شده به ساختار align_state سرور (همان کلیدهایی که پنل نشان می‌دهد). */
function alignStateFrom(cfg) {
  const dpm = 8.0;
  const lineDots = Number(cfg.line_dots != null ? cfg.line_dots : 30);
  const headOffset = Number(cfg.head_offset_mm != null ? cfg.head_offset_mm : 0);
  const tailMm = Number(cfg.tail_mm != null ? cfg.tail_mm : 3);
  const reverseLines = Math.round(headOffset * dpm / lineDots);
  return {
    active: headOffset > 0 && cfg.edge_align !== false,
    verdict: headOffset > 0 ? 'ready' : 'measure',
    head_offset_mm: headOffset,
    edge_align: cfg.edge_align !== false,
    tail_mm: tailMm,
    line_dots: lineDots,
    dots_per_mm: dpm,
    reverse_feed_cmd: cfg.reverse_feed_cmd || 'esc_e',
    reverse_lines: reverseLines,
    reverse_arg: cfg.reverse_feed_cmd === 'esc_k' ? reverseLines * lineDots : reverseLines,
    reverse_unit: cfg.reverse_feed_cmd === 'esc_k' ? 'dots' : 'lines',
    reverse_dots: reverseLines * lineDots,
    reverse_mm: Math.round(reverseLines * lineDots / dpm * 10) / 10,
    cut_feed_dots: Math.round(Math.max(0, headOffset - tailMm) * dpm),
    cut_feed_mm: Math.round(Math.max(0, headOffset - tailMm) * 10) / 10,
    cut_mode: cfg.cut_mode || 'partial',
    win_top_shift_mm: Number(cfg.win_top_shift_mm != null ? cfg.win_top_shift_mm : 0),
    top_gap_mm: Number(cfg.top_gap_mm != null ? cfg.top_gap_mm : 4),
    feed_lines: Number(cfg.feed_lines != null ? cfg.feed_lines : 2),
    threshold: Number(cfg.threshold != null ? cfg.threshold : 170),
    chunk_rows: 128,
    head_dots: 576,
    fit_bottom: true,
    cut: true,
    log_align: true,
    save_preview: false,
    io_timeout: 10,
    hint: 'تراز دقیق لبه‌ها فعال است.',
  };
}
const serverAlign = alignStateFrom({ head_offset_mm: 20, tail_mm: 3, line_dots: 30, cut_mode: 'partial', win_top_shift_mm: 0, top_gap_mm: 4, feed_lines: 2, threshold: 170 });

const calls = [];
let postBodies = [];      // بدنهٔ POST های /api/config
let allPosts = [];        // هر POST: {url, body}
const uploads = [];       // آپلودهای multipart: {url, csrf, hasFile, fileName, update}
let failNextUpload = false; // برای آزمون نمایش خطای سرور

function response(obj) {
  return Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve(obj),
    text: () => Promise.resolve(JSON.stringify(obj)),
  });
}

const { VirtualConsole } = require('/tmp/jstest/node_modules/jsdom');
const virtualConsole = new VirtualConsole();
const pageErrors = [];
virtualConsole.on('jsdomError', (e) => { pageErrors.push(e.message); });
virtualConsole.on('error', (...a) => { pageErrors.push('ERR ' + a.join(' ')); });
virtualConsole.on('warn', (...a) => { pageErrors.push('WARN ' + a.join(' ')); });
virtualConsole.on('log', (...a) => { pageErrors.push('LOG ' + a.join(' ')); });

const dom = new JSDOM(html, {
  virtualConsole: virtualConsole,
  runScripts: 'dangerously',
  url: 'http://localhost/food-ticket-web/',
  pretendToBeVisual: true,
  beforeParse(window) {
    // پنل در حالت «یکپارچه» از sessionStorage اجازهٔ ورود می‌گیرد؛ همان چیزی که پس از
    // ورود واقعی مدیر در مرورگر وجود دارد.
    try {
      window.FOOD_TICKET_ROLE = 'primary_admin';
      window.FOOD_TICKET_CSRF = 'csrf-test-token';
      window.sessionStorage.setItem('food-auth', '1');
      window.sessionStorage.setItem('food-role', 'primary_admin');
    } catch (e) { /* بی‌اهمیت */ }
    window.fetch = function (url, options) {
      const target = String(url);
      const method = (options && options.method) || 'GET';
      calls.push(method + ' ' + target);
      if (method === 'POST') {
        try {
          allPosts.push({ url: target, body: JSON.parse((options && options.body) || '{}') });
        } catch (e) {
          allPosts.push({ url: target, body: {} });
        }
      }
      if (target.indexOf('/api/config') !== -1) {
        if (method === 'POST') {
          postBodies.push(JSON.parse((options && options.body) || '{}'));
          const body = postBodies[postBodies.length - 1];
          const pr = body.printer || {};
          // سرور واقعی: مقادیر را در دیتابیس می‌نویسد و همان حقیقت را برمی‌گرداند.
          // نکتهٔ مهم (۱.۳۷.۴): در حالت «صف ویندوز» هم host/port باید حفظ شوند.
          serverState.printer = Object.assign({}, serverState.printer, {
            mode: pr.mode === 'windows_share' ? 'windows-spooler' : 'socket',
            mode_db: pr.mode || serverState.printer.mode_db || 'tcp_raw',
            host: pr.host !== undefined && pr.host !== '' ? pr.host : serverState.printer.host,
            port: Number(pr.port || serverState.printer.port || 9100),
            name: pr.name !== undefined && pr.name !== '' ? pr.name : serverState.printer.name,
            share: pr.name !== undefined && pr.name !== '' ? pr.name : serverState.printer.share,
            ready: true,
          });
          serverState.printer.target = serverState.printer.mode === 'windows-spooler' ? serverState.printer.name : serverState.printer.host;
          return response({ ok: true, printer: serverState.printer });
        }
        return response(Object.assign({}, serverState, {
          panelVersion: window.FOOD_TICKET_PANEL_VERSION_FROM_SERVER || '1.37.6',
          attendancePath: '\\\\\\\\server\\\\attendance.mdb',
          ordersPath: '\\\\\\\\server\\\\orders.mdb',
          pollSeconds: 2,
          enabled: true,
          db: { token: 't', fresh: false, counts: {} },
        }));
      }
      if (target.indexOf('/api/employees-import') !== -1) {
        const headers = (options && options.headers) || {};
        const fd = (options && options.body) && typeof options.body.get === 'function' ? options.body : null;
        uploads.push({
          url: target,
          csrf: headers['X-CSRF-Token'] || headers['x-csrf-token'] || '',
          hasFile: !!(fd && fd.get('file')),
          fileName: fd && fd.get('file') ? fd.get('file').name : '',
          update: fd ? fd.get('update') : '',
        });
        if (failNextUpload) {
          failNextUpload = false;
          return Promise.resolve({
            ok: false,
            status: 500,
            json: () => Promise.resolve({ ok: false, error: 'نوشتن فایل تنظیمات چاپ ناموفق بود.' }),
            text: () => Promise.resolve(JSON.stringify({ ok: false, error: 'نوشتن فایل تنظیمات چاپ ناموفق بود.' })),
          });
        }
        return response({
          ok: true,
          items: [{ id: 1, employee_number: '1001', full_name: 'علی رضایی', active: true }],
          file: 'نمونه-لیست-کارکنان.xlsx',
          format: 'xlsx',
          total: 3,
          created: 2,
          updated: 1,
          skipped: 0,
          duplicates: 1,
          truncated: false,
          message: 'فایل نمونه-لیست-کارکنان.xlsx (اکسل) خوانده شد: 3 ردیف. 2 کارمند تازه اضافه شد، 1 رکورد به‌روزرسانی شد، 1 ردیف تکراری داخل فایل بود.',
          rows: [
            { row: 2, pc: '1001', name: 'علی رضایی', status: 'created', messages: [] },
            { row: 3, pc: '1002', name: 'مریم حسینی', status: 'updated', messages: [] },
            { row: 4, pc: '1001', name: 'تکراری', status: 'duplicate', messages: ['این کد پرسنلی در همین فایل تکرار شده است (ردیف 2).'] },
          ],
        });
      }
      if (target.indexOf('/api/printers') !== -1) {
        return response({
          items: ['XP-80C', 'Microsoft Print to PDF'],
          installed: ['XP-80C', 'Microsoft Print to PDF'],
          mode: 'windows-spooler',
          selected: 'XP-80C',
          tcp: '192.168.1.50:9100',
          host: '192.168.1.50',
          port: 9100,
          share: 'XP-80C',
          hint: 'چاپگرهای نصب‌شده روی سرور Windows',
        });
      }
      if (target.indexOf('/api/print-align') !== -1) {
        if (method === 'POST') {
          let body = {};
          try { body = JSON.parse((options && options.body) || '{}'); } catch (e) { body = {}; }
          // سرور واقعی: فقط کلیدهای مجاز را ذخیره می‌کند و سپس وضعیت را از فایل می‌خواند.
          const allowed = ['head_offset_mm', 'edge_align', 'tail_mm', 'line_dots', 'reverse_feed_cmd',
            'cut_mode', 'top_gap_mm', 'fit_bottom', 'feed_lines', 'threshold', 'win_top_shift_mm', 'dots_per_mm'];
          allowed.forEach((k) => { if (Object.prototype.hasOwnProperty.call(body, k)) { serverAlign[k] = body[k]; } });
          Object.assign(serverAlign, alignStateFrom(serverAlign));
          serverAlign.saved_keys = Object.keys(body);
          return response({ ok: true, message: 'ذخیره شد. ' + serverAlign.hint, align: serverAlign });
        }
        return response({ ok: true, align: serverAlign });
      }
      if (target.indexOf('/api/print-diag') !== -1) {
        return response({
          ok: true,
          printer: Object.assign({ calibration_host: '192.168.1.50', calibration_source: 'food_ticket_config' }, serverState.printer),
          reachable: null,
          align: { active: true, verdict: 'ready', head_offset_mm: 20, tail_mm: 3, line_dots: 30, reverse_feed_cmd: 'esc_e', reverse_lines: 5, reverse_mm: 15, reverse_dots: 150, cut_feed_dots: 136, cut_feed_mm: 17, edge_align: true },
          netprint_file: { path: '/storage/food_ticket_netprint.json', exists: true, writable: true },
        });
      }
      return response({ ok: true, items: [] });
    };
    window.alert = function () {};
  },
});

const window = dom.window;
const doc = window.document;

function nextTick(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms === undefined ? 120 : ms));
}

/** تا وقتی شرط درست شود (یا مهلت تمام شود) صبر می‌کند — بوت پنل async است. */
async function waitFor(predicate, timeout) {
  const deadline = Date.now() + (timeout === undefined ? 6000 : timeout);
  while (Date.now() < deadline) {
    if (predicate()) return true;
    await nextTick(80);
  }
  return predicate();
}

(async function run() {
  await waitFor(() => !!doc.querySelector('[data-view="printer"]'), 6000);

  const navButton = doc.querySelector('[data-view="settings"]');
  check('۱: صفحهٔ «تنظیمات سیستم» برای ادمین اصلی در پنل هست', !!navButton,
    pageErrors.slice(0, 3).join(' | '));
  if (navButton) {
    navButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await nextTick(200);
  }
  const printerTab = doc.querySelector('[data-settings-section="printer"]');
  check('۱-ب: زبانهٔ «چاپگر سیستم» در تنظیمات هست', !!printerTab);
  if (printerTab) {
    printerTab.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await waitFor(() => !!doc.querySelector('#printer-form'), 4000);
  }

  const form = doc.querySelector('#printer-form');
  check('۲: فرم تنظیمات چاپگر رندر شد', !!form);
  if (!form) { finish(); return; }

  const modeSelect = form.querySelector('select[name="mode"]');
  const hostInput = form.querySelector('input[name="host"]');
  const portInput = form.querySelector('input[name="port"]');
  const radios = form.querySelectorAll('input[name="printerName"]');
  const checked = form.querySelector('input[name="printerName"]:checked');

  check('۳: روش چاپ ذخیره‌شده روی سرور («صف ویندوز») در فرم انتخاب شده است',
    modeSelect && modeSelect.value === 'windows-spooler', modeSelect && modeSelect.value);
  check('۴: فیلد IP با مقدار ذخیره‌شدهٔ سرور پر است (ایراد «IP پاک می‌شود»)',
    hostInput && hostInput.value === '192.168.1.50', hostInput && hostInput.value);
  check('۵: پورت هم از سرور پر شده است', portInput && portInput.value === '9100', portInput && portInput.value);
  check('۶: چاپگر فعلی در لیست تیک خورده است (ایراد «انتخاب فعلی تیک نمی‌خورد»)',
    !!checked && checked.value === 'XP-80C', checked && checked.value);
  check('۷: کنار چاپگر فعلی نشان «✓ انتخاب فعلی» دیده می‌شود',
    doc.querySelectorAll('.printer-item-current .printer-mark').length === 1,
    String(doc.querySelectorAll('.printer-item-current').length));

  const winLabel = form.querySelector('.win-only');
  const tcpLabels = form.querySelectorAll('.tcp-only');
  check('۸: در حالت صف ویندوز، فیلد IP پنهان و لیست چاپگرها پیدا است',
    tcpLabels[0].style.display === 'none' && winLabel.style.display !== 'none',
    tcpLabels[0].style.display + ' / ' + winLabel.style.display);

  // سوئیچ زنده به حالت شبکه: باید همان لحظه فیلد IP ظاهر شود و مقدارش بماند
  modeSelect.value = 'socket';
  modeSelect.dispatchEvent(new window.Event('change', { bubbles: true }));
  await nextTick(60);
  check('۹: با یک بار سوییچ به «شبکه»، فیلد IP فوراً ظاهر می‌شود (بدون چند بار سوییچ)',
    tcpLabels[0].style.display !== 'none' && winLabel.style.display === 'none',
    tcpLabels[0].style.display + ' / ' + winLabel.style.display);
  check('۱۰: مقدار IP هم‌زمان با سوییچ حفظ می‌شود', hostInput.value === '192.168.1.50', hostInput.value);

  // برگشتن به صف ویندوز و ذخیره: IP نباید پاک شود و باید به سرور هم برود
  modeSelect.value = 'windows-spooler';
  modeSelect.dispatchEvent(new window.Event('change', { bubbles: true }));
  await nextTick(60);
  postBodies = [];
  form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  await nextTick(300);
  const saved = postBodies[0] || {};
  check('۱۱: ذخیره در حالت صف ویندوز، IP را پاک نمی‌کند و همان را می‌فرستد',
    saved.printer && saved.printer.host === '192.168.1.50' && saved.printer.mode === 'windows_share',
    JSON.stringify(saved.printer || {}));
  check('۱۲: نام چاپگر انتخاب‌شده هم به سرور می‌رود',
    saved.printer && saved.printer.name === 'XP-80C', JSON.stringify(saved.printer || {}));
  check('۱۳: بعد از ذخیره، فیلد IP همچنان پر است',
    hostInput.value === '192.168.1.50', hostInput.value);

  // کادر «وضعیت واقعی روی سرور»
  const diagButton = doc.querySelector('[data-action="print-diag"]');
  check('۱۴: دکمهٔ «وضعیت واقعی روی سرور» در صفحهٔ چاپگر هست', !!diagButton);
  if (diagButton) {
    diagButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await nextTick(300);
    const box = doc.querySelector('.server-printer-card');
    check('۱۵: کادر وضعیت سرور با مقصد کارت کالیبراسیون نمایش داده می‌شود',
      !!box && box.textContent.indexOf('192.168.1.50') !== -1 && box.textContent.indexOf('آدرس ذخیره‌شده') !== -1);
  }

  // کادر تراز لبه‌ها: ذخیره باید کلیدهای لازم را بفرستد
  const alignForm = doc.querySelector('#align-form');
  check('۱۶: کادر «تراز دقیق لبه‌های کاغذ» با فرم ذخیره در صفحه هست', !!alignForm);
  if (alignForm) {
    alignForm.elements.head_offset_mm.value = '20';
    alignForm.elements.win_top_shift_mm.value = '-20';
    alignForm.elements.cut_mode.value = 'none';
    postBodies = [];
    allPosts = [];
    const saveAlign = doc.querySelector('[data-action="save-align"]');
    saveAlign.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await nextTick(300);
    const alignPost = calls.filter((c) => c.indexOf('POST') === 0 && c.indexOf('/api/print-align') !== -1);
    check('۱۷: ذخیرهٔ تراز به مسیر /api/print-align می‌رود', alignPost.length >= 1, calls.slice(-3).join(' | '));
    const alignBody = (allPosts.filter((x) => x.url.indexOf('/api/print-align') !== -1).pop() || {}).body || {};
    check('۱۸: حالت برش انتخاب‌شده هم به سرور می‌رود', alignBody.cut_mode === 'none', JSON.stringify(alignBody.cut_mode));
    check('۱۹: جابه‌جایی حالت صف ویندوز هم فرستاده می‌شود', String(alignBody.win_top_shift_mm) === '-20', String(alignBody.win_top_shift_mm));
  }

  /* ═══════════════ ۱.۳۷.۴ — بررسی ایرادهای باقی‌ماندهٔ کاربر ═══════════════ */

  // ۲۰) کادر کالیبراسیون باید «ساده ولی کارآمد» باشد: سه گام اصلی در دید، بقیه در جزئیات بسته.
  const alignForm2 = doc.querySelector('#align-form');
  const advBox = doc.querySelector('.align-advanced');
  check('۲۰: کادر کالیبراسیون فقط سه گام اصلی را نشان می‌دهد و «تنظیمات پیشرفته» بسته است',
    !!alignForm2 && ['head_offset_mm', 'cut_mode', 'win_top_shift_mm'].every((n) => alignForm2.elements[n])
      && !!advBox && advBox.open === false && advBox.querySelectorAll('input,select').length >= 4,
    'details.open=' + (advBox && advBox.open) + ' / advFields=' + (advBox ? advBox.querySelectorAll('input,select').length : -1));

  // ۲۱) ایراد کاربر: «در حالت صف ویندوز عدد جابه‌جایی بالای فیش پس از ذخیره صفر می‌شود»
  check('۲۱: بعد از ذخیره، «جابه‌جایی بالای فیش» همان ‎-20 می‌ماند (صفر نمی‌شود)',
    !!alignForm2 && String(alignForm2.elements.win_top_shift_mm.value) === '-20',
    alignForm2 ? alignForm2.elements.win_top_shift_mm.value : 'no form');
  check('۲۱-ب: در «وضعیت فعلی» هم همان عدد دیده می‌شود',
    doc.querySelector('.align-facts') && doc.querySelector('.align-facts').textContent.indexOf('-20') !== -1);
  check('۲۱-ج: حالت برش انتخاب‌شده («بدون برش») بعد از ذخیره باقی می‌ماند',
    !!alignForm2 && alignForm2.elements.cut_mode.value === 'none',
    alignForm2 ? alignForm2.elements.cut_mode.value : 'no form');
  check('۲۱-د: سرور هم همان مقدار را برمی‌گرداند (منبع حقیقت، نه حدس پنل)',
    Number(serverAlign.win_top_shift_mm) === -20 && serverAlign.cut_mode === 'none',
    JSON.stringify({ win: serverAlign.win_top_shift_mm, cut: serverAlign.cut_mode }));

  // ۲۲) اعداد «تنظیمات پیشرفته» هم باید ذخیره و بازخوانی شوند (کالیبراسیون کم‌جزئیات ولی کارآمد)
  if (alignForm2) {
    advBox.open = true;
    alignForm2.elements.top_gap_mm.value = '1';
    alignForm2.elements.feed_lines.value = '1';
    alignForm2.elements.threshold.value = '180';
    doc.querySelector('[data-action="save-align"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await nextTick(300);
  }
  const alignForm3 = doc.querySelector('#align-form');
  check('۲۲: اعداد پیشرفته (سفیدی ۱ / تغذیه ۱ / آستانه ۱۸۰) به سرور رفت',
    Number(serverAlign.top_gap_mm) === 1 && Number(serverAlign.feed_lines) === 1 && Number(serverAlign.threshold) === 180,
    JSON.stringify({ gap: serverAlign.top_gap_mm, feed: serverAlign.feed_lines, th: serverAlign.threshold }));
  check('۲۲-ب: و بعد از ذخیرهٔ دوباره هم در فرم همان اعداد می‌مانند',
    !!alignForm3 && alignForm3.elements.top_gap_mm.value === '1' && alignForm3.elements.feed_lines.value === '1'
      && alignForm3.elements.threshold.value === '180',
    alignForm3 ? [alignForm3.elements.top_gap_mm.value, alignForm3.elements.feed_lines.value, alignForm3.elements.threshold.value].join('/') : 'no form');
  check('۲۲-ج: «جابه‌جایی ویندوز» بعد از دو ذخیرهٔ پشت‌سرهم هم ‎-20 مانده',
    !!alignForm3 && String(alignForm3.elements.win_top_shift_mm.value) === '-20',
    alignForm3 ? alignForm3.elements.win_top_shift_mm.value : 'no form');

  // ۲۳) روش چاپ پس از ذخیرهٔ دوباره: نه برمی‌گردد به شبکه، نه فیلدهایش پنهان می‌شوند.
  const form3 = doc.querySelector('#printer-form');
  if (form3) {
    form3.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    await nextTick(300);
  }
  const form4 = doc.querySelector('#printer-form');
  const modeSelect4 = form4 && form4.querySelector('select[name="mode"]');
  const hostInput4 = form4 && form4.querySelector('input[name="host"]');
  check('۲۳: پس از ذخیرهٔ دوباره، روش چاپ «صف ویندوز» در فرم می‌ماند',
    !!modeSelect4 && modeSelect4.value === 'windows-spooler', modeSelect4 ? modeSelect4.value : 'no form');
  check('۲۳-ب: بلوک فیلدهای همان روش همان لحظه دیده می‌شود (بدون سوییچ‌های مکرر)',
    !!form4 && form4.querySelector('.win-only').style.display !== 'none'
      && form4.querySelectorAll('.tcp-only')[0].style.display === 'none');
  check('۲۳-ج: IP و نام چاپگر بعد از ذخیره‌های مکرر هم سرِ جای خود هستند',
    !!hostInput4 && hostInput4.value === '192.168.1.50' && serverState.printer.name === 'XP-80C',
    hostInput4 ? hostInput4.value : 'no form');

  // ۲۴) نشان نسخهٔ پنل + بنر «فایل کش‌شده» (تشخیص علت دیده‌نشدن اصلاحات)
  const pill = doc.querySelector('.top-meta .pill');
  check('۲۴: نشان نسخهٔ پنل «پنل 1.37.6» در نوار بالا دیده می‌شود',
    !!pill && pill.textContent.indexOf('1.37.6') !== -1, pill ? pill.textContent.trim() : 'no pill');
  window.__ftPanelExpected = '1.37.3';
  window.render();
  await nextTick(120);
  const banner = doc.querySelector('.version-warning');
  check('۲۴-ب: اگر سرور نسخهٔ قدیمی‌تری بفرستد، بنر هشدار کش با راهنمای Ctrl+F5 نشان داده می‌شود',
    !!banner && banner.textContent.indexOf('Ctrl+F5') !== -1);
  window.__ftPanelExpected = '1.37.6';
  window.render();
  await nextTick(120);
  check('۲۴-ج: با تطبیق نسخه‌ها بنر هشدار ناپدید می‌شود', !doc.querySelector('.version-warning'));

  // ۲۵) فرم کارکنان: دکمهٔ browse برای ایمپورت Excel
  const empNav = doc.querySelector('[data-view="employees"]');
  check('۲۵: زبانهٔ «کارکنان» در نوار بالا هست', !!empNav);
  if (empNav) {
    empNav.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await waitFor(() => !!doc.querySelector('[data-action="import-employees"]'), 5000);
  }
  const fileInput = doc.querySelector('[data-action="import-employees"]');
  check('۲۵-ب: دکمهٔ «بارگذاری از Excel» (browse) در فرم کارکنان هست',
    !!fileInput && fileInput.type === 'file', fileInput ? fileInput.type : 'not found');
  check('۲۵-ج: فایل‌های xlsx/csv پذیرفته می‌شوند (accept)',
    !!fileInput && String(fileInput.getAttribute('accept')).indexOf('.xlsx') !== -1,
    fileInput ? fileInput.getAttribute('accept') : '');
  check('۲۵-د: کادر راهنما، سرستون‌های نمونه را توضیح می‌دهد',
    !!doc.querySelector('.import-card') && doc.querySelector('.import-card').textContent.indexOf('کد پرسنلی') !== -1);

  // ۲۶) انتخاب فایل → آپلود با CSRF و نمایش نتیجه
  if (fileInput) {
    const sample = new window.File([new Uint8Array([80, 75, 3, 4])], 'نمونه-لیست-کارکنان.xlsx',
      { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    Object.defineProperty(fileInput, 'files', { value: [sample], configurable: true });
    fileInput.dispatchEvent(new window.Event('change', { bubbles: true }));
    await waitFor(() => uploads.length > 0, 5000);
    await nextTick(300);
  }
  const up = uploads[uploads.length - 1] || {};
  check('۲۶: فایل انتخابی به مسیر /api/employees-import فرستاده می‌شود',
    String(up.url || '').indexOf('/api/employees-import') !== -1, String(up.url || ''));
  check('۲۶-ب: فایل به‌صورت multipart با فیلد file می‌رود و نام فایل حفظ می‌شود',
    up.hasFile === true && up.fileName === 'نمونه-لیست-کارکنان.xlsx', JSON.stringify([up.hasFile, up.fileName]));
  check('۲۶-ج: توکن CSRF همراه درخواست است (وگرنه سرور ۴۰۳ می‌دهد)',
    up.csrf === 'csrf-test-token', String(up.csrf));
  check('۲۶-د: پرچم به‌روزرسانی رکوردهای موجود فرستاده می‌شود', String(up.update) === '1', String(up.update));
  const res = doc.querySelector('.import-result');
  check('۲۷: نتیجهٔ ایمپورت با آمار (ثبت/به‌روزرسانی/تکراری) در فرم نشان داده می‌شود',
    !!res && res.classList.contains('ok') && /ثبت تازه/.test(res.textContent)
      && res.textContent.indexOf('2') !== -1 && res.textContent.indexOf('1') !== -1,
    res ? res.textContent.slice(0, 120) : 'no result box');
  const dupRow = doc.querySelector('.import-rows .row-bad');
  check('۲۷-ب: ردیف تکراری داخل فایل با وضعیت «تکراری» و رنگ هشدار مشخص شده است',
    !!dupRow && dupRow.textContent.indexOf('تکراری') !== -1, dupRow ? dupRow.textContent.slice(0, 80) : 'no row');

  // ۲۸) خطای سرور باید خوانا نمایش داده شود (نه سکوت)
  failNextUpload = true;
  const fileInput2 = doc.querySelector('[data-action="import-employees"]');
  if (fileInput2) {
    const broken = new window.File([new Uint8Array([1, 2])], 'bad.xlsx', { type: 'application/vnd.ms-excel' });
    Object.defineProperty(fileInput2, 'files', { value: [broken], configurable: true });
    fileInput2.dispatchEvent(new window.Event('change', { bubbles: true }));
    await waitFor(() => doc.querySelector('.import-result.bad'), 5000);
    await nextTick(200);
  }
  const badBox = doc.querySelector('.import-result.bad');
  check('۲۸: خطای سرور در کادر قرمز و با متن خوانا نشان داده می‌شود',
    !!badBox && badBox.textContent.indexOf('ناموفق') !== -1, badBox ? badBox.textContent.slice(0, 100) : 'no error box');
  check('۲۸-ب: پس از خطا، فرم کارکنان دست‌نخورده و قابل استفاده می‌ماند (دکمهٔ browse هست)',
    !!doc.querySelector('[data-action="import-employees"]'));

  finish();
})();

function finish() {
  console.log('\n' + (fail === 0
    ? 'همهٔ ' + pass + ' بررسی پنل تنظیمات چاپگر و ایمپورت کارکنان (۱.۳۷.۴) موفق بود.\n'
    : fail + ' بررسی ناموفق از ' + (pass + fail) + ' مورد.\n'));
  process.exit(fail === 0 ? 0 : 1);
}
