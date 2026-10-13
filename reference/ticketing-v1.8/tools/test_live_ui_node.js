/**
 * آزمون رفتاریِ رابط کاربری — نسخهٔ ۱.۳۷
 * ============================================================================
 *   node tools/test_live_ui_node.js
 *
 * دو محور را واقعاً در یک DOM اجرا می‌کند (نه فقط بازرسی متن فایل):
 *   الف) «شناسنامه‌های فنی سیستم»: جست‌وجوی زنده بدون دکمهٔ «اعمال فیلتر»
 *        — با هر نویسه‌ای که تایپ شود فهرست همان لحظه فیلتر می‌شود، شمارنده به‌روز
 *          می‌شود، «انتخاب همه» فقط ردیف‌های دیده‌شده را انتخاب می‌کند.
 *   ب) فرم تیکت: کمبوی زندهٔ «سیستم مرتبط»
 *        — با تایپ، فهرست نتایج زیر فیلد باز می‌شود، با ↑/↓ و Enter یا کلیک یکی
 *          انتخاب می‌شود و مقدار select مخفی همان انتخاب را به فرم می‌دهد.
 *
 * نیازمند بستهٔ اختیاری jsdom است:  npm install jsdom
 * اگر jsdom نصب نباشد، آزمون با پیام SKIP رد می‌شود (بقیهٔ سوئیت‌ها مستقل‌اند).
 */
'use strict';
const fs = require('fs');
const path = require('path');

let JSDOM = null;
for (const candidate of ['jsdom', path.join('/tmp/jstest/node_modules', 'jsdom')]) {
  try {
    JSDOM = require(candidate).JSDOM;
    break;
  } catch (e) { /* امتحان مسیر بعدی */ }
}
if (!JSDOM) {
  console.log('[SKIP] jsdom نصب نیست؛ این آزمون رد شد (npm install jsdom و دوباره اجرا کنید).');
  process.exit(0);
}

