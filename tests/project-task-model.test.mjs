import test from 'node:test';
import assert from 'node:assert/strict';
import { buildProjectTaskTree } from '../src/projectTaskModel.mjs';

test( 'project task tree nests active subtasks and preserves source order', () => {
	const model = buildProjectTaskTree( [
		{ id: 1, name: 'Parent', status: 'pending' },
		{ id: 2, name: 'First child', status: 'in-progress', parent_task_id: 1 },
		{ id: 3, name: 'Completed child', status: 'done', parent_task_id: 1 },
		{ id: 4, name: 'Second child', status: 'pending', parent_task_id: 1 },
	] );

	assert.equal( model.total, 3 );
	assert.deepEqual( model.roots.map( ( node ) => node.task.id ), [ 1 ] );
	assert.deepEqual(
		model.roots[ 0 ].children.map( ( node ) => node.task.id ),
		[ 2, 4 ]
	);
} );

test( 'project task tree keeps orphaned and cyclic tasks reachable', () => {
	const model = buildProjectTaskTree( [
		{ id: 5, name: 'Orphan', status: 'pending', parent_task_id: 99 },
		{ id: 6, name: 'Cycle A', status: 'pending', parent_task_id: 7 },
		{ id: 7, name: 'Cycle B', status: 'pending', parent_task_id: 6 },
	] );

	assert.equal( model.total, 3 );
	assert.deepEqual(
		model.roots.map( ( node ) => node.task.id ),
		[ 5, 6, 7 ]
	);
} );
