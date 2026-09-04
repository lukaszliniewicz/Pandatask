import assert from 'node:assert/strict';
import test from 'node:test';
import {
	buildProjectFlowModel,
	getProjectTaskGroups,
	toProjectVisualTasks,
} from '../src/projectWorkspaceModel.mjs';

const tasks = [
	{
		workspace_key: 'task-1',
		task_id: 1,
		name: 'Native',
		origin: 'native',
		status: 'pending',
		visible_in_visuals: true,
		predecessor_keys: [],
	},
	{
		workspace_key: 'task-2',
		task_id: 2,
		name: 'External blocker',
		origin: 'external',
		status: 'done',
		visible_in_visuals: true,
		predecessor_keys: [],
	},
	{
		workspace_key: 'task-3',
		task_id: 3,
		name: 'Related note',
		origin: 'external',
		status: 'pending',
		visible_in_visuals: false,
		predecessor_keys: [],
	},
];

test( 'project workspace groups native, visual external, and related tasks', () => {
	const groups = getProjectTaskGroups( tasks );
	assert.deepEqual( groups.native.map( ( task ) => task.task_id ), [ 1 ] );
	assert.deepEqual( groups.external.map( ( task ) => task.task_id ), [ 2 ] );
	assert.deepEqual( groups.related.map( ( task ) => task.task_id ), [ 3 ] );
} );

test( 'visual tasks use workspace keys without losing canonical navigation IDs', () => {
	const visual = toProjectVisualTasks( tasks );
	assert.deepEqual( visual.map( ( task ) => task.id ), [ 'task-1', 'task-2' ] );
	assert.deepEqual(
		visual.map( ( task ) => task.canonical_task_id ),
		[ 1, 2 ]
	);
} );

test( 'flow layout keeps a completed dependency as context for open work', () => {
	const model = buildProjectFlowModel(
		tasks,
		[
			{
				relationship_id: 9,
				predecessor_key: 'task-2',
				successor_key: 'task-1',
			},
		],
		'open'
	);
	assert.equal( model.nodes.length, 2 );
	assert.equal( model.edges.length, 1 );
	assert.ok(
		model.nodes.find( ( node ) => node.key === 'task-2' ).x <
			model.nodes.find( ( node ) => node.key === 'task-1' ).x
	);
} );
