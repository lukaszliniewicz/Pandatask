import test from 'node:test';
import assert from 'node:assert/strict';
import { taskCreateData, taskUpdateData } from '../src/schemas.js';

test('recurrence schemas preserve explicit intervals and ISO Sunday', () => {
  const weekly = taskCreateData.parse({
    name: 'Every four Sundays',
    is_recurring: true,
    recurrence_frequency: 'custom_weekly',
    recurrence_interval: 4,
    recurrence_days: '7',
  });
  assert.equal(weekly.recurrence_interval, 4);
  assert.equal(weekly.recurrence_days, '7');
  assert.throws(
    () => taskCreateData.parse({ name: 'Bad Sunday', recurrence_frequency: 'custom_weekly', recurrence_days: '0' }),
    /ISO weekdays/,
  );
  assert.throws(
    () => taskCreateData.parse({ name: 'Malformed weekdays', recurrence_frequency: 'custom_weekly', recurrence_days: '1,,7' }),
    /ISO weekdays/,
  );
});

test('monthly weekday recurrence accepts ordinal weekday rules', () => {
  for (const recurrence_month_week of ['first', 'second', 'third', 'fourth', 'last'] as const) {
    const parsed = taskCreateData.parse({
      name: `${recurrence_month_week} Sunday`,
      is_recurring: true,
      recurrence_frequency: 'monthly_weekday',
      recurrence_interval: 1,
      recurrence_days: '7',
      recurrence_month_week,
    });
    assert.equal(parsed.recurrence_month_week, recurrence_month_week);
  }
});

test('recurrence schemas reject unsupported field combinations with actionable errors', () => {
  assert.throws(
    () => taskCreateData.parse({ name: 'Incomplete monthly rule', recurrence_frequency: 'monthly_weekday', recurrence_days: '7' }),
    /recurrence_month_week/,
  );
  assert.throws(
    () => taskCreateData.parse({ name: 'Ambiguous monthly rule', recurrence_frequency: 'monthly_weekday', recurrence_days: '1,7', recurrence_month_week: 'first' }),
    /exactly one ISO weekday/,
  );
  assert.throws(
    () => taskUpdateData.parse({ recurrence_frequency: 'weekly', recurrence_month_week: 'last' }),
    /supported only with monthly_weekday/,
  );
  assert.throws(
    () => taskUpdateData.parse({ recurrence_frequency: 'bi-weekly', recurrence_interval: 4 }),
    /legacy alias/,
  );
});
