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
import {
    formatWorkDuration,
    getBoardLabel,
    getWorkAllocationLabel,
    getWorkEntryPresentation,
    normalizeWorkBreakdown,
} from '../src/workReportModel.mjs';
import {
    WORK_LOG_RANGE_PRESETS,
    workLogCsv,
    workLogRangeForPreset,
} from '../src/workLogUiModel.mjs';

test('personal work-log tab availability comes from the canonical board tab model', () => {
    assert.equal(isBoardTabAvailable('work', true), true);
    assert.equal(isBoardTabAvailable('work', false), false);
    assert.equal(isBoardTabAvailable('work', true, { workLogEnabled: false }), false);
    assert.deepEqual(
        getBoardTabs(true)
            .filter(tab => tab.id === 'work')
            .map(tab => tab.label),
        ['Work Log']
    );
});

test('compact work-log range presets produce inclusive local-date ranges', () => {
    const now = new Date(2026, 7, 25, 12);
    assert.deepEqual(workLogRangeForPreset('last_7_days', now), {
        startDate: '2026-08-19',
        endDate: '2026-08-25',
    });
    assert.deepEqual(workLogRangeForPreset('this_week', now), {
        startDate: '2026-08-24',
        endDate: '2026-08-25',
    });
    assert.deepEqual(workLogRangeForPreset('this_month', now), {
        startDate: '2026-08-01',
        endDate: '2026-08-25',
    });
    assert.ok(WORK_LOG_RANGE_PRESETS.some(option => option.value === 'custom'));
});

test('work-log CSV is Excel-friendly and escapes spreadsheet cells', () => {
    assert.equal(
        workLogCsv([
            ['Title', 'Notes', 'Formula'],
            ['Development', 'Quoted "detail"', '=IMPORTXML("bad")'],
        ]),
        '\uFEFF"Title","Notes","Formula"\n"Development","Quoted ""detail""","\'=IMPORTXML(""bad"")"'
    );
});

test('work reports replace raw keys and merge rows with the same useful label', () => {
    const rows = normalizeWorkBreakdown(
        [
            {
                activity_type: 'deep_work',
                kind: 'entry',
                duration_seconds: 1800,
            },
            {
                activity_type: 'deep_work',
                kind: 'entry',
                duration_seconds: 900,
            },
            { activity_type: null, kind: 'residual', duration_seconds: 3600 },
        ],
        {
            dimension: 'activity',
            activityTypes: [{ key: 'deep_work', label: 'Deep work' }],
        }
    );
    assert.deepEqual(rows, [{ label: 'Deep work', duration_seconds: 2700 }]);
    assert.equal(formatWorkDuration(2700), '45m');
});

test('generated other task time is excluded from work-type and capacity classifications', () => {
    const rows = [
        { name: null, kind: 'residual', duration_seconds: 3600 },
        { name: null, kind: 'manual', duration_seconds: 1800 },
    ];
    assert.deepEqual(normalizeWorkBreakdown(rows, { dimension: 'capacity' }), [
        { label: 'Not specified', duration_seconds: 1800 },
    ]);
});

test('residual entries present their task as primary context and offer refinement data', () => {
    const presentation = getWorkEntryPresentation({
        kind: 'residual',
        title: 'Unitemised task time',
        allocations: [{ task_id_snapshot: 42, task_name_snapshot: 'Funding proposal' }],
    });
    assert.deepEqual(presentation, {
        isResidual: true,
        title: 'Funding proposal',
        typeLabel: 'Other task time',
        contextLabel: 'Funding proposal',
        task: { id: 42, name: 'Funding proposal' },
    });
});

test('board report labels prefer user-facing board names over storage keys', () => {
    assert.equal(getBoardLabel('group_10', [{ id: 'group_10', name: 'Test group' }]), 'Test group');
    assert.equal(getBoardLabel('user_8'), 'Private tasks');
    assert.equal(
        getWorkAllocationLabel({ board_name_snapshot: 'group_10' }, [{ id: 'group_10', name: 'Test group' }]),
        'Test group'
    );
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
    assert.deepEqual(buildAllocationPayload([{ taskId: 10, minutes: 15, residualHandling: 'refine_residual' }]), [
        { task_id: 10, seconds: 900, residual_handling: 'refine_residual' },
    ]);
});

test('work allocation model supports board-only and mixed task/board allocations', () => {
    const allocations = [
        { targetType: 'task', taskId: 10, minutes: 30, residualHandling: '' },
        {
            targetType: 'board',
            boardName: 'group_10',
            minutes: 45,
            residualHandling: '',
        },
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
    assert.equal(buildSuggestionAllocationOverride(3600, [{ board_name: 'group_10', seconds: 3600 }], []), null);
});

test('board-only allocations render as boards rather than fake tasks', () => {
    assert.equal(workAllocationTargetLabel({ task_name_snapshot: 'Research report' }), 'Research report');
    assert.equal(workAllocationTargetLabel({ board_name_snapshot: 'group_10' }), 'Group board');
    assert.equal(workAllocationTargetLabel({ board_name_snapshot: 'user_8' }), 'Private board');
    assert.equal(workAllocationTargetLabel({ board_name_snapshot: 'project_alpha' }), 'project_alpha');
});
