const DAY_IN_MS = 24 * 60 * 60 * 1000;

export const MAX_GANTT_TIMELINE_DAYS = 1096;

const toId = ( value ) => {
	if ( value === null || value === undefined || value === '' ) {
		return '';
	}

	return String( value );
};

const minDate = ( values ) => {
	const dates = values.filter( Boolean );
	return dates.length ? new Date( Math.min( ...dates.map( Number ) ) ) : null;
};

const maxDate = ( values ) => {
	const dates = values.filter( Boolean );
	return dates.length ? new Date( Math.max( ...dates.map( Number ) ) ) : null;
};

export const parseGanttDate = ( value ) => {
	if ( ! value ) {
		return null;
	}

	if ( value instanceof Date ) {
		return Number.isNaN( value.getTime() )
			? null
			: new Date(
					Date.UTC(
						value.getUTCFullYear(),
						value.getUTCMonth(),
						value.getUTCDate()
					)
			  );
	}

	const match = String( value ).match( /^(\d{4})-(\d{2})-(\d{2})$/ );
	if ( ! match ) {
		return null;
	}

	const year = Number( match[ 1 ] );
	const month = Number( match[ 2 ] );
	const day = Number( match[ 3 ] );
	if ( year < 1 || month < 1 || month > 12 || day < 1 || day > 31 ) {
		return null;
	}

	const date = new Date( 0 );
	date.setUTCHours( 0, 0, 0, 0 );
	date.setUTCFullYear( year, month - 1, day );

	if (
		Number.isNaN( date.getTime() ) ||
		date.getUTCFullYear() !== year ||
		date.getUTCMonth() !== month - 1 ||
		date.getUTCDate() !== day
	) {
		return null;
	}

	return date;
};

export const formatGanttDate = ( date ) => {
	if ( ! date ) {
		return '';
	}

	return [
		date.getUTCFullYear(),
		String( date.getUTCMonth() + 1 ).padStart( 2, '0' ),
		String( date.getUTCDate() ).padStart( 2, '0' ),
	].join( '-' );
};

export const addGanttDays = ( date, days ) =>
	new Date( date.getTime() + days * DAY_IN_MS );

export const ganttDayDifference = ( start, end ) =>
	Math.round( ( end.getTime() - start.getTime() ) / DAY_IN_MS );

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

	focusCandidates.forEach( ( candidate ) => {
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
	} );

	return bestDate;
};

