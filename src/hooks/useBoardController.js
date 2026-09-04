import { useEffect, useMemo, useState } from 'react';
import { generateGCalUrl } from '../utils';
import { useConfig } from '../context/ConfigContext';
import { useBoardFullscreen } from './useBoardFullscreen';
import { useBoardNavigation } from './useBoardNavigation';
import { useContainerMode } from './useContainerMode';
import { useDebouncedValue } from './useDebouncedValue';
import { useTaskMutations } from './useTaskMutations';
import { useTasks } from './useTasks';

/* eslint-disable no-alert -- Destructive actions require synchronous user confirmation. */

const INITIAL_FILTERS = {
	search: '',
	sort: 'created_at_desc',
	status: 'pending_in-progress',
	onlyMyTasks: false,
	archived: false,
};

const normalizeTaskId = ( taskId ) => {
	if ( typeof taskId !== 'string' || ! taskId.startsWith( 'virtual-' ) ) {
		return taskId;
	}
	const id = Number.parseInt( taskId.split( '-' )[ 1 ], 10 );
	return Number.isInteger( id ) ? id : taskId;
};

export const useBoardController = () => {
	const { boardName, text, features } = useConfig();
	const isUserBoard = boardName?.startsWith( 'user_' );
	const workLogEnabled = features?.workLog !== false;
	const navigation = useBoardNavigation( isUserBoard, workLogEnabled );
	const { containerRef, isContainerNarrow } = useContainerMode();
	const fullscreen = useBoardFullscreen();
	const [ filters, setFilters ] = useState( INITIAL_FILTERS );
	const [ allSubtasksExpanded, setAllSubtasksExpanded ] = useState( false );
	const [ groupByProject, setGroupByProject ] = useState( true );
	const [ isSidebarOpen, setIsSidebarOpen ] = useState(
		() => window.innerWidth >= 1080
	);
	const [ dialog, setDialog ] = useState( null );
	const debouncedSearch = useDebouncedValue( filters.search );
	const { deleteTask, updateTask } = useTaskMutations();

	useEffect( () => {
		if ( isContainerNarrow ) {
			setIsSidebarOpen( false );
		}
	}, [ isContainerNarrow ] );

	const activeFilters = useMemo( () => {
		const queryFilters = {
			...filters,
			project: navigation.selectedProjectId,
			search: debouncedSearch,
		};
		if (
			navigation.currentTab === 'tasks' &&
			[ 'kanban', 'gantt' ].includes( navigation.currentView )
		) {
			queryFilters.status = '';
		}
		return queryFilters;
	}, [
		debouncedSearch,
		filters,
		navigation.currentTab,
		navigation.currentView,
		navigation.selectedProjectId,
	] );
	const taskQuery = useTasks( activeFilters );

	const setFilter = ( key, value ) => {
		if ( key === 'project' ) {
			navigation.setSelectedProject( value );
			return;
		}
		if ( isUserBoard && key === 'onlyMyTasks' && value ) {
			navigation.setSelectedProject( 'all' );
		}
		setFilters( ( current ) => ( {
			...current,
			[ key ]: value,
		} ) );
	};

	const closeDialogs = () => {
		if ( navigation.isDetailModalOpen ) {
			navigation.closeTask();
		}
		setDialog( null );
	};

	const handleTaskAction = async ( action, task ) => {
		const taskId = normalizeTaskId( task.id );

		if ( action === 'view' ) {
			navigation.openTask( taskId );
			return;
		}
		if ( action === 'edit' ) {
			setDialog( { kind: 'task', task, defaults: {} } );
			return;
		}
		if ( action === 'add-subtask' ) {
			setDialog( {
				kind: 'task',
				task: null,
				defaults: {
					parent_task_id: taskId,
					project_id: task.project_id || '',
					target_board: task.board_name || '',
				},
			} );
			return;
		}
		if ( action === 'gcal-export' ) {
			const url = generateGCalUrl( task );
			if ( url ) {
				window.open( url, '_blank', 'noopener,noreferrer' );
			}
			return;
		}
		if ( action === 'delete' ) {
			if ( Number( task.is_recurring ) === 1 ) {
				setDialog( { kind: 'recurring-delete', task } );
				return;
			}
			const message =
				text?.confirm_delete_task ||
				`Are you sure you want to delete "${ task.name }"?`;
			if ( deleteTask.isPending || ! window.confirm( message ) ) {
				return;
			}
			try {
				await deleteTask.mutateAsync( { id: task.id } );
			} catch {
				window.alert( 'Failed to delete task.' );
			}
			return;
		}
		if ( action === 'archive' || action === 'unarchive' ) {
			const archived = action === 'archive';
			if (
				updateTask.isPending ||
				! window.confirm(
					`Are you sure you want to ${ action } "${ task.name }"?`
				)
			) {
				return;
			}
			try {
				await updateTask.mutateAsync( {
					id: task.id,
					data: { archived: archived ? 1 : 0 },
				} );
			} catch {
				window.alert( `Failed to ${ action } task.` );
			}
		}
	};

	const addSubtask = ( parentId, projectId = '', targetBoard = '' ) => {
		navigation.closeTask( { replace: true } );
		setDialog( {
			kind: 'task',
			task: null,
			defaults: {
				parent_task_id: parentId,
				project_id: projectId,
				target_board: targetBoard,
			},
		} );
	};

	const confirmRecurringDelete = async ( scope ) => {
		if ( dialog?.kind !== 'recurring-delete' || deleteTask.isPending ) {
			return;
		}
		try {
			await deleteTask.mutateAsync( { id: dialog.task.id, scope } );
			closeDialogs();
		} catch {
			window.alert( 'Failed to delete task.' );
		}
	};

	return {
		...fullscreen,
		...navigation,
		...taskQuery,
		addSubtask,
		allSubtasksExpanded,
		closeDialogs,
		confirmRecurringDelete,
		containerRef,
		dialog,
		filters: { ...filters, project: navigation.selectedProjectId },
		groupByProject,
		handleTaskAction,
		isContainerNarrow,
		isSidebarOpen,
		isUserBoard,
		workLogEnabled,
		navigateTask: ( taskId ) =>
			navigation.openTask( taskId, { replace: true } ),
		openCategoryDialog: () => setDialog( { kind: 'category' } ),
		openWorkTypesDialog: () => setDialog( { kind: 'work-types' } ),
		openWorkDialog: ( options = {} ) => {
			if ( ! workLogEnabled ) {
				return;
			}
			const initialValues =
				options.initialValues ||
				( ! isUserBoard && ! options.entry && ! options.task
					? {
							duration_seconds: 1800,
							allocations: [
								{ board_name: boardName, seconds: 1800 },
							],
					  }
					: null );
			setDialog( {
				kind: 'work',
				entry: options.entry || null,
				task: options.task || null,
				initialValues,
			} );
		},
		openProjectDialog: ( project = null ) =>
			setDialog( { kind: 'project', project } ),
		openTaskDialog: () =>
			setDialog( { kind: 'task', task: null, defaults: {} } ),
		setFilter,
		setIsSidebarOpen,
		toggleAllSubtasks: () =>
			setAllSubtasksExpanded( ( expanded ) => ! expanded ),
		toggleProjectGrouping: () =>
			setGroupByProject( ( grouped ) => ! grouped ),
		toggleSidebar: () => setIsSidebarOpen( ( open ) => ! open ),
	};
};
