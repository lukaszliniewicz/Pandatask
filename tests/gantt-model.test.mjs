import test from 'node:test';
import assert from 'node:assert/strict';
import {
	buildGanttModel,
	formatGanttDate,
	getGanttTaskSet,
	parseGanttDate,
	pickGanttFocusDate,
} from '../src/ganttModel.mjs';

const rowById = ( model, id ) =>
	model.rows.find( ( row ) => row.id === String( id ) );

test( 'subtask order never creates implicit dependencies', () => {
	const model = buildGanttModel( [
		{
			id: 1,
			name: 'Parent',
			start_date: '2026-08-01',
			deadline: '2026-08-10',
		},
		{
			id: 2,
			parent_task_id: 1,
			name: 'First child',
			start_date: '2026-08-02',
			deadline: '2026-08-03',
		},
		{
			id: 3,
			parent_task_id: 1,
			name: 'Second child',
			start_date: '2026-08-04',
			deadline: '2026-08-05',
		},
	] );

	assert.deepEqual( model.edges, [] );
	assert.deepEqual(
		model.rows.map( ( row ) => [ row.id, row.depth ] ),
		[
			[ '1', 0 ],
			[ '2', 1 ],
			[ '3', 1 ],
		]
	);
} );

test( 'a parent display range is the union of its own dates and descendants', () => {
	const model = buildGanttModel( [
		{
			id: 1,
			name: 'Parent',
			start_date: '2026-08-05',
			deadline: '2026-08-20',
		},
		{
			id: 2,
			parent_task_id: 1,
			name: 'Early child',
			start_date: '2026-08-01',
			deadline: '2026-08-03',
		},
		{
			id: 3,
			parent_task_id: 1,
			name: 'Late child',
			start_date: '2026-08-12',
			deadline: '2026-08-15',
		},
	] );
	const parent = rowById( model, 1 );

	assert.equal( formatGanttDate( parent.effectiveStart ), '2026-08-01' );
	assert.equal( formatGanttDate( parent.effectiveEnd ), '2026-08-20' );
	assert.equal( parent.scheduleKind, 'parent-summary' );
	assert.ok(
		parent.warnings.some(
			( warning ) => warning.code === 'child-outside-parent'
		)
	);
} );

test( 'undated work remains explicitly unscheduled', () => {
	const model = buildGanttModel( [
		{ id: 1, name: 'No dates' },
		{ id: 2, name: 'Deadline only', deadline: '2026-08-07' },
		{
			id: 3,
			name: 'Legacy zero dates',
			start_date: '0000-00-00',
			deadline: '0000-00-00',
		},
	] );

	assert.deepEqual(
		model.unscheduledRows.map( ( row ) => row.id ).sort(),
		[ '1', '3' ]
	);
	assert.equal( rowById( model, 2 ).scheduleKind, 'deadline-only' );
} );

test( 'only explicit predecessor links produce arrows and overlap warnings', () => {
	const model = buildGanttModel( [
		{
			id: 1,
			name: 'Predecessor',
			start_date: '2026-08-01',
			deadline: '2026-08-10',
			status: 'in-progress',
		},
		{
			id: 2,
			name: 'Successor',
			start_date: '2026-08-08',
			deadline: '2026-08-12',
			predecessor_ids: [ 1 ],
		},
		{
			id: 3,
			name: 'Unrelated task',
			start_date: '2026-08-02',
			deadline: '2026-08-03',
		},
	] );

	assert.equal( model.edges.length, 1 );
	assert.deepEqual(
		{
			from: model.edges[ 0 ].from,
			to: model.edges[ 0 ].to,
			hasConflict: model.edges[ 0 ].hasConflict,
		},
		{ from: '1', to: '2', hasConflict: true }
	);
	assert.ok(
		rowById( model, 2 ).warnings.some(
			( warning ) => warning.code === 'dependency-overlap'
		)
	);
} );

test( 'completed predecessors remain as context when completed work is hidden', () => {
	const tasks = [
		{ id: 1, name: 'Completed predecessor', status: 'done' },
		{
			id: 2,
			name: 'Active successor',
			status: 'pending',
			predecessor_ids: [ 1 ],
		},
		{ id: 3, name: 'Unrelated completed task', status: 'done' },
	];
	const visible = getGanttTaskSet( tasks, false );

	assert.deepEqual(
		visible.map( ( task ) => task.id ),
		[ 1, 2 ]
	);
	assert.equal( visible[ 0 ].is_gantt_context, true );
	assert.equal( getGanttTaskSet( tasks, true ).length, 3 );
} );

test( 'completed parent tasks remain as hierarchy context', () => {
	const visible = getGanttTaskSet(
		[
			{ id: 1, name: 'Completed parent', status: 'done' },
			{
				id: 2,
				parent_task_id: 1,
				name: 'Active child',
				status: 'in-progress',
			},
		],
		false
	);

	assert.deepEqual(
		visible.map( ( task ) => task.id ),
		[ 1, 2 ]
	);
	assert.equal( visible[ 0 ].is_gantt_context, true );
} );

test( 'initial focus prefers a useful nearby cluster over a lone nearer task', () => {
	const model = buildGanttModel( [
		{
			id: 1,
			name: 'Cluster one',
			start_date: '2026-03-12',
			deadline: '2026-03-12',
		},
		{
			id: 2,
			name: 'Cluster two',
			start_date: '2026-03-14',
			deadline: '2026-03-14',
		},
		{
			id: 3,
			name: 'Cluster three',
			start_date: '2026-03-20',
			deadline: '2026-03-20',
		},
		{
			id: 4,
			name: 'Lone nearer task',
			start_date: '2026-05-30',
			deadline: '2026-05-30',
		},
	] );
	const focusDate = pickGanttFocusDate(
		model.scheduledRows,
		parseGanttDate( '2026-07-29' )
	);

	assert.equal( formatGanttDate( focusDate ), '2026-03-20' );
} );
