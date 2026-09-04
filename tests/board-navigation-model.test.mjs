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
			currentProjectView: 'list',
			selectedProjectId: 12,
			selectedTaskId: 484,
		}
	);
} );

test( 'reads and normalizes the dedicated project workspace view', () => {
	assert.equal(
		readBoardNavigationSearch( '?pandatask_project_view=flow' )
			.currentProjectView,
		'flow'
	);
	assert.equal(
		readBoardNavigationSearch( '?pandatask_project_view=wall-of-yarn' )
			.currentProjectView,
		'list'
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
