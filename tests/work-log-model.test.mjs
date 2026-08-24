import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildAllocationPayload,
    minutesToSeconds,
    summarizeAllocationDrafts,
    validateAllocationDrafts,
} from '../src/workLogModel.mjs';

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
