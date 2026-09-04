import { useCallback, useEffect, useState } from 'react';
import { isBoardTabAvailable } from '../boardTabs.mjs';
import {
	projectSelectionQueryValue,
	readBoardNavigationSearch,
} from '../boardNavigationModel.mjs';

const readLocation = ( isUserBoard = false, workLogEnabled = true ) => {
	return readBoardNavigationSearch(
		window.location.search,
		isUserBoard,
		workLogEnabled
	);
};

const writeLocation = ( values, mode, state = {} ) => {
	const url = new URL( window.location.href );

	Object.entries( values ).forEach( ( [ key, value ] ) => {
		if ( value === null || value === undefined || value === '' ) {
			url.searchParams.delete( key );
		} else {
			url.searchParams.set( key, String( value ) );
		}
	} );

	window.history[ `${ mode }State` ](
		{ ...( window.history.state || {} ), ...state },
		document.title,
		`${ url.pathname }${ url.search }${ url.hash }`
	);
};

export const useBoardNavigation = (
	isUserBoard = false,
	workLogEnabled = true
) => {
	const [ navigation, setNavigation ] = useState( () =>
		readLocation( isUserBoard, workLogEnabled )
	);

	useEffect( () => {
		const handlePopState = () =>
			setNavigation( readLocation( isUserBoard, workLogEnabled ) );
		window.addEventListener( 'popstate', handlePopState );
		return () => window.removeEventListener( 'popstate', handlePopState );
	}, [ isUserBoard, workLogEnabled ] );

	useEffect( () => {
		if (
			isBoardTabAvailable( navigation.currentTab, isUserBoard, {
				workLogEnabled,
			} )
		) {
			return;
		}
		setNavigation( ( current ) => ( { ...current, currentTab: 'tasks' } ) );
		writeLocation( { pandatask_tab: null }, 'replace' );
	}, [ isUserBoard, navigation.currentTab, workLogEnabled ] );

	const setCurrentTab = useCallback(
		( currentTab ) => {
			if (
				! isBoardTabAvailable( currentTab, isUserBoard, {
					workLogEnabled,
				} )
			) {
				return;
			}
			setNavigation( ( current ) => ( { ...current, currentTab } ) );
			writeLocation(
				{ pandatask_tab: currentTab === 'tasks' ? null : currentTab },
				'push'
			);
		},
		[ isUserBoard, workLogEnabled ]
	);

	const setCurrentView = useCallback( ( currentView ) => {
		if (
			! [ 'compact', 'list', 'kanban', 'calendar', 'gantt' ].includes(
				currentView
			)
		) {
			return;
		}
		setNavigation( ( current ) => ( { ...current, currentView } ) );
		writeLocation(
			{ pandatask_view: currentView === 'compact' ? null : currentView },
			'push'
		);
	}, [] );

	const setSelectedProject = useCallback( ( projectId ) => {
		const selectedProjectId = projectSelectionQueryValue( projectId );
		setNavigation( ( current ) => ( {
			...current,
			selectedProjectId: selectedProjectId ?? 'all',
		} ) );
		writeLocation( { pandatask_project: selectedProjectId }, 'push' );
	}, [] );

	const openTask = useCallback( ( taskId, { replace = false } = {} ) => {
		const normalizedTaskId = Number.parseInt( taskId, 10 );
		if ( ! Number.isInteger( normalizedTaskId ) || normalizedTaskId <= 0 ) {
			return;
		}
		setNavigation( ( current ) => ( {
			...current,
			selectedTaskId: normalizedTaskId,
		} ) );
		writeLocation(
			{ open_task: normalizedTaskId },
			replace ? 'replace' : 'push',
			{ pandataskModalEntry: ! replace }
		);
	}, [] );

	const closeTask = useCallback( ( { replace = false } = {} ) => {
		setNavigation( ( current ) => ( {
			...current,
			selectedTaskId: null,
		} ) );

		if ( ! replace && window.history.state?.pandataskModalEntry ) {
			window.history.back();
			return;
		}

		writeLocation( { open_task: null }, 'replace', {
			pandataskModalEntry: false,
		} );
	}, [] );

	return {
		...navigation,
		isDetailModalOpen: navigation.selectedTaskId !== null,
		setCurrentTab,
		setCurrentView,
		setSelectedProject,
		openTask,
		closeTask,
	};
};
