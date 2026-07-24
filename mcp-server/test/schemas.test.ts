import assert from 'node:assert/strict';
import test from 'node:test';
import { batchAction, idList, isoDate, plannedTaskData } from '../src/schemas.js';

test('calendar dates reject impossible month and day values', () => {
  assert.equal(isoDate.safeParse('2026-07-24').success, true);
  assert.equal(isoDate.safeParse('2026-02-30').success, false);
  assert.equal(isoDate.safeParse('2026-13-01').success, false);
});

test('planned tasks use dependency indexes and cannot override workflow-owned relationships', () => {
  assert.equal(plannedTaskData.safeParse({ name: 'Valid', depends_on_task_indexes: [0] }).success, true);
  assert.equal(plannedTaskData.safeParse({ name: 'Invalid', project_id: 99 }).success, false);
  assert.equal(plannedTaskData.safeParse({ name: 'Invalid', predecessors: [99] }).success, false);
});

test('relationship IDs are unique and typed batch updates require an actual change', () => {
  assert.equal(idList.safeParse([1, 2]).success, true);
  assert.equal(idList.safeParse([1, 1]).success, false);
  assert.equal(batchAction.safeParse({ action: 'update_task', data: { id: 1 } }).success, false);
  assert.equal(batchAction.safeParse({ action: 'update_task', data: { id: 1, priority: 8 } }).success, true);
});
