import { isBoardTabAvailable } from './boardTabs.mjs';

const VALID_VIEWS = new Set( [
	'compact',
	'list',
	'kanban',
	'calendar',
	'gantt',
] );

const VALID_PROJECT_VIEWS = new Set( [ 'list', 'flow', 'timeline' ] );

export const normalizeProjectSelection = ( value ) => {
	if ( value === 'none' ) {
		return 'none';
	}

	const rawValue = String( value ?? '' );
	if ( ! /^\d+$/.test( rawValue ) ) {
		return 'all';
	}

	const projectId = Number( rawValue );
	return Number.isSafeInteger( projectId ) && projectId > 0
		? projectId
		: 'all';
};

export const readBoardNavigationSearch = (
	search,
	isUserBoard = false,
	workLogEnabled = true
) => {
	const params = new URLSearchParams( search );
	const tab = params.get( 'pandatask_tab' );
	const view = params.get( 'pandatask_view' );
	const projectView = params.get( 'pandatask_project_view' );
	const taskValue = Number.parseInt( params.get( 'open_task' ) || '', 10 );

	return {
		currentTab: isBoardTabAvailable( tab || '', isUserBoard, {
			workLogEnabled,
		} )
			? tab
			: 'tasks',
		currentView: VALID_VIEWS.has( view ) ? view : 'compact',
		currentProjectView: VALID_PROJECT_VIEWS.has( projectView )
			? projectView
			: 'list',
		selectedProjectId: normalizeProjectSelection(
			params.get( 'pandatask_project' )
		),
		selectedTaskId:
			Number.isInteger( taskValue ) && taskValue > 0 ? taskValue : null,
	};
};

export const projectSelectionQueryValue = ( value ) => {
	const selection = normalizeProjectSelection( value );
	return selection === 'all' ? null : selection;
};
