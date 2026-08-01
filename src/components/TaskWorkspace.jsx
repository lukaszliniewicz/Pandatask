import React, { Suspense } from 'react';
import FilterBar from './FilterBar';
import TaskList from './TaskList';
import CompactListView from './CompactListView';
import KanbanView from './KanbanView';
import CalendarView from './CalendarView';
import { lazyWithRetry } from '../utils/lazyWithRetry';

const GanttView = lazyWithRetry( () => import( './GanttView' ) );
const LoadingChunk = () => (
	<div className="pandat69-loading">Loading view…</div>
);

const TaskWorkspace = ( {
	filters,
	onFilterChange,
	currentView,
	allSubtasksExpanded,
	onToggleSubtasks,
	groupByProject,
	onToggleProjectGrouping,
	tasks,
	isLoading,
	isError,
	error,
	onTaskAction,
} ) => (
	<>
		<FilterBar
			filters={ filters }
			onFilterChange={ onFilterChange }
			hideProjectSelect
			showSubtaskToggle={ currentView === 'compact' }
			showProjectGrouping={ [ 'compact', 'list' ].includes( currentView ) }
			allSubtasksExpanded={ allSubtasksExpanded }
			onToggleSubtasks={ onToggleSubtasks }
			groupByProject={ groupByProject }
			onToggleProjectGrouping={ onToggleProjectGrouping }
		/>

		{ isLoading && <div className="pandat69-loading">Loading…</div> }
		{ isError && (
			<div className="pandat69-error" role="alert">
				Error: { error?.message || 'Tasks could not be loaded.' }
			</div>
		) }

		{ ! isLoading && ! isError && currentView === 'compact' && (
			<CompactListView
				key={ allSubtasksExpanded ? 'expanded' : 'collapsed' }
				tasks={ tasks }
				onTaskAction={ onTaskAction }
				allSubtasksExpanded={ allSubtasksExpanded }
				groupByProject={ groupByProject }
			/>
		) }

		{ ! isLoading && ! isError && currentView === 'list' && (
			<TaskList tasks={ tasks } onTaskAction={ onTaskAction } groupByProject={ groupByProject } />
		) }

		{ ! isLoading && ! isError && currentView === 'kanban' && (
			<div className="pandat69-view-container pandat69-kanban-view active">
				<KanbanView tasks={ tasks } onTaskAction={ onTaskAction } />
			</div>
		) }

		{ ! isLoading && ! isError && currentView === 'calendar' && (
			<CalendarView tasks={ tasks } onTaskAction={ onTaskAction } />
		) }

		{ ! isLoading && ! isError && currentView === 'gantt' && (
			<Suspense fallback={ <LoadingChunk /> }>
				<GanttView tasks={ tasks } onTaskAction={ onTaskAction } />
			</Suspense>
		) }
	</>
);

export default TaskWorkspace;
