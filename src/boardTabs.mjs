export const BOARD_TABS = Object.freeze( [
	{ id: 'tasks', label: 'All Tasks', icon: 'list-todo' },
	{ id: 'projects', label: 'Projects', icon: 'folder' },
	{ id: 'overview', label: 'Overview', icon: 'bar-chart' },
	{ id: 'archive', label: 'Archive', icon: 'archive' },
	{ id: 'work', label: 'Work Log', icon: 'history', userBoardOnly: true },
	{ id: 'report', label: 'Report', icon: 'bar-chart' },
] );

export const getBoardTabs = ( isUserBoard = false ) =>
	BOARD_TABS.filter( ( tab ) => ! tab.userBoardOnly || isUserBoard );

export const isBoardTabAvailable = ( tabId, isUserBoard = false ) =>
	getBoardTabs( isUserBoard ).some( ( tab ) => tab.id === tabId );
