import { useMemo } from 'react';
import {
	buildGanttModel,
	buildGanttTimelineWindow,
	ganttDayDifference,
	getGanttTaskSet,
} from '../../ganttModel.mjs';
import {
	buildGanttHeaderPeriods,
	GANTT_HEADER_HEIGHT,
	GANTT_LABEL_WIDTH,
	GANTT_ROW_HEIGHT,
	GANTT_ZOOM_LEVELS,
	getLocalGanttToday,
} from './ganttViewConfig';

export const useGanttViewModel = (
	tasks,
	showCompleted,
	collapsedIds,
	zoom
) => {
	const today = useMemo( getLocalGanttToday, [] );
	const ganttTasks = useMemo(
		() => getGanttTaskSet( tasks || [], showCompleted ),
		[ tasks, showCompleted ]
	);
	const model = useMemo(
		() => buildGanttModel( ganttTasks ),
		[ ganttTasks ]
	);

	return useMemo( () => {
		const visibleRows = model.rows.filter( ( row ) => {
			let parent = row.parent;
			while ( parent ) {
				if ( collapsedIds.has( parent.id ) ) {
					return false;
				}
				parent = parent.parent;
			}
			return true;
		} );
		const allScheduledRows = [];
		const unscheduledRows = [];
		for ( const row of visibleRows ) {
			if ( row.effectiveStart && row.effectiveEnd ) {
				allScheduledRows.push( row );
			} else {
				unscheduledRows.push( row );
			}
		}

		const zoomConfig = GANTT_ZOOM_LEVELS[ zoom ];
		const timelineWindow = buildGanttTimelineWindow(
			allScheduledRows,
			today,
			zoomConfig.padding
		);
		const scheduledRows = timelineWindow.visibleRows;
		const rowIndexes = new Map(
			scheduledRows.map( ( row, index ) => [ row.id, index ] )
		);
		const rowsById = new Map(
			model.rows.map( ( row ) => [ row.id, row ] )
		);
		const visibleEdges = model.edges.filter(
			( edge ) => rowIndexes.has( edge.from ) && rowIndexes.has( edge.to )
		);
		const conflictCount = visibleRows.reduce(
			( count, row ) => count + row.warnings.length,
			0
		);
		const timelineWidth = Math.max(
			720,
			timelineWindow.dayCount * zoomConfig.dayWidth
		);
		const initialFocusOffset =
			ganttDayDifference(
				timelineWindow.start,
				timelineWindow.focusDate
			) *
				zoomConfig.dayWidth +
			zoomConfig.dayWidth / 2;

		return {
			allScheduledRows,
			canvasHeight:
				GANTT_HEADER_HEIGHT +
				Math.max( scheduledRows.length, 1 ) * GANTT_ROW_HEIGHT,
			canvasWidth: GANTT_LABEL_WIDTH + timelineWidth,
			conflictCount,
			headerPeriods: buildGanttHeaderPeriods(
				timelineWindow.start,
				timelineWindow.dayCount,
				zoom
			),
			initialFocusOffset,
			initialFocusRowIndex: Math.max(
				0,
				scheduledRows.findIndex(
					( row ) =>
						row.effectiveStart <= timelineWindow.focusDate &&
						row.effectiveEnd >= timelineWindow.focusDate
				)
			),
			model,
			rowIndexes,
			rowsById,
			scheduledRows,
			timelineWidth,
			timelineWindow,
			todayOffset:
				ganttDayDifference( timelineWindow.start, today ) *
					zoomConfig.dayWidth +
				zoomConfig.dayWidth / 2,
			unscheduledRows,
			visibleEdges,
			zoomConfig,
		};
	}, [ collapsedIds, model, today, zoom ] );
};
