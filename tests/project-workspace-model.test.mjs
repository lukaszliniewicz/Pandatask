import assert from 'node:assert/strict';
import test from 'node:test';
import {
	buildProjectFlowFocus,
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
	assert.deepEqual(
		groups.native.map( ( task ) => task.task_id ),
		[ 1 ]
	);
	assert.deepEqual(
		groups.external.map( ( task ) => task.task_id ),
		[ 2 ]
	);
	assert.deepEqual(
		groups.related.map( ( task ) => task.task_id ),
		[ 3 ]
	);
} );

test( 'visual tasks use workspace keys without losing canonical navigation IDs', () => {
	const visual = toProjectVisualTasks( tasks );
	assert.deepEqual(
		visual.map( ( task ) => task.id ),
		[ 'task-1', 'task-2' ]
	);
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

test( 'flow layout nests descendants in their root workstream without losing task anchors', () => {
	const hierarchyTasks = [
		{
			workspace_key: 'root',
			name: 'Root workstream',
			origin: 'native',
			status: 'done',
			visible_in_visuals: true,
		},
		{
			workspace_key: 'child',
			parent_workspace_key: 'root',
			name: 'Child milestone',
			origin: 'native',
			status: 'pending',
			visible_in_visuals: true,
		},
		{
			workspace_key: 'grandchild',
			parent_workspace_key: 'child',
			name: 'Grandchild action',
			origin: 'native',
			status: 'pending',
			visible_in_visuals: true,
		},
		{
			workspace_key: 'other',
			name: 'Other workstream',
			origin: 'native',
			status: 'pending',
			visible_in_visuals: true,
		},
	];
	const model = buildProjectFlowModel(
		hierarchyTasks,
		[
			{
				predecessor_key: 'grandchild',
				successor_key: 'other',
			},
		],
		'open'
	);

	assert.equal( model.groups.length, 2 );
	const rootGroup = model.groups.find( ( group ) => group.key === 'root' );
	assert.deepEqual(
		rootGroup.members.map( ( node ) => [ node.key, node.depth ] ),
		[
			[ 'root', 0 ],
			[ 'child', 1 ],
			[ 'grandchild', 2 ],
		]
	);
	assert.equal(
		model.nodes.find( ( node ) => node.key === 'grandchild' ).groupKey,
		'root'
	);
	assert.equal( model.edges[ 0 ].from, 'grandchild' );
	assert.equal( model.edges[ 0 ].to, 'other' );
	assert.ok(
		model.nodes.find( ( node ) => node.key === 'grandchild' ).y >
			model.nodes.find( ( node ) => node.key === 'root' ).y
	);
} );

test( 'relation focus includes a selected branch, its ancestors, and direct dependencies', () => {
	const hierarchyTasks = [
		{
			workspace_key: 'root',
			name: 'Root',
			origin: 'native',
			status: 'pending',
			visible_in_visuals: true,
		},
		{
			workspace_key: 'child',
			parent_workspace_key: 'root',
			name: 'Child',
			origin: 'native',
			status: 'pending',
			visible_in_visuals: true,
		},
		{
			workspace_key: 'grandchild',
			parent_workspace_key: 'child',
			name: 'Grandchild',
			origin: 'native',
			status: 'pending',
			visible_in_visuals: true,
		},
		{
			workspace_key: 'dependency',
			name: 'Dependency',
			origin: 'external',
			status: 'pending',
			visible_in_visuals: true,
		},
	];
	const model = buildProjectFlowModel( hierarchyTasks, [
		{
			predecessor_key: 'dependency',
			successor_key: 'child',
		},
	] );
	const focus = buildProjectFlowFocus( model, 'child' );

	assert.deepEqual( [ ...focus.taskKeys ].sort(), [
		'child',
		'dependency',
		'grandchild',
		'root',
	] );
	assert.deepEqual( [ ...focus.edgeIds ], [ 'dependency:dependency:child' ] );
} );
