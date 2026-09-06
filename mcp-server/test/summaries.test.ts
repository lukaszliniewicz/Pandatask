import assert from 'node:assert/strict';
import test from 'node:test';
import { deadlineReview, summarizeTasks, workload } from '../src/summaries.js';

const tasks = [
  { id: 1, name: 'Late', status: 'in-progress', priority: 9, deadline: '2026-07-18', assigned_user_ids: ['2'], is_blocked: false },
  { id: 2, name: 'Today', status: 'pending', priority: 5, deadline: '2026-07-19', assigned_user_ids: '', is_blocked: true },
  { id: 3, name: 'Soon', status: 'pending', priority: 8, deadline: '2026-07-22', assigned_user_ids: '2,3', is_blocked: false },
  { id: 4, name: 'Done', status: 'done', priority: 10, deadline: '2026-07-10', assigned_user_ids: '2', is_blocked: false },
];

test('summarizeTasks calculates actionable status and attention buckets', () => {
  const summary = summarizeTasks(tasks, '2026-07-19');
  assert.equal(summary.total, 4);
  assert.deepEqual(summary.by_status, { pending: 2, in_progress: 1, done: 1 });
  assert.equal(summary.overdue, 1);
  assert.equal(summary.due_today, 1);
  assert.equal(summary.due_next_7_days, 1);
  assert.equal(summary.high_priority_open, 2);
  assert.equal(summary.unassigned_open, 1);
  assert.equal(summary.blocked_open, 1);
});

test('workload and deadline review preserve useful task references', () => {
  assert.deepEqual(workload(tasks, '2026-07-19'), [
    { user_id: 2, open: 2, overdue: 1, high_priority: 2, task_ids: [1, 3] },
    { user_id: 3, open: 1, overdue: 0, high_priority: 1, task_ids: [3] },
  ]);
  const review = deadlineReview(tasks, 3, '2026-07-19');
  assert.equal(review.count, 3);
  assert.deepEqual((review.tasks as Record<string, unknown>[]).map((task) => task.id), [1, 2, 3]);
});

test('recurrence-enabled rows are concrete actionable occurrences in summaries', () => {
  const summary = summarizeTasks(
    [...tasks, {
      id: 5,
      name: 'Recurring occurrence',
      status: 'pending',
      priority: 10,
      deadline: '2026-07-18',
      is_recurring: true,
      recurrence_series_id: 9,
      recurrence_sequence: 3,
      recurrence_scheduled_start: '2026-07-18',
    }],
    '2026-07-19',
  );
  assert.equal(summary.total, 5);
  assert.equal(summary.total_records, 5);
  assert.equal(summary.recurring_tasks, 1);
  assert.equal('recurring_templates' in summary, false);
  assert.deepEqual((summary.attention as Record<string, unknown>).overdue, [
    {
      id: 1,
      name: 'Late',
      status: 'in-progress',
      priority: 9,
      deadline: '2026-07-18',
      project_id: null,
      project_name: null,
      assigned_user_ids: [2],
      supervisor_user_ids: [],
      is_blocked: false,
      is_recurring: false,
      recurrence_series_id: null,
      recurrence_sequence: null,
      recurrence_scheduled_start: null,
    },
    {
      id: 5,
      name: 'Recurring occurrence',
      status: 'pending',
      priority: 10,
      deadline: '2026-07-18',
      project_id: null,
      project_name: null,
      assigned_user_ids: [],
      supervisor_user_ids: [],
      is_blocked: false,
      is_recurring: true,
      recurrence_series_id: 9,
      recurrence_sequence: 3,
      recurrence_scheduled_start: '2026-07-18',
    },
  ]);
  assert.equal(summary.overdue, 2);
});
