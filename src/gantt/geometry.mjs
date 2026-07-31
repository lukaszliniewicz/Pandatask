import { ganttDayDifference } from './date.mjs';

const clamp = ( value, minimum, maximum ) =>
	Math.min( maximum, Math.max( minimum, value ) );

export const getGanttBarGeometry = ( row, timelineWindow, dayWidth ) => {
	const visibleStart =
		row.effectiveStart < timelineWindow.start
			? timelineWindow.start
			: row.effectiveStart;
	const visibleEnd =
		row.effectiveEnd > timelineWindow.end
			? timelineWindow.end
			: row.effectiveEnd;
	const left =
		ganttDayDifference( timelineWindow.start, visibleStart ) * dayWidth;
	const width = Math.max(
		dayWidth,
		( ganttDayDifference( visibleStart, visibleEnd ) + 1 ) * dayWidth
	);
	const hasVisibleOwnRange = Boolean(
		row.ownStart &&
			row.ownEnd &&
			row.ownStart <= timelineWindow.end &&
			row.ownEnd >= timelineWindow.start
	);
	let visibleOwnStart = null;
	let visibleOwnEnd = null;
	if ( hasVisibleOwnRange ) {
		visibleOwnStart =
			row.ownStart < timelineWindow.start
				? timelineWindow.start
				: row.ownStart;
		visibleOwnEnd =
			row.ownEnd > timelineWindow.end ? timelineWindow.end : row.ownEnd;
	}

	return {
		left,
		width,
		hasVisibleOwnRange,
		ownLeft: visibleOwnStart
			? ganttDayDifference( visibleStart, visibleOwnStart ) * dayWidth
			: 0,
		ownWidth:
			visibleOwnStart && visibleOwnEnd
				? Math.max(
						dayWidth,
						( ganttDayDifference( visibleOwnStart, visibleOwnEnd ) +
							1 ) *
							dayWidth
				  )
				: 0,
	};
};

export const getGanttDependencyPath = ( {
	edge,
	rowIndexes,
	rowsById,
	timelineStart,
	timelineWidth,
	dayWidth,
	rowHeight,
} ) => {
	const from = rowsById.get( edge.from );
	const to = rowsById.get( edge.to );
	const fromIndex = rowIndexes.get( edge.from );
	const toIndex = rowIndexes.get( edge.to );
	if ( ! from || ! to || fromIndex === undefined || toIndex === undefined ) {
		return '';
	}

	const rawFromX =
		( ganttDayDifference( timelineStart, from.effectiveEnd ) + 1 ) *
		dayWidth;
	const rawToX =
		ganttDayDifference( timelineStart, to.effectiveStart ) * dayWidth;
	const fromX = clamp( rawFromX, 0, timelineWidth );
	const toX = clamp( rawToX, 0, timelineWidth );
	const fromY = fromIndex * rowHeight + rowHeight / 2;
	const toY = toIndex * rowHeight + rowHeight / 2;
	const bendX =
		toX > fromX + 18
			? fromX + ( toX - fromX ) / 2
			: Math.max( fromX, toX ) + 18;
	return `M ${ fromX } ${ fromY } H ${ bendX } V ${ toY } H ${ toX }`;
};
