import assert from 'node:assert/strict';
import test from 'node:test';
import {
  batchAction,
  idList,
  isoDate,
  plannedTaskData,
  projectDependencyReferenceKey,
  projectReferenceAddData,
  projectReferenceImportData,
  projectReferenceKey,
  projectReferenceRelationData,
  taskCollectionFieldNames,
  taskCreateData,
  taskListInput,
  taskUpdateData,
} from '../src/schemas.js';

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

test('task creation and update schemas keep done behind task_complete', () => {
  assert.equal(plannedTaskData.safeParse({ name: 'Done plan task', status: 'done' }).success, false);
  assert.equal(batchAction.safeParse({
    action: 'create_task',
    board_name: 'project_alpha',
    data: { name: 'Done batch task', status: 'done' },
  }).success, false);
  assert.equal(batchAction.safeParse({
    action: 'update_task',
    data: { id: 1, status: 'done' },
  }).success, false);
});

test('task description inputs cap raw content at 10,000 code units', () => {
  const description = 'x'.repeat(10_000);
  const tooLong = 'x'.repeat(10_001);
  assert.equal(taskCreateData.safeParse({ name: 'At the limit', description }).success, true);
  assert.equal(taskUpdateData.safeParse({ description }).success, true);
  assert.equal(plannedTaskData.safeParse({ name: 'At the limit', description }).success, true);
  assert.equal(batchAction.safeParse({
    action: 'create_task',
    board_name: 'project_alpha',
    data: { name: 'At the limit', description },
  }).success, true);
  assert.equal(taskCreateData.safeParse({ name: 'Too long', description: tooLong }).success, false);
  assert.equal(taskUpdateData.safeParse({ description: tooLong }).success, false);
  assert.equal(plannedTaskData.safeParse({ name: 'Too long', description: tooLong }).success, false);
  assert.equal(batchAction.safeParse({
    action: 'create_task',
    board_name: 'project_alpha',
    data: { name: 'Too long', description: tooLong },
  }).success, false);
});

test('task collection schemas advertise the authoritative frontend URL field', () => {
  assert.equal(taskCollectionFieldNames.includes('frontend_url'), true);
  assert.equal(taskListInput.safeParse({ board_name: 'group_10', fields: ['frontend_url'] }).success, true);
});

test('project reference schemas enforce association and dependency discriminants', () => {
  assert.equal(projectReferenceRelationData.safeParse({ relation_type: 'included', task_id: 12 }).success, true);
  assert.equal(projectReferenceRelationData.safeParse({ relation_type: 'related', task_id: 12 }).success, true);
  assert.equal(projectReferenceRelationData.safeParse({ relation_type: 'dependency', predecessor_task_id: 12, successor_task_id: 13 }).success, true);
  assert.equal(projectReferenceRelationData.safeParse({ relation_type: 'dependency', task_id: 12 }).success, false);
  assert.equal(projectReferenceRelationData.safeParse({ relation_type: 'included', predecessor_task_id: 12, successor_task_id: 13 }).success, false);
  assert.equal(projectReferenceAddData.safeParse({ relation_type: 'included', project_id: 4, task_id: 12 }).success, true);
  assert.equal(projectReferenceAddData.safeParse({ relation_type: 'dependency', project_id: 4, predecessor_task_id: 12, successor_task_id: 13 }).success, true);
  assert.equal(projectReferenceAddData.safeParse({ relation_type: 'unknown', project_id: 4, task_id: 12 }).success, false);
});

test('project reference keys and import versions are bounded', () => {
  assert.equal(projectReferenceKey.safeParse('reference-1').success, true);
  assert.equal(projectReferenceKey.safeParse('dependency-1').success, false);
  assert.equal(projectDependencyReferenceKey.safeParse('reference-12').success, true);
  assert.equal(projectDependencyReferenceKey.safeParse('dependency-12').success, true);
  assert.equal(projectDependencyReferenceKey.safeParse('reference-0').success, false);
  assert.equal(projectDependencyReferenceKey.safeParse('dependency-abc').success, false);

  const parsed = projectReferenceImportData.parse({
    project_id: 4,
    references: [{ relation_type: 'related', task_id: 12 }],
  });
  assert.equal(parsed.version, 1);
  assert.equal(projectReferenceImportData.safeParse({ project_id: 4, version: 2, references: [] }).success, false);
});
