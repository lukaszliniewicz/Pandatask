import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { getBoardTabs } from '../src/boardTabs.mjs';
import { serializeCompletionWorkItems } from '../src/components/work/completionWorkModel.mjs';
import {
  flattenInboxPages,
  getInboxNextPageParam,
} from '../src/inboxModel.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = relativePath => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('personal Inbox is a dedicated user-board surface, not a duplicate task list', () => {
  assert.equal(getBoardTabs(false).some(tab => tab.id === 'inbox'), false);
  assert.equal(getBoardTabs(true).some(tab => tab.id === 'inbox'), true);
  const repository = read('src/Infrastructure/Persistence/TaskRepository.php');
  assert.match(repository, /AND t\.inbox_state IS NULL/);
  assert.match(repository, /findInboxForOwner/);
  assert.match(repository, /'any'/);
});

test('Inbox provenance and lifecycle lineage are protected behind dedicated APIs', () => {
  const schema = read('src/Http/Rest/V1/Support/SchemaProvider.php');
  const normalizer = read('src/Http/Rest/V1/Support/TaskInputNormalizer.php');
  for (const field of ['inbox_state', 'capture_source', 'capture_url', 'follow_up_of_task_id']) {
    assert.doesNotMatch(schema, new RegExp(`['\"]${field}['\"]\\s*=>`));
    assert.doesNotMatch(normalizer, new RegExp(`array_key_exists\\( ['\"]${field}['\"]`));
  }
  const inboxRoutes = read('src/Http/Rest/V1/InboxRouteRegistrar.php');
  const inboxService = read('src/Application/Task/InboxService.php');
  const lifecycleRoutes = read('src/Http/Rest/V1/TaskLifecycleRouteRegistrar.php');
  assert.match(inboxRoutes, /\/users\/me\/inbox/);
  assert.match(inboxRoutes, /inbox-state/);
  assert.match(inboxService, /createTask\(\s*\$data,\s*array\(\s*'actor_id'\s*=>\s*\(int\) \$actor_id,\s*'creator_id'\s*=>\s*\(int\) \$owner_user_id/s);
  assert.match(lifecycleRoutes, /move-preview/);
  assert.match(lifecycleRoutes, /\/reopen/);
  assert.match(lifecycleRoutes, /follow-ups/);
});

test('cross-board move is previewable, preserves identity, and refreshes only open occurrence snapshots', () => {
  const moveService = read('src/Application/Task/TaskMoveService.php');
  const mutation = read('src/Application/Task/TaskMutationService.php');
  const occurrence = read('src/Infrastructure/Persistence/WorkOccurrenceRepository.php');
  assert.match(moveService, /strict.*reset_incompatible/s);
  assert.match(moveService, /'task_id', 'history', 'comments', 'attachments', 'work'/);
  assert.match(moveService, /canWriteBoard/);
  assert.match(moveService, /move_or_detach_subtasks_first/);
  assert.match(mutation, /refreshOpenSnapshot/);
  assert.match(occurrence, /array\( 'id' => \(int\) \$occurrence_id, 'state' => 'open' \)/);
});

test('reopen, follow-up task, and post-completion work remain distinct lifecycle operations', () => {
  const mutation = read('src/Application/Task/TaskMutationService.php');
  const lifecycle = read('src/Http/Rest/V1/TaskLifecycleRouteHandler.php');
  const work = read('src/Application/Work/WorkEntryService.php');
  assert.match(mutation, /pandatask_reopen_required/);
  assert.match(mutation, /pandatask_completion_required/);
  assert.match(mutation, /'reopen' === \$lifecycle_operation/);
  assert.match(mutation, /nextSequence/);
  assert.match(lifecycle, /follow_up_created/);
  assert.match(lifecycle, /follow_up_of_task_id/);
  const taskService = read('src/Application/Task/TaskService.php');
  assert.match(taskService, /protectFollowUpSourceForViewer/);
  assert.match(taskService, /follow_up_source_restricted/);
  assert.match(taskService, /follow_up_of_task_name = null/);
  assert.match(work, /post_completion_requires_done_task/);
  assert.match(work, /'occurrence_id'\s*=>\s*\$occurrence && 'post_completion' !== \$allocation_context \? \(int\) \$occurrence->id : null/);
});

test('itemised completion refuses to exceed the still-unlogged actual time', () => {
  const good = serializeCompletionWorkItems(
    [{ minutes: 30, activity_type: 'research', capacity: 'paid' }],
    1800,
  );
  assert.deepEqual(good, [{ duration_seconds: 1800, activity_type: 'research', capacity: 'paid' }]);
  assert.throws(
    () => serializeCompletionWorkItems([{ minutes: 31, activity_type: 'research' }], 1800),
    /cannot exceed/i,
  );
  assert.throws(
    () => serializeCompletionWorkItems([{ minutes: 15, activity_type: '' }], 1800),
    /work type/i,
  );
  const timeService = read('src/Application/Work/TaskTimeService.php');
  assert.match(timeService, /pandatask_itemised_time_exceeds_remaining/);
  assert.match(timeService, /completion_item_created/);
});

test('database schema persists Inbox delegation, follow-up lineage, and work allocation context', () => {
  const lifecycle = read('src/Infrastructure/Setup/DatabaseLifecycle.php');
  assert.match(lifecycle, /DB_VERSION = '1\.0\.20'/);
  assert.match(lifecycle, /follow_up_of_task_id/);
  assert.match(lifecycle, /inbox_delegates/);
  assert.match(lifecycle, /allocation_context VARCHAR\(32\) NOT NULL DEFAULT 'occurrence'/);
  assert.match(lifecycle, /board_inbox_created/);
});

test('status=all is explicit across MCP and REST while omitted board status remains actionable-only', () => {
  const schemas = read('mcp-server/src/schemas.ts');
  const handler = read('src/Http/Rest/V1/TaskRouteHandler.php');
  assert.match(schemas, /status_filter: input\.status/);
  assert.match(handler, /\$status_filter\s*=\s*\$params\['status_filter'\] \?\? 'pending_in-progress'/);
  assert.match(handler, /if \( 'all' === \$status_filter \) \{\s*\$status_filter = '';/s);
});

test('browser extension remains a thin generic capture client', () => {
  const manifest = JSON.parse(read('browser-extension/manifest.json'));
  const popup = read('browser-extension/popup.js');
  assert.equal(manifest.manifest_version, 3);
  assert.ok(manifest.permissions.includes('storage'));
  assert.match(popup, /\/users\/me\/inbox/);
  assert.match(popup, /\/users\/me\/boards/);
  assert.match(popup, /\/boards\/\$\{encodeURIComponent\(destination\.value\)\}\/tasks/);
  assert.doesNotMatch(popup, /follow_up_of_task_id|inbox_state|allocation_context/);
});

test('Inbox pages flatten and follow server pagination metadata', () => {
  assert.deepEqual(
    flattenInboxPages([
      { tasks: [{ id: 1 }, { id: 2 }] },
      { tasks: [] },
      { tasks: [{ id: 3 }] },
    ]),
    [{ id: 1 }, { id: 2 }, { id: 3 }],
  );
  assert.equal(
    getInboxNextPageParam({
      pagination: { has_more: true, next_offset: 200 },
    }),
    200,
  );
  assert.equal(
    getInboxNextPageParam({
      pagination: { has_more: false, next_offset: null },
    }),
    undefined,
  );
  assert.equal(
    getInboxNextPageParam({
      pagination: { has_more: true, next_offset: null },
    }),
    undefined,
  );
});

test('Inbox UI wires paginated reads and visible triage failures', () => {
  const hook = read('src/hooks/useInbox.js');
  const view = read('src/components/InboxView.jsx');
  assert.match(hook, /useInfiniteQuery/);
  assert.match(hook, /getNextPageParam: getInboxNextPageParam/);
  assert.match(hook, /offset: pageParam/);
  assert.match(view, /fetchNextPage/);
  assert.match(view, /setState\.mutateAsync/);
  assert.match(view, /Could not update Inbox item\./);
});
