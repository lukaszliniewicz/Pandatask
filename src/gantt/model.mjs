import { maxGanttDate, minGanttDate, parseGanttDate } from './date.mjs';
import { getGanttPredecessorIds, toGanttId } from './relationships.mjs';

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

const createNodes = ( tasks ) => {
	const nodes = new Map();
	for ( const task of tasks ) {
		const id = toGanttId( task.id );
		if ( ! id || nodes.has( id ) ) {
			continue;
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
	}
	return nodes;
};

const connectHierarchy = ( nodes ) => {
	const roots = [];
	nodes.forEach( ( node ) => {
		const parentId = toGanttId( node.task.parent_task_id );
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
	return roots;
};

const computeSchedules = ( nodes, roots ) => {
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
		const childStart = minGanttDate(
			scheduledChildren.map( ( child ) => child.effectiveStart )
		);
		const childEnd = maxGanttDate(
			scheduledChildren.map( ( child ) => child.effectiveEnd )
		);
		node.effectiveStart = minGanttDate( [ ownStart, childStart ] );
		node.effectiveEnd = maxGanttDate( [ ownEnd, childEnd ] );

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
};

const flattenHierarchy = ( nodes, roots ) => {
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
	return rows;
};

const buildDependencyEdges = ( nodes, rows ) => {
	const edges = [];
	rows.forEach( ( node ) => {
		getGanttPredecessorIds( node.task ).forEach( ( predecessorId ) => {
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
	return edges;
};

export const buildGanttModel = ( tasks = [] ) => {
	const nodes = createNodes( tasks );
	const roots = connectHierarchy( nodes );
	computeSchedules( nodes, roots );
	const rows = flattenHierarchy( nodes, roots );
	const edges = buildDependencyEdges( nodes, rows );
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
		rangeStart: minGanttDate(
			scheduledRows.map( ( row ) => row.effectiveStart )
		),
		rangeEnd: maxGanttDate(
			scheduledRows.map( ( row ) => row.effectiveEnd )
		),
	};
};
