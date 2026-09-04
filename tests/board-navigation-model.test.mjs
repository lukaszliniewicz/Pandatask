import assert from 'node:assert/strict';
import test from 'node:test';
import {
	normalizeProjectSelection,
	projectSelectionQueryValue,
	readBoardNavigationSearch,
} from '../src/boardNavigationModel.mjs';

test( 'reads canonical task and project deep links', () => {
	assert.deepEqual(
		readBoardNavigationSearch(
			'?pandatask_project=12&open_task=484&pandatask_view=gantt'
		),
		{
			currentTab: 'tasks',
			currentView: 'gantt',
			selectedProjectId: 12,
			selectedTaskId: 484,
		}
	);
} );

test( 'normalizes invalid and special project selections safely', () => {
	assert.equal( normalizeProjectSelection( 'none' ), 'none' );
	assert.equal( normalizeProjectSelection( '0' ), 'all' );
	assert.equal( normalizeProjectSelection( 'project-12' ), 'all' );
	assert.equal( normalizeProjectSelection( '12-project' ), 'all' );
	assert.equal( normalizeProjectSelection( '9007199254740992' ), 'all' );
	assert.equal( projectSelectionQueryValue( 'all' ), null );
	assert.equal( projectSelectionQueryValue( 'none' ), 'none' );
	assert.equal( projectSelectionQueryValue( 12 ), 12 );
} );
