import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildAllocationPayload,
    buildSuggestionAllocationOverride,
    minutesToSeconds,
    summarizeAllocationDrafts,
    validateAllocationDrafts,
    workAllocationTargetLabel,
} from '../src/workLogModel.mjs';
import { getBoardTabs, isBoardTabAvailable } from '../src/boardTabs.mjs';

test('personal work-log tab availability comes from the canonical board tab model', () => {
    assert.equal(isBoardTabAvailable('work', true), true);
    assert.equal(isBoardTabAvailable('work', false), false);
    assert.deepEqual(getBoardTabs(true).filter((tab) => tab.id === 'work').map((tab) => tab.label), ['Work Log']);
});

test('split allocations preserve one entry duration and expose unallocated remainder', () => {
    const allocations = [
        { taskId: 10, minutes: 60, residualHandling: '' },
        { taskId: 11, minutes: 30, residualHandling: '' },
    ];
    assert.equal(minutesToSeconds(90), 5400);
    assert.deepEqual(summarizeAllocationDrafts(90, allocations), {
        totalMinutes: 90,
        allocatedMinutes: 90,
        remainingMinutes: 0,
        overallocatedMinutes: 0,
    });
    assert.equal(validateAllocationDrafts(5400, allocations), '');
    assert.deepEqual(buildAllocationPayload(allocations), [
        { task_id: 10, seconds: 3600 },
        { task_id: 11, seconds: 1800 },
    ]);
});

test('work allocation model allows an explicit unallocated remainder', () => {
    const allocations = [{ taskId: 10, minutes: 60, residualHandling: '' }];
    assert.deepEqual(summarizeAllocationDrafts(90, allocations), {
        totalMinutes: 90,
        allocatedMinutes: 60,
        remainingMinutes: 30,
        overallocatedMinutes: 0,
    });
    assert.equal(validateAllocationDrafts(5400, allocations), '');
});

test('work allocation model rejects duplicate and excessive allocations', () => {
    assert.match(
        validateAllocationDrafts(3600, [
            { taskId: 10, minutes: 30 },
            { taskId: 10, minutes: 30 },
        ]),
        /only once/i
    );
    assert.match(
        validateAllocationDrafts(3600, [
            { taskId: 10, minutes: 45 },
            { taskId: 11, minutes: 30 },
        ]),
        /cannot exceed/i
    );
});

test('residual handling is preserved in the allocation payload', () => {
    assert.deepEqual(buildAllocationPayload([
        { taskId: 10, minutes: 15, residualHandling: 'refine_residual' },
    ]), [
        { task_id: 10, seconds: 900, residual_handling: 'refine_residual' },
    ]);
});

test('work allocation model supports board-only and mixed task/board allocations', () => {
    const allocations = [
        { targetType: 'task', taskId: 10, minutes: 30, residualHandling: '' },
        { targetType: 'board', boardName: 'group_10', minutes: 45, residualHandling: '' },
    ];
    assert.equal(validateAllocationDrafts(5400, allocations), '');
    assert.deepEqual(buildAllocationPayload(allocations), [
        { task_id: 10, seconds: 1800 },
        { board_name: 'group_10', seconds: 2700 },
    ]);
    assert.match(
        validateAllocationDrafts(5400, [
            { targetType: 'board', boardName: 'group_10', minutes: 30 },
            { targetType: 'board', boardName: 'group_10', minutes: 30 },
        ]),
        /only once/i
    );
});

test('suggestion adjustment keeps provider board time as the task-allocation remainder', () => {
    assert.deepEqual(
        buildSuggestionAllocationOverride(
            3600,
            [{ board_name: 'group_10', seconds: 3600 }],
            [{ task_id: 42, seconds: 1500 }]
        ),
        [
            { task_id: 42, seconds: 1500 },
            { board_name: 'group_10', seconds: 2100 },
        ]
    );
});

test('suggestion adjustment preserves provider defaults when no task allocation is supplied', () => {
    assert.equal(
        buildSuggestionAllocationOverride(
            3600,
            [{ board_name: 'group_10', seconds: 3600 }],
            []
        ),
        null
    );
});

test('board-only allocations render as boards rather than fake tasks', () => {
    assert.equal(workAllocationTargetLabel({ task_name_snapshot: 'Research report' }), 'Research report');
    assert.equal(workAllocationTargetLabel({ board_name_snapshot: 'group_10' }), 'Group board');
    assert.equal(workAllocationTargetLabel({ board_name_snapshot: 'user_8' }), 'Private board');
    assert.equal(workAllocationTargetLabel({ board_name_snapshot: 'project_alpha' }), 'project_alpha');
});
