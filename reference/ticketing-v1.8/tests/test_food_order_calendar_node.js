'use strict';

const assert = require('node:assert/strict');

// The shared Jalali helper expects the same small browser globals it gets in index.php.
global.window = global;
global.document = {
  addEventListener() {},
  querySelectorAll() { return []; },
};

require('../assets/jalali-calendar.js');
const Calendar = require('../assets/food-order-calendar.js');

assert.deepEqual(Calendar.jalaliDateFromIso('2026-10-08'), [1405, 7, 16]);
assert.equal(Calendar.isoDate(1405, 1, 1), '2026-03-21');
assert.equal(Calendar.monthLength(1405, 7), 30);

const cells = Calendar.monthCells(1405, 7);
assert.equal(cells.length, 42, 'calendar grid should be six complete weeks');
assert.equal(cells.filter((cell) => cell.isCurrentMonth).length, 30);
assert.equal(cells.find((cell) => cell.iso === '2026-10-08').isWeekend, true, 'Thursday should be highlighted');
assert.equal(cells.find((cell) => cell.iso === '2026-10-09').isWeekend, true, 'Friday should be highlighted');
assert.equal(cells.find((cell) => cell.iso === '2026-10-10').isWeekend, false, 'Saturday should not be highlighted as a weekend');
assert.deepEqual(Calendar.shiftMonth(1405, 1, -1), [1404, 12]);

console.log('PASS Jalali conversion, month grid, and Thursday/Friday highlighting');
