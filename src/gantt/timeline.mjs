import {
	addGanttDays,
	ganttDayDifference,
	MAX_GANTT_TIMELINE_DAYS,
	maxGanttDate,
	minGanttDate,
} from './date.mjs';

export const pickGanttFocusDate = ( rows, targetDate ) => {
	const scheduledRows = rows.filter(
		( row ) => row.effectiveStart && row.effectiveEnd
	);
	if ( ! scheduledRows.length ) {
		return targetDate;
	}

	if (
		scheduledRows.some(
			( row ) =>
				row.effectiveStart <= targetDate &&
				row.effectiveEnd >= targetDate
		)
	) {
		return targetDate;
	}

	const candidates = scheduledRows.map( ( row ) =>
		row.effectiveEnd < targetDate ? row.effectiveEnd : row.effectiveStart
	);
	const nearbyCandidates = candidates.filter(
		( date ) => Math.abs( ganttDayDifference( targetDate, date ) ) <= 366
	);
	const focusCandidates = nearbyCandidates.length
		? nearbyCandidates
		: candidates;
	let bestDate = focusCandidates[ 0 ];
	let bestDensity = -1;
	let bestDistance = Number.POSITIVE_INFINITY;

	for ( const candidate of focusCandidates ) {
		const windowStart = addGanttDays( candidate, -21 );
		const windowEnd = addGanttDays( candidate, 21 );
		const density = scheduledRows.filter(
			( row ) =>
				row.effectiveStart <= windowEnd &&
				row.effectiveEnd >= windowStart
		).length;
		const distance = Math.abs(
			ganttDayDifference( targetDate, candidate )
		);

		if (
			density > bestDensity ||
			( density === bestDensity && distance < bestDistance )
		) {
			bestDate = candidate;
			bestDensity = density;
			bestDistance = distance;
		}
	}

	return bestDate;
};

export const buildGanttTimelineWindow = (
	rows,
	targetDate,
	padding = 0,
	maximumDays = MAX_GANTT_TIMELINE_DAYS
) => {
	const scheduledRows = rows.filter(
		( row ) => row.effectiveStart && row.effectiveEnd
	);
	const safeMaximumDays = Math.max( 31, Math.floor( maximumDays ) );
	const focusDate = pickGanttFocusDate( scheduledRows, targetDate );
	const earliest = minGanttDate( [
		targetDate,
		...scheduledRows.map( ( row ) => row.effectiveStart ),
	] );
	const latest = maxGanttDate( [
		targetDate,
		...scheduledRows.map( ( row ) => row.effectiveEnd ),
	] );
	const naturalStart = addGanttDays( earliest || targetDate, -padding );
	const naturalEnd = addGanttDays( latest || targetDate, padding );
	const naturalDayCount = ganttDayDifference( naturalStart, naturalEnd ) + 1;
	let start = naturalStart;
	let end = naturalEnd;
	let wasBounded = false;

	if ( naturalDayCount > safeMaximumDays ) {
		wasBounded = true;
		const daysBeforeFocus = Math.floor( ( safeMaximumDays - 1 ) / 2 );
		start = addGanttDays( focusDate, -daysBeforeFocus );
		end = addGanttDays( start, safeMaximumDays - 1 );
	}

	const visibleRows = scheduledRows.filter(
		( row ) => row.effectiveStart <= end && row.effectiveEnd >= start
	);

	return {
		start,
		end,
		focusDate,
		dayCount: ganttDayDifference( start, end ) + 1,
		visibleRows,
		excludedRowCount: scheduledRows.length - visibleRows.length,
		wasBounded,
		todayIsVisible: targetDate >= start && targetDate <= end,
	};
};
