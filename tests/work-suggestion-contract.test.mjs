import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relativePath) => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('PandaTask exposes an optional generic work suggestion provider boundary', () => {
    const plugin = read('pandatask.php');
    const registry = read('src/Application/Work/WorkSuggestionProviderRegistry.php');

    assert.match(plugin, /function pandatask_register_work_suggestion_provider/);
    assert.match(registry, /list_callback/);
    assert.match(registry, /resolve_callback/);
    assert.match(registry, /private static \$providers/);
});

test('suggestions stay outside work accounting until explicitly confirmed', () => {
    const lifecycle = read('src/Infrastructure/Setup/DatabaseLifecycle.php');
    const service = read('src/Application/Work/WorkSuggestionService.php');
    const report = read('src/Infrastructure/Persistence/WorkReportRepository.php');

    assert.match(lifecycle, /work_suggestion_decisions/);
    assert.match(lifecycle, /UNIQUE KEY user_provider_external/);
    assert.doesNotMatch(report, /work_suggestion_decisions/);
    assert.match(service, /createSourcedEntry/);
    assert.match(service, /'confirmed'/);
    assert.match(service, /'dismissed'/);
    assert.match(service, /has_allocation_override/);
    assert.match(service, /modify\( '\+' \. \$duration \. ' seconds' \)/);
});

test('confirmed provider work uses trusted provenance and idempotency', () => {
    const work = read('src/Application/Work/WorkEntryService.php');
    const suggestions = read('src/Application/Work/WorkSuggestionService.php');

    assert.match(work, /function createSourcedEntry/);
    assert.match(work, /findBySourceKey/);
    assert.match(suggestions, /hash\( 'sha256', \(string\) \$external_key \)/);
    assert.doesNotMatch(work, /\$input\['source_key'\]/);
});

test('work allocations can target a board without inventing a task', () => {
    const work = read('src/Application/Work/WorkEntryService.php');

    assert.match(work, /\$board_name = sanitize_key/);
    assert.match(work, /canReadBoard\( \$board_name, \$actor_id \)/);
    assert.match(work, /'task_id_snapshot'\s*=> null/);
    assert.match(work, /'board_name_snapshot'\s*=> \$board_name/);
});

test('Work Log presents quiet confirmation actions rather than notifications', () => {
    const panel = read('src/components/work/WorkSuggestionsPanel.jsx');
    const hooks = read('src/hooks/useWorkLog.js');

    assert.match(panel, /Needs confirmation/);
    assert.match(panel, /Nothing here counts toward your totals until you confirm it/);
    assert.match(panel, /Confirm[\s\S]*formatDuration/);
    assert.match(panel, /Adjust/);
    assert.match(panel, /<WorkEntryForm/);
    assert.match(panel, /initialValues=\{\s*suggestion\s*\}/);
    assert.match(panel, /onSubmitOverride/);
    assert.match(panel, /buildSuggestionAllocationOverride/);
    assert.match(panel, /Didn't attend/);
    assert.match(hooks, /users\/me\/work-suggestions\/confirm/);
    assert.match(hooks, /users\/me\/work-suggestions\/dismiss/);
});
