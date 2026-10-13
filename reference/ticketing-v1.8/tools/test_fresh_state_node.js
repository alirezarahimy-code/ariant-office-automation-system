/**
 * آزمون تفکیک «دیتابیس خام» در سمت مرورگر — نسخهٔ ۱.۳۵
 * ============================================================================
 *   node tools/test_fresh_state_node.js
 *
 * این آزمون، خودِ منطق گاردِ پنل غذا را از food-ticket-web/index.html بیرون می‌کشد و در
 * چهار سناریوی واقعی اجرا می‌کند:
 *   ۱) کش مرورگر به نصب دیگری تعلق دارد (توکن متفاوت) → داده‌ها دور ریخته می‌شوند،
 *      ولی «تنظیمات اتصال به سامانه/چاپگر» عمداً حفظ می‌شود (۱.۳۵)
 *   ۲) سرور می‌گوید دیتابیس خام است (fresh) → داده‌ها دور ریخته می‌شوند
 *   ۳) توکن یکسان و دیتابیس پر → کش معتبر است و باید بماند
 *   ۴) resetLocalState باید همهٔ آرایه‌ها را خالی کند
 *
 * نیازمند Node.js (اختیاری). روی سرور بدون Node هم بقیهٔ سوئیت‌ها کار می‌کنند.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const htmlPath = path.join(__dirname, '..', 'food-ticket-web', 'index.html');
const html = fs.readFileSync(htmlPath, 'utf8');

const start = html.indexOf('const seed=');
const end = html.indexOf('let view=state.view');
if (start < 0 || end < 0) {
  console.error('[FAIL] بخش state از index.html استخراج نشد.');
  process.exit(1);
}
const runtime = html.slice(start, end);

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

/** اجرای گارد با کش مرورگر و توکن سرور مشخص. */
function boot(savedPayload, serverToken, serverFresh) {
  const store = {};
  if (savedPayload !== null) {
    store['food-ticket-web'] = JSON.stringify(savedPayload);
  }
  const sandbox = {
    window: {
      FOOD_TICKET_DB_TOKEN: serverToken,
      FOOD_TICKET_DB_FRESH: serverFresh,
      localStorage: {
        getItem: (k) => (Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null),
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
      },
    },
    console,
  };
  sandbox.window.__store = store;
  const code = runtime + '\n;globalThis.__out={state:state,resetLocalState:resetLocalState,applyDbIdentity:applyDbIdentity,seedJson:JSON.stringify(seed)};';
  // eslint-disable-next-line no-new-func
  const run = new Function('window', 'globalThis', code);
  run(sandbox.window, sandbox);
  sandbox.__out.store = store;
  return sandbox.__out;
}

const dirty = {
  __dbToken: 'token-old',
  view: 'live',
  employees: [{ id: 1, name: 'کاربر قدیمی', pc: '9001', active: true }],
  orders: [{ id: 5, pc: '9001', food: 'قیمه بادمجان' }],
  absentRows: [{ nat: '001', pc: '9001', food: 'قیمه بادمجان' }],
  guests: { cards: ['A1B2C3D4'], cooldown: 3, maxDaily: 20, food: 'مهمان' },
  settings: { attendancePath: '\\\\old\\path.mdb', attendanceTable: 'SOURCE_TABLE', ordersTable: 'food_fish' },
};

console.log('=== آزمون تفکیک دیتابیس خام (سمت مرورگر) ===\n');

/* ۱) توکن متفاوت → دور ریخته شود */
const r1 = boot(dirty, 'token-new', false);
check('۱: توکن متفاوت → دادهٔ قدیمی نمایش داده نمی‌شود', r1.state.employees.length === 0 && r1.state.orders.length === 0 && r1.state.absentRows.length === 0, JSON.stringify({ emp: r1.state.employees.length, ord: r1.state.orders.length }));
// ۱.۳۵: «داده» دور ریخته می‌شود، ولی «تنظیمات اتصال» باید حفظ شود (وگرنه با یک کش خالی،
// پیکربندی سالم سامانه از بین می‌رفت و ماژول دیگر به سامانه وصل نمی‌شد).
check('۱-ب: تنظیمات اتصال از کش قدیمی حفظ می‌شود (عمدی)', (r1.state.settings.attendancePath || '') === '\\\\old\\path.mdb', r1.state.settings.attendancePath);
check('۱-پ: چاپگر نیز حفظ می‌شود', (r1.state.printer.name || '') !== '', r1.state.printer.name);
check('۱-ت: توکن تازهٔ سرور در وضعیت ذخیره شد (پاکسازی تکرار نمی‌شود)', String(r1.state.__dbToken || '') === 'token-new', String(r1.state.__dbToken || ''));

/* ۲) دیتابیس خام → دور ریخته شود (حتی با توکن یکسان) */
const r2 = boot({ ...dirty, __dbToken: 'token-same' }, 'token-same', true);
check('۲: دیتابیس خام → همهٔ دادهٔ ذخیره‌شدهٔ مرورگر دور ریخته می‌شود', r2.state.employees.length === 0 && r2.state.orders.length === 0 && r2.state.absentRows.length === 0);
check('۲-ب: کارت‌های مهمان قدیمی نمایش داده نمی‌شوند', String(r2.state.guests.cards || '').indexOf('A1B2C3D4') < 0, String(r2.state.guests.cards));

/* ۳) توکن یکسان و دیتابیس پر → کش معتبر بماند */
const r3 = boot(dirty, 'token-old', false);
check('۳: توکن یکسان و دیتابیس پر → کش مرورگر حفظ می‌شود', r3.state.employees.length === 1 && r3.state.orders.length === 1, JSON.stringify({ emp: r3.state.employees.length, ord: r3.state.orders.length }));

/* ۴) resetLocalState آرایه‌ها را خالی می‌کند */
const r4 = boot(dirty, 'token-old', false);
r4.resetLocalState();
check('۴: resetLocalState همهٔ آرایه‌ها را خالی می‌کند', r4.state.employees.length === 0 && r4.state.orders.length === 0 && r4.state.absentRows.length === 0 && r4.state.events.length === 0);
check('۴-ب: resetLocalState کش مرورگر را هم پاک می‌کند', r4.store['food-ticket-web'] === undefined);

/* ۵) applyDbIdentity با پاسخ زندهٔ سرور */
const r5 = boot(dirty, '', false);
r5.applyDbIdentity({ token: 'token-fresh-server', fresh: true, counts: {} });
check('۵: پاسخ /api/config با fresh=true → پاکسازی زنده', r5.state.employees.length === 0 && r5.store['food-ticket-web'] === undefined);

const r6 = boot(dirty, '', false);
const changed6 = r6.applyDbIdentity({ token: 'token-other-server', fresh: false, counts: { tickets: 3 } });
check('۵-ب: توکن جدید سرور با کش قدیمی → پاکسازی و true برگشتی', changed6 === true && r6.state.orders.length === 0);

const r7 = boot(dirty, '', false);
const changed7 = r7.applyDbIdentity({ token: 'token-old', fresh: false, counts: { tickets: 9 } });
check('۵-پ: توکن یکسان → هیچ پاکسازی‌ای انجام نمی‌شود', changed7 === false && r7.state.orders.length === 1);

console.log('');
if (fail === 0) {
  console.log('همهٔ بررسی‌های تفکیک دیتابیس خام موفق بودند (' + pass + ' مورد).');
  process.exit(0);
}
console.log(fail + ' مورد ناموفق از ' + (pass + fail) + ' مورد.');
process.exit(1);
