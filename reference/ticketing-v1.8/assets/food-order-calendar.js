/* Shared Jalali calendar helpers for the employee form and the food-ticket manager view. */
(function (global) {
  'use strict';

  var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
  var WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

  function jalali() {
    if (!global.ItsmJalali || typeof global.ItsmJalali.j2g !== 'function' || typeof global.ItsmJalali.g2j !== 'function') {
      throw new Error('تقویم شمسی سامانه (ItsmJalali) بارگذاری نشده است.');
    }
    return global.ItsmJalali;
  }

  function pad(value) { return String(value).padStart(2, '0'); }

  function isoDate(year, month, day) {
    var g = jalali().j2g(Number(year), Number(month), Number(day));
    if (!Array.isArray(g) || g.length !== 3) throw new Error('تبدیل تاریخ شمسی معتبر نیست.');
    return String(g[0]).padStart(4, '0') + '-' + pad(g[1]) + '-' + pad(g[2]);
  }

  function jalaliDateFromIso(value) {
    var m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return null;
    return jalali().g2j(Number(m[1]), Number(m[2]), Number(m[3]));
  }

  function monthLength(year, month) {
    year = Number(year); month = Number(month);
    if (year < 1200 || year > 1600 || month < 1 || month > 12) throw new Error('ماه شمسی خارج از بازه است.');
    var start = jalali().j2g(year, month, 1);
    var next = month === 12 ? jalali().j2g(year + 1, 1, 1) : jalali().j2g(year, month + 1, 1);
    var startUtc = Date.UTC(start[0], start[1] - 1, start[2]);
    var nextUtc = Date.UTC(next[0], next[1] - 1, next[2]);
    return Math.round((nextUtc - startUtc) / 86400000);
  }

  function previousMonth(year, month) {
    return month === 1 ? [year - 1, 12] : [year, month - 1];
  }

  function nextMonth(year, month) {
    return month === 12 ? [year + 1, 1] : [year, month + 1];
  }

  function monthCells(year, month) {
    year = Number(year); month = Number(month);
    var count = monthLength(year, month);
    var first = jalali().j2g(year, month, 1);
    var firstWeekday = new Date(Date.UTC(first[0], first[1] - 1, first[2])).getUTCDay();
    // JavaScript Sunday=0; Iranian calendar starts Saturday, so Sat=0 … Fri=6.
    var leading = (firstWeekday + 1) % 7;
    var prev = previousMonth(year, month);
    var prevCount = monthLength(prev[0], prev[1]);
    var next = nextMonth(year, month);
    var cells = [];

    for (var index = 0; index < 42; index++) {
      var dayNumber = index - leading + 1;
      var cellYear = year, cellMonth = month, day = dayNumber, current = true;
      if (dayNumber < 1) {
        cellYear = prev[0]; cellMonth = prev[1]; day = prevCount + dayNumber; current = false;
      } else if (dayNumber > count) {
        cellYear = next[0]; cellMonth = next[1]; day = dayNumber - count; current = false;
      }
      var g = jalali().j2g(cellYear, cellMonth, day);
      var weekday = new Date(Date.UTC(g[0], g[1] - 1, g[2])).getUTCDay();
      var iso = String(g[0]).padStart(4, '0') + '-' + pad(g[1]) + '-' + pad(g[2]);
      cells.push({
        year: cellYear,
        month: cellMonth,
        day: day,
        iso: iso,
        jalali: String(cellYear).padStart(4, '0') + '/' + pad(cellMonth) + '/' + pad(day),
        isCurrentMonth: current,
        isWeekend: weekday === 4 || weekday === 5,
        weekday: weekday,
      });
    }
    return cells;
  }

  function shiftMonth(year, month, delta) {
    var total = (Number(year) * 12) + (Number(month) - 1) + Number(delta);
    var y = Math.floor(total / 12);
    var m = (total % 12) + 1;
    return [y, m];
  }

  var api = {
    months: MONTHS.slice(),
    weekdays: WEEKDAYS.slice(),
    isoDate: isoDate,
    jalaliDateFromIso: jalaliDateFromIso,
    monthLength: monthLength,
    monthCells: monthCells,
    shiftMonth: shiftMonth,
  };
  global.FoodOrderCalendar = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