const appJs = fs.readFileSync(path.join(__dirname, '..', 'assets', 'app.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '..', 'assets', 'style.css'), 'utf8');

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

/** اجرای app.js در یک صفحهٔ ساختگی و برگرداندن window پس از DOMContentLoaded. */
function boot(body) {
  const dom = new JSDOM('<!doctype html><html lang="fa"><body>' + body + '</body></html>', {
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  window.eval(appJs);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles: true }));
  return window;
}

function pressKey(window, node, key) {
  const event = new window.KeyboardEvent('keydown', { key: key, bubbles: true, cancelable: true });
  node.dispatchEvent(event);
  return event;
}

function rowVisible(row) {
  return row.hasAttribute('hidden') === false;
}

/* ───────────────────────── الف) صفحهٔ شناسنامه‌های فنی ───────────────────────── */
const assetsBody = `
<section class="inventory-summary"><div><strong>۲</strong><span>سیستم در این فهرست</span></div></section>
<form class="inventory-filter card" method="get" data-asset-live-filter>
  <label class="inventory-search">جست‌وجوی نام، شناسه، سریال یا IP
    <input name="q" id="asset-live-q" value="" placeholder="…" autocomplete="off" data-asset-live-q>
  </label>
  <label>وضعیت شبکه
    <select name="network" id="asset-live-network" data-asset-live-network>
      <option value="all" selected>همه وضعیت‌ها</option>
      <option value="online">آنلاین</option>
      <option value="offline">آفلاین</option>
      <option value="unknown">بررسی‌نشده</option>
    </select>
  </label>
  <span class="muted" data-asset-live-count></span>
</form>
<form id="assetSelectionForm" method="post">
  <div class="table-head"><span><input type="checkbox" data-select-all="asset_ids[]"></span><span>سیستم</span></div>
  <div class="table-row" data-asset-row="7" data-asset-search="PC-023 T-1001 SN-ABC 192.168.1.23 معاونت اداری" data-asset-net="online">
    <span><input type="checkbox" name="asset_ids[]" value="7"></span><span class="ticket-title"><b>T-1001</b><strong>PC-023</strong></span>
  </div>
  <div class="table-row" data-asset-row="8" data-asset-search="PC-099 T-1002  " data-asset-net="unknown">
    <span><input type="checkbox" name="asset_ids[]" value="8"></span><span class="ticket-title"><b>T-1002</b><strong>PC-099</strong></span>
  </div>
  <div class="inventory-empty" data-asset-live-empty hidden><strong>سیستمی با این جست‌وجو پیدا نشد.</strong></div>
</form>`;

const assetsWindow = boot(assetsBody);
const ad = assetsWindow.document;
const rows = Array.prototype.slice.call(ad.querySelectorAll('[data-asset-row]'));
const liveInput = ad.querySelector('[data-asset-live-q]');
const liveNetwork = ad.querySelector('[data-asset-live-network]');
const liveCount = ad.querySelector('[data-asset-live-count]');
const liveEmpty = ad.querySelector('[data-asset-live-empty]');
const selectAll = ad.querySelector('[data-select-all]');
const boxes = Array.prototype.slice.call(ad.querySelectorAll('input[name="asset_ids[]"]'));

check('الف-۱: در فرم فیلتر هیچ دکمهٔ «اعمال فیلتر» وجود ندارد', ad.querySelector('[data-asset-live-filter] button') === null);
check('الف-۲: هر دو ردیف در ابتدا دیده می‌شوند', rows.every(rowVisible));
check('الف-۳: شمارندهٔ اولیه درست است', liveCount.textContent.indexOf('۲ سیستم') !== -1, liveCount.textContent);

liveInput.value = 'pc-09۹';                       // حروف کوچک + رقم فارسی
liveInput.dispatchEvent(new assetsWindow.Event('input', { bubbles: true }));
check('الف-۴: جست‌وجوی «pc-09۹» فقط ردیف PC-099 را نشان می‌دهد', !rowVisible(rows[0]) && rowVisible(rows[1]),
  rows.map(rowVisible).join(','));
check('الف-۵: شمارنده «نمایش ۱ از ۲» می‌شود', liveCount.textContent.indexOf('نمایش ۱ از ۲') !== -1, liveCount.textContent);
check('الف-۶: پیام «پیدا نشد» پنهان می‌ماند', liveEmpty.hidden === true);

liveInput.value = '192.168.1.23';
liveInput.dispatchEvent(new assetsWindow.Event('input', { bubbles: true }));
check('الف-۷: جست‌وجو روی IP (فیلد نامرئی جدول) هم کار می‌کند', rowVisible(rows[0]) && !rowVisible(rows[1]));

liveInput.value = '';
liveInput.dispatchEvent(new assetsWindow.Event('input', { bubbles: true }));
liveNetwork.value = 'online';
liveNetwork.dispatchEvent(new assetsWindow.Event('change', { bubbles: true }));
check('الف-۸: فیلتر «وضعیت شبکه = آنلاین» بدون دکمه و بی‌درنگ اعمال می‌شود', rowVisible(rows[0]) && !rowVisible(rows[1]));

// «انتخاب همه» فقط باید ردیف‌های دیده‌شده را انتخاب کند
selectAll.checked = true;
selectAll.dispatchEvent(new assetsWindow.Event('change', { bubbles: true }));
check('الف-۹: «انتخاب همه» فقط ردیف دیده‌شده را تیک می‌زند', boxes[0].checked === true && boxes[1].checked === false,
  boxes.map(function (b) { return b.checked; }).join(','));

liveNetwork.value = 'all';
liveNetwork.dispatchEvent(new assetsWindow.Event('change', { bubbles: true }));
selectAll.checked = true;
selectAll.dispatchEvent(new assetsWindow.Event('change', { bubbles: true }));
check('الف-۱۰: با فیلتر «همه»، انتخاب همه هر دو ردیف را تیک می‌زند', boxes[0].checked && boxes[1].checked);

liveInput.value = 'چیزی که وجود ندارد';
liveInput.dispatchEvent(new assetsWindow.Event('input', { bubbles: true }));
check('الف-۱۱: نتیجهٔ خالی → پیام «سیستمی با این جست‌وجو پیدا نشد»', liveEmpty.hidden === false && rows.every(function (row) { return !rowVisible(row); }));

pressKey(assetsWindow, liveInput, 'Escape');
check('الف-۱۲: کلید Escape جست‌وجو را پاک می‌کند و فهرست برمی‌گردد', liveInput.value === '' && rows.every(rowVisible), liveInput.value);

check('الف-۱۳: CSS ردیف‌های مخفی‌شده را واقعاً پنهان می‌کند',
  css.indexOf('.table-row[hidden]') !== -1 && css.indexOf('.asset-results') !== -1);

/* ───────────────────────── ب) کمبوی زندهٔ «سیستم مرتبط» ───────────────────────── */
const ticketBody = `
<form id="ticketForm" method="post">
  <label>خدمت
    <select id="service-selector" name="service_id">
      <option value="">انتخاب کنید…</option>
      <option value="5" data-service-group="it" data-category-id="1" data-requires-asset="1" selected>نصب نرم‌افزار</option>
      <option value="6" data-service-group="it" data-category-id="1" data-requires-asset="0">مشاوره</option>
      <option value="7" data-service-group="support" data-category-id="2" data-requires-asset="0">چای و پذیرایی</option>
    </select>
  </label>
  <label class="ticket-asset-field" data-asset-combo data-asset-required-group="it">سیستم مرتبط
    <input type="text" id="asset-search" class="asset-search" placeholder="…" autocomplete="off"
           role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="asset-results">
    <select name="asset_id" id="asset-selector" class="asset-native" tabindex="-1" aria-hidden="true">
      <option value="">بدون سیستم خاص / انتخاب نشده</option>
      <option value="7" selected>PC-023 - T-1001 (سیستم فعلی)</option>
      <option value="8">PC-099 - T-1002</option>
      <option value="9">PC-101 - T-1003</option>
    </select>
    <div class="asset-results" id="asset-results" role="listbox" hidden></div>
    <small class="field-help">…</small>
    <small class="field-error" data-asset-error hidden></small>
  </label>
  <label>حوزه خدمت
    <label><input type="radio" name="service_group" value="it" checked> خدمات کامپیوتری</label>
    <label><input type="radio" name="service_group" value="support"> پشتیبانی</label>
  </label>
</form>`;

const ticketWindow = boot(ticketBody);
const td = ticketWindow.document;
const search = td.querySelector('#asset-search');
const hiddenSelect = td.querySelector('#asset-selector');
const results = td.querySelector('#asset-results');

check('ب-۱: سیستم خودکار (سیستم فعلی) در فیلد نمایش داده می‌شود', search.value === 'PC-023 - T-1001', search.value);
check('ب-۲: تا وقتی کاربر تایپ نکرده، انتخاب خودکار دست‌نخورده است', hiddenSelect.value === '7');
check('ب-۳: فهرست نتایج در آغاز بسته است', results.hidden === true);

search.value = 'pc-09';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
const items = Array.prototype.slice.call(results.querySelectorAll('[data-combo-index]'));
check('ب-۴: با تایپ، فهرست نتایج همان لحظه زیر فیلد باز می‌شود', results.hidden === false);
check('ب-۵: فقط نتیجهٔ منطبق با «pc-09» نشان داده می‌شود، به‌همراه ردیف «حذف انتخاب»',
  items.length === 2 && items[0].textContent.indexOf('بدون سیستم خاص') !== -1 && items[1].textContent.indexOf('PC-099') !== -1,
  items.map(function (n) { return n.textContent; }).join(' | '));
check('ب-۶: با ویرایش متن، انتخاب قبلی باطل می‌شود', hiddenSelect.value === '');

pressKey(ticketWindow, search, 'ArrowDown');
pressKey(ticketWindow, search, 'ArrowDown');
const enterEvent = pressKey(ticketWindow, search, 'Enter');
check('ب-۷: Enter با ↑/↓ یکی را انتخاب می‌کند و از ارسال فرم جلوگیری می‌شود', enterEvent.defaultPrevented === true);
check('ب-۸: مقدار انتخاب‌شده در select مخفی نشست (فرم همین را می‌فرستد)', hiddenSelect.value === '8', hiddenSelect.value);
check('ب-۹: متن انتخاب‌شده در فیلد می‌ماند و فهرست بسته می‌شود',
  search.value === 'PC-099 - T-1002' && results.hidden === true, search.value);

// انتخاب با کلیک
search.value = 't-1003';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
const clickTarget = results.querySelectorAll('[data-combo-index]')[1];
clickTarget.dispatchEvent(new ticketWindow.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
clickTarget.dispatchEvent(new ticketWindow.MouseEvent('click', { bubbles: true }));
check('ب-۱۰: کلیک روی نتیجه، همان سیستم را انتخاب می‌کند', hiddenSelect.value === '9' && search.value === 'PC-101 - T-1003',
  hiddenSelect.value + ' / ' + search.value);

// پاک‌کردن انتخاب با ردیف «بدون سیستم خاص»
search.value = 'pc';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
const clearRow = results.querySelector('.asset-result-clear');
clearRow.dispatchEvent(new ticketWindow.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
clearRow.dispatchEvent(new ticketWindow.MouseEvent('click', { bubbles: true }));
check('ب-۱۱: ردیف «بدون سیستم خاص» انتخاب را پاک می‌کند', hiddenSelect.value === '' && search.value === '');

// متن تایپ‌شدهٔ بی‌نتیجه نباید انتخاب قدیمی را ثبت کند
search.value = 'PC-099';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
td.querySelector('#ticketForm').dispatchEvent(new ticketWindow.Event('submit', { bubbles: true, cancelable: true }));
check('ب-۱۲: اگر متنی تایپ شود ولی نتیجه‌ای انتخاب نشود، سیستمِ قدیمی ثبت نمی‌شود', hiddenSelect.value === '');

// جست‌وجو با ارقام فارسی هم باید همان کامپیوتر را پیدا کند
search.value = '۰۹۹';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
const faItems = Array.prototype.slice.call(results.querySelectorAll('[data-combo-index]'));
check('ب-۱۳: ارقام فارسی در جست‌وجو هم معادل ارقام لاتین کار می‌کنند',
  faItems.length === 2 && faItems[1].textContent.indexOf('PC-099') !== -1,
  faItems.map(function (n) { return n.textContent; }).join(' | '));

pressKey(ticketWindow, search, 'Escape');
check('ب-۱۴: Escape فهرست را می‌بندد', results.hidden === true);

// هم‌زیستی با منطق قبلی: خدمتی که به سیستم نیاز ندارد، انتخاب را پاک می‌کند
search.value = 'pc-099';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
const item099 = results.querySelectorAll('[data-combo-index]')[1];
item099.dispatchEvent(new ticketWindow.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
item099.dispatchEvent(new ticketWindow.MouseEvent('click', { bubbles: true }));
const serviceSelector = td.querySelector('#service-selector');
check('ب-۱۵: انتخاب سیستم با خدمت نیازمند سیستم کار می‌کند', hiddenSelect.value === '8', hiddenSelect.value);

/* ─── ۱.۳۷.۲: قاعدهٔ حوزه‌محور برای «سیستم مرتبط» ─────────────────────────── */
const assetLabel = td.querySelector('.ticket-asset-field');
const assetError = td.querySelector('[data-asset-error]');
const groupRadios = td.querySelectorAll('input[name="service_group"]');
const submitForm = function () {
  const ev = new ticketWindow.Event('submit', { bubbles: true, cancelable: true });
  td.querySelector('#ticketForm').dispatchEvent(ev);
  return ev;
};

// «مشاوره» در حوزهٔ IT: فیلد باید بماند و الزامی باشد (تغییر قاعده در ۱.۳۷.۲)
serviceSelector.value = '6';
serviceSelector.dispatchEvent(new ticketWindow.Event('change', { bubbles: true }));
check('ب-۱۶: در حوزهٔ خدمات کامپیوتری فیلد «سیستم مرتبط» پیدا می‌ماند (حتی برای خدمتی که سیستم نمی‌خواهد)',
  assetLabel.hidden === false, String(assetLabel.hidden));
check('ب-۱۷: فیلد برای صفحه‌خوان الزامی علامت می‌خورد', search.getAttribute('aria-required') === 'true',
  String(search.getAttribute('aria-required')));

// ارسال بدون انتخاب سیستم در حوزهٔ IT باید گرفته شود
// (ابتدا انتخاب فعلی را پاک می‌کنیم؛ در حوزهٔ IT انتخاب قبلی عمداً حفظ می‌شود.)
search.value = '';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
let submitEvent = submitForm();
check('ب-۱۸: ارسال فرم در حوزهٔ IT بدون انتخاب سیستم گرفته می‌شود', submitEvent.defaultPrevented === true);
check('ب-۱۹: پیام خطای درون‌خطی نمایش داده می‌شود', assetError.hidden === false && assetError.textContent.indexOf('الزامی') !== -1,
  assetError.textContent);
check('ب-۲۰: فیلد حالت خطا می‌گیرد و برای صفحه‌خوان نامعتبر است',
  assetLabel.classList.contains('field-invalid') && search.getAttribute('aria-invalid') === 'true');

// با انتخاب یک سیستم، خطا پاک می‌شود و ارسال می‌گذرد
search.value = 'pc-023';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
pressKey(ticketWindow, search, 'Enter');
check('ب-۲۱: با یک نتیجه، Enter همان سیستم را انتخاب می‌کند (بدون ↑/↓)',
  hiddenSelect.value === '7' && search.value === 'PC-023 - T-1001', hiddenSelect.value + ' / ' + search.value);
check('ب-۲۲: با انتخاب سیستم، پیام خطا پاک می‌شود', assetError.hidden === true);
submitEvent = submitForm();
check('ب-۲۳: ارسال فرم با انتخاب‌شده در حوزهٔ IT می‌گذرد', submitEvent.defaultPrevented === false);

// با چند نتیجه، Enter نباید چیزی انتخاب کند و نباید فرم را بفرستد
search.value = 't-100';
search.dispatchEvent(new ticketWindow.Event('input', { bubbles: true }));
const multiCount = results.querySelectorAll('[data-combo-id]').length;
const multiEnter = pressKey(ticketWindow, search, 'Enter');
check('ب-۲۴: با چند نتیجه، Enter انتخاب نمی‌کند ولی فرم را هم نمی‌فرستد',
  multiCount >= 2 && hiddenSelect.value === '' && multiEnter.defaultPrevented === true,
  String(multiCount) + ' / ' + hiddenSelect.value);
pressKey(ticketWindow, search, 'Escape');

// سوئیچ به حوزهٔ پشتیبانی: فیلد کامل مخفی و بدون الزام
groupRadios[1].checked = true;
groupRadios[1].dispatchEvent(new ticketWindow.Event('change', { bubbles: true }));
check('ب-۲۵: در حوزهٔ پشتیبانی فیلد «سیستم مرتبط» کامل مخفی می‌شود', assetLabel.hidden === true);
check('ب-۲۶: در حوزهٔ پشتیبانی انتخاب سیستم پاک و الزام برداشته می‌شود',
  hiddenSelect.value === '' && search.getAttribute('aria-required') === 'false',
  hiddenSelect.value + ' / ' + search.getAttribute('aria-required'));
groupRadios[0].checked = true;
groupRadios[0].dispatchEvent(new ticketWindow.Event('change', { bubbles: true }));
check('ب-۲۷: بازگشت به حوزهٔ IT فیلد را دوباره پیدا و الزامی می‌کند',
  assetLabel.hidden === false && search.getAttribute('aria-required') === 'true');

console.log('\n' + (fail === 0
  ? 'همهٔ ' + pass + ' بررسی رابط کاربری (۱.۳۷) موفق بود.\n'
  : fail + ' بررسی ناموفق از ' + (pass + fail) + ' مورد.\n'));
process.exit(fail === 0 ? 0 : 1);