/**
 * Bound the rendered timeline while retaining tasks that overlap the window.
 *
 * A corrupt or accidental century-spanning date must never create millions of
 * day headers or a multi-megabyte SVG/canvas.
 *
 * @param {Array}  rows        Scheduled Gantt model rows.
 * @param {Date}   targetDate  Preferred focus date.
 * @param {number} padding     Extra days around the natural range.
 * @param {number} maximumDays Maximum number of rendered days.
 * @return {Object} Bounded dates, visible rows, and truncation metadata.
 */
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
	const earliest = minDate( [
		targetDate,
		...scheduledRows.map( ( row ) => row.effectiveStart ),
	] );
	const latest = maxDate( [
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

const compareNodes = ( first, second ) => {
	const firstDate =
		parseGanttDate( first.task.start_date ) ||
		parseGanttDate( first.task.deadline );
	const secondDate =
		parseGanttDate( second.task.start_date ) ||
		parseGanttDate( second.task.deadline );

	if (
		firstDate &&
		secondDate &&
		Number( firstDate ) !== Number( secondDate )
	) {
		return Number( firstDate ) - Number( secondDate );
	}

	if ( firstDate && ! secondDate ) {
		return -1;
	}

	if ( ! firstDate && secondDate ) {
		return 1;
	}

	return String( first.task.name || '' ).localeCompare(
		String( second.task.name || '' )
	);
};

const getPredecessorIds = ( task ) => {
	const explicitIds = Array.isArray( task.predecessor_ids )
		? task.predecessor_ids
		: [];
	const hydratedIds = Array.isArray( task.predecessors )
		? task.predecessors.map( ( predecessor ) => predecessor.id )
		: [];

	return Array.from(
		new Set(
			[ ...explicitIds, ...hydratedIds ].map( toId ).filter( Boolean )
		)
	);
};

/**
 * Build a non-destructive schedule model.
 *
 * Parent/child links express decomposition only. Dependency edges are created
 * exclusively from explicit predecessor relationships.
 *
 * @param {Array} tasks Hydrated Pandatask task records.
 * @return {Object} Rows, explicit dependency edges, and the derived date range.
 */
export const buildGanttModel = ( tasks = [] ) => {
	const nodes = new Map();

	tasks.forEach( ( task ) => {
		const id = toId( task.id );
		if ( ! id || nodes.has( id ) ) {
			return;
		}

		nodes.set( id, {
			id,
			task,
			children: [],
			parent: null,
			depth: 0,
			ownStart: null,
			ownEnd: null,
			effectiveStart: null,
			effectiveEnd: null,
			scheduleKind: 'unscheduled',
			warnings: [],
		} );
	} );

	const roots = [];
	nodes.forEach( ( node ) => {
		const parentId = toId( node.task.parent_task_id );
		const parent =
			parentId && parentId !== node.id ? nodes.get( parentId ) : null;

		if ( parent ) {
			node.parent = parent;
			parent.children.push( node );
		} else {
			roots.push( node );
		}
	} );

	roots.sort( compareNodes );
	nodes.forEach( ( node ) => node.children.sort( compareNodes ) );

	const computed = new Set();
	const computing = new Set();

	const computeSchedule = ( node ) => {
		if ( computed.has( node.id ) ) {
			return node;
		}

		if ( computing.has( node.id ) ) {
			node.warnings.push( {
				code: 'hierarchy-cycle',
				label: 'This hierarchy contains a cycle.',
			} );
			return node;
		}

		computing.add( node.id );

		const declaredStart = parseGanttDate( node.task.start_date );
		const declaredEnd = parseGanttDate( node.task.deadline );
		let ownStart = declaredStart || declaredEnd;
		let ownEnd = declaredEnd || declaredStart;

		if ( ownStart && ownEnd && ownEnd < ownStart ) {
			node.warnings.push( {
				code: 'invalid-range',
				label: 'The deadline is earlier than the start date.',
			} );
			[ ownStart, ownEnd ] = [ ownEnd, ownStart ];
		}

		node.ownStart = ownStart;
		node.ownEnd = ownEnd;

		node.children.forEach( computeSchedule );
		const scheduledChildren = node.children.filter(
			( child ) => child.effectiveStart && child.effectiveEnd
		);
		const childStart = minDate(
			scheduledChildren.map( ( child ) => child.effectiveStart )
		);
		const childEnd = maxDate(
			scheduledChildren.map( ( child ) => child.effectiveEnd )
		);

		node.effectiveStart = minDate( [ ownStart, childStart ] );
		node.effectiveEnd = maxDate( [ ownEnd, childEnd ] );

		if ( ! node.effectiveStart || ! node.effectiveEnd ) {
			node.scheduleKind = 'unscheduled';
		} else if ( scheduledChildren.length ) {
			node.scheduleKind = ownStart ? 'parent-summary' : 'rollup-only';
		} else if ( declaredStart && declaredEnd ) {
			node.scheduleKind = 'fixed';
		} else if ( declaredEnd ) {
			node.scheduleKind = 'deadline-only';
		} else {
			node.scheduleKind = 'start-only';
		}

		if (
			scheduledChildren.length &&
			ownStart &&
			ownEnd &&
			( childStart < ownStart || childEnd > ownEnd )
		) {
			node.warnings.push( {
				code: 'child-outside-parent',
				label: 'A subtask falls outside the task’s own schedule.',
			} );
		}

		computing.delete( node.id );
		computed.add( node.id );
		return node;
	};

	roots.forEach( computeSchedule );
	nodes.forEach( computeSchedule );

	const rows = [];
	const flattened = new Set();
	const flatten = ( node, depth = 0 ) => {
		if ( flattened.has( node.id ) ) {
			return;
		}

		flattened.add( node.id );
		node.depth = depth;
		rows.push( node );
		node.children.forEach( ( child ) => flatten( child, depth + 1 ) );
	};

	roots.forEach( ( node ) => flatten( node ) );
	nodes.forEach( ( node ) => flatten( node ) );

	const edges = [];
	rows.forEach( ( node ) => {
		getPredecessorIds( node.task ).forEach( ( predecessorId ) => {
			const predecessor = nodes.get( predecessorId );
			if ( ! predecessor ) {
				return;
			}

			const hasSchedule = predecessor.effectiveEnd && node.effectiveStart;
			const hasConflict =
				Boolean( hasSchedule ) &&
				node.effectiveStart < predecessor.effectiveEnd;

			edges.push( {
				id: `${ predecessor.id }-${ node.id }`,
				from: predecessor.id,
				to: node.id,
				hasConflict,
			} );

			if (
				hasConflict &&
				! node.warnings.some(
					( warning ) => warning.code === 'dependency-overlap'
				)
			) {
				node.warnings.push( {
					code: 'dependency-overlap',
					label: 'This task starts before a predecessor finishes.',
				} );
			}
		} );
	} );

	const scheduledRows = rows.filter(
		( row ) => row.effectiveStart && row.effectiveEnd
	);
	const unscheduledRows = rows.filter(
		( row ) => ! row.effectiveStart || ! row.effectiveEnd
	);

	return {
		rows,
		scheduledRows,
		unscheduledRows,
		edges,
		rangeStart: minDate(
			scheduledRows.map( ( row ) => row.effectiveStart )
		),
		rangeEnd: maxDate( scheduledRows.map( ( row ) => row.effectiveEnd ) ),
	};
};

export const getGanttTaskSet = ( tasks = [], showCompleted = false ) => {
	if ( showCompleted ) {
		return tasks.filter( ( task ) => Number( task.is_recurring ) !== 1 );
	}

	const realTasks = tasks.filter(
		( task ) => Number( task.is_recurring ) !== 1
	);
	const tasksById = new Map(
		realTasks.map( ( task ) => [ toId( task.id ), task ] )
	);
	const activeTasks = realTasks.filter( ( task ) => task.status !== 'done' );
	const contextualIds = new Set();
	const queue = [ ...activeTasks ];
	const visited = new Set( activeTasks.map( ( task ) => toId( task.id ) ) );

	while ( queue.length ) {
		const task = queue.shift();
		const relatedIds = [
			toId( task.parent_task_id ),
			...getPredecessorIds( task ),
		].filter( Boolean );

		relatedIds.forEach( ( id ) => {
			const relatedTask = tasksById.get( id );
			if ( ! relatedTask ) {
				return;
			}

			if ( relatedTask.status === 'done' ) {
				contextualIds.add( id );
			}

			if ( ! visited.has( id ) ) {
				visited.add( id );
				queue.push( relatedTask );
			}
		} );
	}

	return realTasks
		.filter(
			( task ) =>
				task.status !== 'done' || contextualIds.has( toId( task.id ) )
		)
		.map( ( task ) => ( {
			...task,
			is_gantt_context:
				task.status === 'done' && contextualIds.has( toId( task.id ) ),
		} ) );
};
