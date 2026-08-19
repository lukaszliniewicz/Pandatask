import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relative) => fs.readFileSync(path.join(repoRoot, relative), 'utf8');

const mutation = read('src/Application/Task/TaskMutationService.php');
const registrar = read('src/Integration/BuddyPress/BuddyPressRegistrar.php');
const projector = read('src/Integration/BuddyPress/TaskActivityProjector.php');

test('task lifecycle hooks are post-commit extension points isolated from persistence', () => {
  assert.match(mutation, /dispatchLifecycleEvent\(\s*'pandatask_task_created'/);
  assert.match(mutation, /dispatchLifecycleEvent\(\s*'pandatask_task_changed'/);
  assert.match(mutation, /dispatchLifecycleEvent\(\s*'pandatask_task_deleted'/);
  assert.match(mutation, /private function dispatchLifecycleEvent[\s\S]*try[\s\S]*do_action\([\s\S]*catch \( Throwable/);

  const createCommit = mutation.indexOf("throw new Exception( 'The task could not be committed.'");
  const createHook = mutation.indexOf("dispatchLifecycleEvent( 'pandatask_task_created'");
  assert.ok(createCommit >= 0 && createHook > createCommit, 'creation lifecycle event must happen after commit');

  const updateCommit = mutation.indexOf("throw new Exception( 'The task database update could not be committed.'");
  const updateHook = mutation.indexOf("'pandatask_task_changed'");
  assert.ok(updateCommit >= 0 && updateHook > updateCommit, 'change lifecycle event must happen after commit');

  const deleteCommit = mutation.indexOf("throw new Exception( 'The task deletion could not be committed.'");
  const deleteHook = mutation.indexOf("dispatchLifecycleEvent( 'pandatask_task_deleted'");
  assert.ok(deleteCommit >= 0 && deleteHook > deleteCommit, 'deletion lifecycle event must happen after commit');
});

test('BuddyPress projection is Pandatask-owned and one activity maps to one task', () => {
  assert.match(registrar, /new TaskActivityProjector\(\)/);
  assert.match(registrar, /task_activity_projector->register\(\)/);
  assert.match(projector, /self::ACTIVITY_TYPE\s*=\s*'pandatask_task'|const ACTIVITY_TYPE = 'pandatask_task'/);
  assert.match(projector, /'component'\s*=>\s*'groups'/);
  assert.match(projector, /'item_id'\s*=>\s*\$group_id/);
  assert.match(projector, /'secondary_item_id'\s*=>\s*\$task_id/);
  assert.match(projector, /WHERE component = 'groups'[\s\S]*type = %s[\s\S]*secondary_item_id = %d/);
  assert.match(projector, /new \\BP_Activity_Activity\( \$activity_id \)/);
  assert.match(projector, /\$activity->secondary_item_id = \$task_id/);
  assert.match(projector, /bp_activity_delete\( array\( 'id' => \$activity_id \) \)/);
});

test('task activity preserves creation time and only promotes meaningful lifecycle changes', () => {
  assert.match(projector, /const CREATED_META = 'pandatask_created_at'/);
  assert.match(projector, /bp_activity_update_meta\([\s\S]*self::CREATED_META/);
  assert.match(projector, /'date_recorded'\s*=>\s*\$promote[\s\S]*\? \$now[\s\S]*\$task->created_at/);
  assert.match(projector, /if \( \$promote \) \{[\s\S]*\$activity->date_recorded = \$now/);
  assert.match(projector, /pandatask_task_activity_hide_sitewide[\s\S]*true/);

  for (const field of [
    'status',
    'assignee_added',
    'assignee_removed',
    'supervisor_added',
    'supervisor_removed',
    'deadline',
    'board_name',
    'project_id',
  ]) {
    assert.match(projector, new RegExp(`'${field}'`));
  }
  assert.match(projector, /pandatask_task_activity_should_promote/);
  assert.doesNotMatch(
    projector.match(/\$promoting_fields = array\([\s\S]*?\);/)?.[0] || '',
    /'description'|'category_id'|'priority'|'attachment_type'|'recurrence_frequency'/,
  );
});

test('plain BuddyPress fallback remains readable and Network enhancement stays optional', () => {
  assert.match(projector, /class=\"pandatask-activity-card\"/);
  assert.match(projector, /data-pandatask-task-id/);
  assert.match(projector, /TaskBoardUrlResolver::resolve/);
  assert.match(projector, /View task/);
  assert.doesNotMatch(projector, /iarf_|IARF_/);
  assert.match(projector, /pandatask_task_activity_projected/);
});
