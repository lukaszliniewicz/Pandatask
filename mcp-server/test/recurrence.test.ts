import test from 'node:test';
import assert from 'node:assert/strict';
import { taskChecklistUpdateData, taskCreateData, taskRecurrenceGetData, taskUpdateData } from '../src/schemas.js';

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

test('recurrence edit scopes advertise this as the default and require a series version for future edits', () => {
  const current = taskUpdateData.parse({ name: 'Current occurrence' });
  assert.equal(current.recurrence_scope, undefined);
  assert.equal(taskUpdateData.shape.recurrence_scope.meta()?.default, 'this');
  assert.equal('recurrence_scope' in taskCreateData.parse({ name: 'New recurring task' }), false);

  assert.throws(
    () => taskUpdateData.parse({ name: 'Future occurrences', recurrence_scope: 'future' }),
    /expected_series_version is required/,
  );
  const future = taskUpdateData.parse({ name: 'Future occurrences', recurrence_scope: 'future', expected_series_version: 8 });
  assert.equal(future.recurrence_scope, 'future');
  assert.equal(future.expected_series_version, 8);
  assert.equal(taskUpdateData.safeParse({ name: 'Invalid series version', expected_series_version: -1 }).success, false);

  assert.throws(
    () => taskChecklistUpdateData.parse({ task_id: 7, expected_version: 2, items: [], recurrence_scope: 'future' }),
    /expected_series_version is required/,
  );
  const checklistFuture = taskChecklistUpdateData.parse({
    task_id: 7,
    expected_version: 2,
    items: [],
    recurrence_scope: 'future',
    expected_series_version: 8,
  });
  assert.equal(checklistFuture.recurrence_scope, 'future');
  assert.equal(checklistFuture.expected_series_version, 8);
  assert.equal(taskChecklistUpdateData.safeParse({ task_id: 7, items: [] }).success, false);
});

test('recurrence history schema bounds occurrence pagination and defaults the page size', () => {
  assert.deepEqual(taskRecurrenceGetData.parse({ task_id: 7 }), { task_id: 7, limit: 50 });
  assert.deepEqual(taskRecurrenceGetData.parse({ task_id: 7, limit: 100, before_sequence: 12 }), {
    task_id: 7,
    limit: 100,
    before_sequence: 12,
  });
  assert.equal(taskRecurrenceGetData.safeParse({ task_id: 7, limit: 0 }).success, false);
  assert.equal(taskRecurrenceGetData.safeParse({ task_id: 7, limit: 101 }).success, false);
  assert.equal(taskRecurrenceGetData.safeParse({ task_id: 7, before_sequence: 0 }).success, false);
});
