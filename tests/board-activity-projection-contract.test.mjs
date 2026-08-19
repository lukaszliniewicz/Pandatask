import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relative) => fs.readFileSync(path.join(repoRoot, relative), 'utf8');

const mutation = read('src/Application/Task/TaskMutationService.php');
const registrar = read('src/Integration/BuddyPress/BuddyPressRegistrar.php');
const projector = read('src/Integration/BuddyPress/BoardActivityProjector.php');
const eventRepository = read('src/Infrastructure/Persistence/BoardEventRepository.php');
const lifecycle = read('src/Infrastructure/Setup/DatabaseLifecycle.php');
const groupSettings = read('src/Integration/BuddyPress/GroupTasksExtension.php');
const routes = read('src/Http/Rest/V1/RouteRegistrar.php');

test('task lifecycle hooks remain post-commit extension points isolated from persistence', () => {
  assert.match(mutation, /dispatchLifecycleEvent\(\s*'pandatask_task_created'/);
  assert.match(mutation, /dispatchLifecycleEvent\(\s*'pandatask_task_changed'/);
  assert.match(mutation, /dispatchLifecycleEvent\(\s*'pandatask_task_deleted'/);
  assert.match(mutation, /private function dispatchLifecycleEvent[\s\S]*try[\s\S]*do_action\([\s\S]*catch \( Throwable/);
});

test('Pandatask owns one BuddyPress task-board activity per group rather than one per task', () => {
  assert.match(registrar, /new BoardActivityProjector\(\)/);
  assert.match(projector, /ACTIVITY_TYPE = 'pandatask_board_activity'/);
  assert.match(projector, /LEGACY_ACTIVITY_TYPE = 'pandatask_task'/);
  assert.match(projector, /'component'\s*=>\s*'groups'/);
  assert.match(projector, /'item_id'\s*=>\s*\$group_id/);
  assert.match(projector, /'secondary_item_id'\s*=>\s*0/);
  assert.match(projector, /WHERE component = 'groups'[\s\S]*type = %s[\s\S]*item_id = %d/);
  assert.doesNotMatch(projector, /secondary_item_id = %d/);
});

test('board events are durable, board-scoped and preserve task snapshots for moves and deletions', () => {
  assert.match(lifecycle, /board_events/);
  assert.match(lifecycle, /UNIQUE KEY source_activity/);
  assert.match(lifecycle, /KEY board_created \(board_name, created_at, id\)/);
  assert.match(eventRepository, /'board_name'\s*=>\s*\$board_name/);
  assert.match(eventRepository, /'task_name'\s*=>\s*\$task_name/);
  assert.match(eventRepository, /'event_data'/);
  assert.match(projector, /'task_moved_out'/);
  assert.match(projector, /'task_moved_in'/);
  assert.match(projector, /'task_deleted'/);
});

test('important changes promote the board widget while small edits remain non-promoting', () => {
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
  assert.match(projector, /pandatask_board_activity_should_promote/);
  assert.doesNotMatch(
    projector.match(/\$promoting_fields = array\([\s\S]*?\);/)?.[0] || '',
    /'description'|'category_id'|'priority'|'attachment_type'|'recurrence_frequency'/,
  );
});

test('legacy per-task activities are migrated idempotently into board events and removed', () => {
  assert.match(projector, /MIGRATION_OPTION = 'pandat69_board_activity_migration_v1'/);
  assert.match(projector, /hasSourceActivity/);
  assert.match(eventRepository, /source_activity_id/);
  assert.match(projector, /bp_activity_delete\( array\( 'id' => \(int\) \$activity->id \) \)/);
  assert.match(projector, /update_option\( self::MIGRATION_OPTION, '1', false \)/);
});

test('generic BuddyPress group settings expose task-board and feed-widget controls', () => {
  assert.match(groupSettings, /pandat69_tasks_enabled/);
  assert.match(groupSettings, /pandat69_task_activity_enabled/);
  assert.match(groupSettings, /pandat69_task_activity_preview_count/);
  assert.match(groupSettings, /Show a living task-board widget in the group activity feed/);
  assert.match(groupSettings, /pandatask_group_task_settings_updated/);
});

test('portable REST exposes permissioned board activity data for enhanced clients', () => {
  assert.match(routes, /\/boards\/\(\?P<board_name>\[\\w-\]\+\)\/activity/);
  assert.match(routes, /get_board_activity/);
  assert.match(routes, /check_board_read_permission/);
});
