import test from 'node:test';
import assert from 'node:assert/strict';
import {
	buildTaskPayload,
	createTaskFormDefaults,
	requiresTaskChangeReason,
	validationErrorTab,
} from '../src/taskFormModel.mjs';

test( 'personal-board defaults assign a new task to the current user', () => {
	const defaults = createTaskFormDefaults( {
		boardName: 'user_42',
		currentUser: { id: '42' },
		defaultValues: {
			description: 'Prepared description',
			project_id: 9,
			target_board: 'group_delivery',
		},
	} );

	assert.deepEqual( defaults.assigned_persons, [ 42 ] );
	assert.equal( defaults.description, 'Prepared description' );
	assert.equal( defaults.project_id, 9 );
	assert.equal( defaults.target_board, 'group_delivery' );
	assert.equal( defaults.schedule_mode, 'fixed' );
} );

test( 'existing task values take precedence and normalize relationship IDs', () => {
	const defaults = createTaskFormDefaults( {
		boardName: 'group_fallback',
		task: {
			assigned_user_ids: [ '7', 8 ],
			board_name: 'group_actual',
			deadline_days_after_start: 3,
			is_recurring: 1,
			recurrence_days: '1,3,5',
			recurrence_frequency: 'weekly',
			recurrence_interval: 2,
			supervisor_user_ids: [ '9' ],
		},
	} );

	assert.deepEqual( defaults.assigned_persons, [ 7, 8 ] );
	assert.deepEqual( defaults.supervisor_persons, [ 9 ] );
	assert.deepEqual( defaults.recurrence_days, [ '1', '3', '5' ] );
	assert.equal( defaults.recurrence_frequency, 'bi-weekly' );
	assert.equal( defaults.schedule_mode, 'dynamic' );
	assert.equal( defaults.target_board, 'group_actual' );
} );

test( 'dynamic dependent-task payload removes conflicting fixed dates', () => {
	const payload = buildTaskPayload(
		{
			name: 'Dependent task',
			status: 'in-progress',
			schedule_mode: 'dynamic',
			start_date: '2026-08-01',
			deadline: '2026-08-20',
			deadline_days_after_start: 4,
			predecessors: [ 3 ],
			is_recurring: false,
			notify_deadline: true,
			recurrence_days: [ '1', '5' ],
			parent_task_id: '12',
			target_board: 'group_delivery',
			attachment: {
				type: 'link',
				url: 'https://example.test/spec',
				id: '',
				filename: 'Specification',
			},
		},
		{
			boardName: 'user_42',
			isUserBoard: true,
			isEdit: false,
		}
	);

	assert.equal( payload.board_name, 'group_delivery' );
	assert.equal( payload.status, 'pending' );
	assert.equal( payload.start_date, '' );
	assert.equal( payload.deadline, '' );
	assert.equal( payload.parent_task_id, 12 );
	assert.equal( payload.is_recurring, 0 );
	assert.equal( payload.notify_deadline, 1 );
	assert.equal( payload.recurrence_days, '1,5' );
	assert.equal( payload.attachment_type, 'link' );
	assert.equal( payload.attachment_url, 'https://example.test/spec' );
	assert.equal( 'schedule_mode' in payload, false );
	assert.equal( 'target_board' in payload, false );
	assert.equal( 'attachment' in payload, false );
} );

test( 'fixed payload keeps the owning task board and records a change reason', () => {
	const payload = buildTaskPayload(
		{
			status: 'done',
			schedule_mode: 'fixed',
			deadline: '2026-09-01',
			deadline_days_after_start: 8,
			is_recurring: true,
			notify_deadline: false,
			recurrence_days: [],
			target_board: 'ignored_board',
		},
		{
			boardName: 'group_default',
			isUserBoard: false,
			isEdit: true,
			task: { board_name: 'group_owner' },
			changeComment: 'Finished during the review.',
		}
	);

	assert.equal( payload.board_name, 'group_owner' );
	assert.equal( payload.deadline_days_after_start, '' );
	assert.equal( payload.is_recurring, 1 );
	assert.equal( payload.notify_deadline, 0 );
	assert.equal( payload.change_comment, 'Finished during the review.' );
} );

test( 'reason and validation helpers identify sensitive changes and tabs', () => {
	const task = { status: 'pending', deadline: '2026-08-01' };

	assert.equal(
		requiresTaskChangeReason( task, {
			status: 'in-progress',
			schedule_mode: 'dynamic',
		} ),
		true
	);
	assert.equal(
		requiresTaskChangeReason( task, {
			status: 'pending',
			schedule_mode: 'fixed',
			deadline: '2026-08-02',
		} ),
		true
	);
	assert.equal(
		requiresTaskChangeReason( task, {
			status: 'pending',
			schedule_mode: 'fixed',
			deadline: '2026-08-01',
		} ),
		false
	);
	assert.equal( validationErrorTab( { name: {} } ), 'general' );
	assert.equal(
		validationErrorTab( { deadline_days_after_start: {} } ),
		'schedule'
	);
	assert.equal( validationErrorTab( {} ), null );
} );
