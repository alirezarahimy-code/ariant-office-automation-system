/**
 * تقویم جلالی مشترک سامانه تیکت
 * - پیمایش ماه و سال
 * - امروز
 * - تعطیلات قرمز (از window.ITSM_HOLIDAYS)
 */
(function (global) {
  'use strict';

  var WEEK = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
  var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function j2g(jy, jm, jd) {
    var jy2 = jy - 979, jm2 = jm - 1, jd2 = jd - 1;
    var jdn = 365 * jy2 + Math.floor(jy2 / 33) * 8 + Math.floor(((jy2 % 33) + 3) / 4);
    for (var i = 0; i < jm2; i++) jdn += i < 6 ? 31 : 30;
    jdn += jd2;
    var gdn = jdn + 79;
    var gy = 1600 + 400 * Math.floor(gdn / 146097);
    gdn %= 146097;
    var leap = true;
    if (gdn >= 36525) {
      gdn--;
      gy += 100 * Math.floor(gdn / 36524);
      gdn %= 36524;
      if (gdn >= 365) gdn++; else leap = false;
    }
    gy += 4 * Math.floor(gdn / 1461);
    gdn %= 1461;
    if (gdn >= 366) {
      leap = false;
      gdn--;
      gy += Math.floor(gdn / 365);
      gdn %= 365;
    }
    var gd = gdn + 1;
    var sal_a = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gm = 0;
    for (gm = 1; gm <= 12 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
    return [gy, gm, gd];
  }

  function g2j(gy, gm, gd) {
    var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    var gy2 = gy - 1600, gm2 = gm - 1, gd2 = gd - 1;
    var gdn = 365 * gy2 + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400);
    gdn += g_d_m[gm2] + gd2;
    if (gm2 > 1 && ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0)) gdn++;
    var jdn = gdn - 79;
    var j_np = Math.floor(jdn / 12053);
    jdn %= 12053;
    var jy = 979 + 33 * j_np + 4 * Math.floor(jdn / 1461);
    jdn %= 1461;
    if (jdn >= 366) {
      jy += Math.floor((jdn - 1) / 365);
      jdn = (jdn - 1) % 365;
    }
    var jm, jd, i;
    for (i = 0; i < 11 && jdn >= (i < 6 ? 31 : 30); i++) jdn -= i < 6 ? 31 : 30;
    jm = i + 1;
    jd = jdn + 1;
    return [jy, jm, jd];
  }

  function monthLength(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    var a = j2g(jy, 12, 1), b = j2g(jy + 1, 1, 1);
    return Math.round((Date.UTC(b[0], b[1] - 1, b[2]) - Date.UTC(a[0], a[1] - 1, a[2])) / 86400000);
  }

  function parseJalali(value) {
    var s = String(value || '').trim().replace(/-/g, '/').replace(/[۰-۹]/g, function (d) {
      return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
    });
    var m = s.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    return m ? [Number(m[1]), Number(m[2]), Number(m[3])] : null;
  }

  function formatJalali(jy, jm, jd) {
    return jy + '/' + pad(jm) + '/' + pad(jd);
  }

  function holidaySet() {
    var list = global.ITSM_HOLIDAYS || [];
    var set = {};
    list.forEach(function (h) {
      var key = h.jalali || h.date || h;
      if (typeof key === 'string') set[key.replace(/-/g, '/')] = h.title || 'تعطیل';
    });
    return set;
  }

  function isWeekendJalali(jy, jm, jd) {
    var g = j2g(jy, jm, jd);
    var d = new Date(g[0], g[1] - 1, g[2]);
    var n = d.getDay(); // 0 Sun .. 5 Fri 6 Sat — in Iran week starts Sat
    // JS: Thu=4, Fri=5 — Iranian weekend Thu+Fri
    return n === 4 || n === 5;
  }

  function todayJ() {
    var n = new Date();
    return g2j(n.getFullYear(), n.getMonth() + 1, n.getDate());
  }

  function bindPicker(root) {
    if (root.getAttribute('data-jalali-bound') === '1') return;
    var input = root.querySelector('input');
    var panel = root.querySelector('.jalali-calendar');
    var trigger = root.querySelector('.calendar-trigger');
    if (!input || !panel) return;
    root.setAttribute('data-jalali-bound', '1');

    var t = todayJ();
    var parsed = parseJalali(input.value);
    var viewY = parsed ? parsed[0] : t[0];
    var viewM = parsed ? parsed[1] : t[1];
    var holidays = holidaySet();

    function close() { panel.hidden = true; }
    function open() { panel.hidden = false; render(); }

    function render() {
      holidays = holidaySet();
      var len = monthLength(viewY, viewM);
      var firstG = j2g(viewY, viewM, 1);
      var first = new Date(firstG[0], firstG[1] - 1, firstG[2]);
      // Saturday-based index
      var startIndex = (first.getDay() + 1) % 7;
      var html = '';
      html += '<div class="jc-toolbar">';
      html += '<button type="button" class="jc-nav" data-y-prev title="سال قبل">«</button>';
      html += '<button type="button" class="jc-nav" data-m-prev title="ماه قبل">‹</button>';
      html += '<div class="jc-title"><select data-month class="jc-select">';
      MONTHS.forEach(function (name, i) {
        html += '<option value="' + (i + 1) + '"' + (i + 1 === viewM ? ' selected' : '') + '>' + name + '</option>';
      });
      html += '</select><select data-year class="jc-select">';
      for (var y = viewY - 60; y <= viewY + 20; y++) {
        html += '<option value="' + y + '"' + (y === viewY ? ' selected' : '') + '>' + y + '</option>';
      }
      html += '</select></div>';
      html += '<button type="button" class="jc-nav" data-m-next title="ماه بعد">›</button>';
      html += '<button type="button" class="jc-nav" data-y-next title="سال بعد">»</button>';
      html += '</div>';
      html += '<div class="jc-weekdays">';
      WEEK.forEach(function (w) { html += '<span>' + w + '</span>'; });
      html += '</div><div class="jc-days">';
      var i;
      for (i = 0; i < startIndex; i++) html += '<span class="jc-empty"></span>';
      for (var day = 1; day <= len; day++) {
        var key = formatJalali(viewY, viewM, day);
        var weekend = isWeekendJalali(viewY, viewM, day);
        var hol = holidays[key];
        var selected = input.value === key || input.value === key.replace(/\//g, '-');
        var isToday = t[0] === viewY && t[1] === viewM && t[2] === day;
        var cls = 'jc-day';
        if (weekend) cls += ' jc-weekend';
        if (hol) cls += ' jc-holiday';
        if (selected) cls += ' jc-selected';
        if (isToday) cls += ' jc-today';
        var title = hol ? hol : (weekend ? 'تعطیل آخر هفته' : '');
        html += '<button type="button" class="' + cls + '" data-day="' + day + '" title="' + title + '">' + day + '</button>';
      }
      html += '</div>';
      html += '<div class="jc-footer"><button type="button" class="jc-today-btn" type="button">امروز</button>';
      html += '<span class="jc-legend"><i class="jc-dot holiday"></i>تعطیل <i class="jc-dot weekend"></i>پ/ج</span></div>';
      panel.innerHTML = html;

      panel.querySelector('[data-m-prev]').onclick = function () { viewM--; if (viewM < 1) { viewM = 12; viewY--; } render(); };
      panel.querySelector('[data-m-next]').onclick = function () { viewM++; if (viewM > 12) { viewM = 1; viewY++; } render(); };
      panel.querySelector('[data-y-prev]').onclick = function () { viewY--; render(); };
      panel.querySelector('[data-y-next]').onclick = function () { viewY++; render(); };
      panel.querySelector('[data-month]').onchange = function (e) { viewM = Number(e.target.value); render(); };
      panel.querySelector('[data-year]').onchange = function (e) { viewY = Number(e.target.value); render(); };
      panel.querySelector('.jc-today-btn').onclick = function () {
        var td = todayJ();
        input.value = formatJalali(td[0], td[1], td[2]);
        input.dispatchEvent(new Event('change', { bubbles: true }));
        close();
      };
      panel.querySelectorAll('[data-day]').forEach(function (btn) {
        btn.onclick = function () {
          var d = Number(btn.getAttribute('data-day'));
          input.value = formatJalali(viewY, viewM, d);
          input.dispatchEvent(new Event('change', { bubbles: true }));
          close();
        };
      });
    }

    if (trigger) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        if (panel.hidden) open(); else close();
      });
    }
    input.addEventListener('focus', open);
  }

  function installOutsideClickHandler() {
    if (global.__itsmJalaliOutsideClickInstalled) return;
    global.__itsmJalaliOutsideClickInstalled = true;
    document.addEventListener('click', function (e) {
      document.querySelectorAll('.jalali-calendar:not([hidden])').forEach(function (panel) {
        var picker = panel.closest('[data-jalali-picker]');
        if (!picker || !picker.contains(e.target)) panel.hidden = true;
      });
    }, true);
  }

  /** تقویم سالانه برای تیک زدن تعطیلات */
  function bindYearBoard(root) {
    if (!root) return;
    var yearSelect = root.querySelector('[data-year-board-year]');
    var grid = root.querySelector('[data-year-board-grid]');
    var t = todayJ();
    var year = Number(yearSelect && yearSelect.value) || t[0];

    function render() {
      var holidays = holidaySet();
      var html = '';
      for (var m = 1; m <= 12; m++) {
        html += '<div class="year-month-card"><h4>' + MONTHS[m - 1] + '</h4><div class="jc-weekdays mini">';
        WEEK.forEach(function (w) { html += '<span>' + w + '</span>'; });
        html += '</div><div class="jc-days mini">';
        var firstG = j2g(year, m, 1);
        var first = new Date(firstG[0], firstG[1] - 1, firstG[2]);
        var startIndex = (first.getDay() + 1) % 7;
        var len = monthLength(year, m);
        var i;
        for (i = 0; i < startIndex; i++) html += '<span class="jc-empty"></span>';
        for (var d = 1; d <= len; d++) {
          var key = formatJalali(year, m, d);
          var weekend = isWeekendJalali(year, m, d);
          var hol = holidays[key];
          var cls = 'jc-day year-day';
          if (weekend) cls += ' jc-weekend';
          if (hol) cls += ' jc-holiday active-holiday';
          html += '<button type="button" class="' + cls + '" data-jdate="' + key + '" title="' + (hol || '') + '">' + d + '</button>';
        }
        html += '</div></div>';
      }
      grid.innerHTML = html;
      grid.querySelectorAll('[data-jdate]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var jdate = btn.getAttribute('data-jdate');
          var form = root.querySelector('form[data-toggle-holiday]');
          if (!form) return;
          form.querySelector('[name="holiday_date"]').value = jdate;
          form.querySelector('[name="holiday_title"]').value = btn.classList.contains('active-holiday') ? '' : 'تعطیل رسمی';
          form.submit();
        });
      });
    }

    if (yearSelect) {
      yearSelect.addEventListener('change', function () {
        year = Number(yearSelect.value);
        render();
      });
    }
    render();
  }

  function enhanceAllDateFields(scope) {
    scope = scope || document;
    installOutsideClickHandler();
    // input[type=date] → تبدیل به جلالی picker
    scope.querySelectorAll('input[type="date"]').forEach(function (el) {
      if (el.closest('[data-jalali-picker]')) return;
      el.type = 'text';
      el.setAttribute('placeholder', '۱۴۰۵/۰۱/۰۱');
      el.setAttribute('autocomplete', 'off');
      var wrap = document.createElement('div');
      wrap.className = 'jalali-picker';
      wrap.setAttribute('data-jalali-picker', '');
      el.parentNode.insertBefore(wrap, el);
      wrap.appendChild(el);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'calendar-trigger';
      btn.setAttribute('aria-label', 'تقویم');
      btn.textContent = '▦';
      wrap.appendChild(btn);
      var cal = document.createElement('div');
      cal.className = 'jalali-calendar';
      cal.hidden = true;
      wrap.appendChild(cal);
    });

    // فیلدهای متنی تاریخ گزارش
    scope.querySelectorAll('input[name="from"], input[name="to"], input[data-jalali], input.jalali-date').forEach(function (el) {
      if (el.closest('[data-jalali-picker]')) return;
      var wrap = document.createElement('div');
      wrap.className = 'jalali-picker';
      wrap.setAttribute('data-jalali-picker', '');
      el.parentNode.insertBefore(wrap, el);
      wrap.appendChild(el);
      el.setAttribute('autocomplete', 'off');
      if (!el.placeholder) el.placeholder = '۱۴۰۵/۰۱/۰۱';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'calendar-trigger';
      btn.textContent = '▦';
      wrap.appendChild(btn);
      var cal = document.createElement('div');
      cal.className = 'jalali-calendar';
      cal.hidden = true;
      wrap.appendChild(cal);
    });

    scope.querySelectorAll('[data-jalali-picker]').forEach(bindPicker);
    var board = scope.querySelector('[data-year-holiday-board]');
    if (board) bindYearBoard(board);
  }

  global.ItsmJalali = {
    j2g: j2g,
    g2j: g2j,
    parse: parseJalali,
    format: formatJalali,
    enhanceAll: enhanceAllDateFields,
    bindPicker: bindPicker,
    bindYearBoard: bindYearBoard
  };

  document.addEventListener('DOMContentLoaded', function () {
    enhanceAllDateFields(document);
  });
})(window);
