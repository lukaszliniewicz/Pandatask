import { useCallback, useEffect, useState } from 'react';
import { isBoardTabAvailable } from '../boardTabs.mjs';

const VALID_VIEWS = new Set( [
	'compact',
	'list',
	'kanban',
	'calendar',
	'gantt',
] );

const readLocation = ( isUserBoard = false ) => {
	const params = new URLSearchParams( window.location.search );
	const tab = params.get( 'pandatask_tab' );
	const view = params.get( 'pandatask_view' );
	const taskValue = Number.parseInt( params.get( 'open_task' ) || '', 10 );

	return {
		currentTab: isBoardTabAvailable( tab || '', isUserBoard )
			? tab
			: 'tasks',
		currentView: VALID_VIEWS.has( view ) ? view : 'compact',
		selectedTaskId:
			Number.isInteger( taskValue ) && taskValue > 0 ? taskValue : null,
	};
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

export const useBoardNavigation = ( isUserBoard = false ) => {
	const [ navigation, setNavigation ] = useState( () =>
		readLocation( isUserBoard )
	);

	useEffect( () => {
		const handlePopState = () =>
			setNavigation( readLocation( isUserBoard ) );
		window.addEventListener( 'popstate', handlePopState );
		return () => window.removeEventListener( 'popstate', handlePopState );
	}, [ isUserBoard ] );

	useEffect( () => {
		if ( isBoardTabAvailable( navigation.currentTab, isUserBoard ) ) {
			return;
		}
		setNavigation( ( current ) => ( { ...current, currentTab: 'tasks' } ) );
		writeLocation( { pandatask_tab: null }, 'replace' );
	}, [ isUserBoard, navigation.currentTab ] );

	const setCurrentTab = useCallback(
		( currentTab ) => {
			if ( ! isBoardTabAvailable( currentTab, isUserBoard ) ) {
				return;
			}
			setNavigation( ( current ) => ( { ...current, currentTab } ) );
			writeLocation(
				{ pandatask_tab: currentTab === 'tasks' ? null : currentTab },
				'push'
			);
		},
		[ isUserBoard ]
	);

	const setCurrentView = useCallback( ( currentView ) => {
		if ( ! VALID_VIEWS.has( currentView ) ) {
			return;
		}
		setNavigation( ( current ) => ( { ...current, currentView } ) );
		writeLocation(
			{ pandatask_view: currentView === 'compact' ? null : currentView },
			'push'
		);
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
		openTask,
		closeTask,
	};
};
