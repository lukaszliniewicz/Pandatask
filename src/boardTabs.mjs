export const BOARD_TABS = Object.freeze( [
	{ id: 'tasks', label: 'All Tasks', icon: 'list-todo' },
	{ id: 'projects', label: 'Projects', icon: 'folder' },
	{ id: 'overview', label: 'Overview', icon: 'bar-chart' },
	{ id: 'archive', label: 'Archive', icon: 'archive' },
	{ id: 'work', label: 'Work Log', icon: 'history', userBoardOnly: true },
	{ id: 'report', label: 'Report', icon: 'bar-chart' },
] );

export const getBoardTabs = ( isUserBoard = false, options = {} ) =>
	BOARD_TABS.filter(
		( tab ) =>
			( ! tab.userBoardOnly || isUserBoard ) &&
			( tab.id !== 'work' || options.workLogEnabled !== false )
	);

export const isBoardTabAvailable = (
	tabId,
	isUserBoard = false,
	options = {}
) => getBoardTabs( isUserBoard, options ).some( ( tab ) => tab.id === tabId );
