const DEFAULT_LABELS = {
	activity: 'Not specified',
	board: 'Private tasks',
	capacity: 'Not specified',
	category: 'No task category',
	project: 'No project',
	task: 'No task',
};

export const formatWorkDuration = ( seconds = 0 ) => {
	const totalMinutes = Math.max( 0, Math.round( Number( seconds ) / 60 ) );
	const hours = Math.floor( totalMinutes / 60 );
	const minutes = totalMinutes % 60;
	if ( hours && minutes ) {
		return `${ hours }h ${ minutes }m`;
	}
	if ( hours ) {
		return `${ hours }h`;
	}
	return `${ minutes }m`;
};

export const humanizeWorkKey = ( value = '' ) =>
	String( value )
		.replace( /^group_/, 'Group ' )
		.replace( /[_-]+/g, ' ' )
		.replace( /\b\w/g, ( character ) => character.toUpperCase() );

export const getWorkTypeLabel = ( key, activityTypes = [] ) => {
	if ( ! key ) {
		return DEFAULT_LABELS.activity;
	}
	return (
		activityTypes.find( ( item ) => item.key === key )?.label ||
		humanizeWorkKey( key )
	);
};

export const getWorkAllocationLabel = ( allocation, boards = [] ) => {
	const taskName = allocation.task_name_snapshot || allocation.task_name;
	if ( taskName ) {
		return taskName;
	}
	const boardName = allocation.board_name_snapshot || allocation.board_name;
	return boardName ? getBoardLabel( boardName, boards ) : 'Unallocated';
};

export const getBoardLabel = ( boardName, boards = [] ) => {
	if ( ! boardName ) {
		return DEFAULT_LABELS.board;
	}
	const board = boards.find(
		( item ) =>
			String( item.id ) === String( boardName ) ||
			item.board_name === boardName
	);
	if ( board?.name || board?.label ) {
		return board.name || board.label;
	}
	if ( String( boardName ).startsWith( 'user_' ) ) {
		return DEFAULT_LABELS.board;
	}
	return humanizeWorkKey( boardName );
};

const getBreakdownLabel = ( row, dimension, activityTypes, boards ) => {
	switch ( dimension ) {
		case 'activity':
			return getWorkTypeLabel( row.activity_type, activityTypes );
		case 'board':
			return getBoardLabel( row.board_name || row.name, boards );
		case 'capacity':
			return row.capacity || row.name
				? humanizeWorkKey( row.capacity || row.name )
				: DEFAULT_LABELS.capacity;
		case 'category':
			return (
				row.category_name_snapshot ||
				row.name ||
				DEFAULT_LABELS.category
			);
		case 'project':
			return (
				row.project_name_snapshot || row.name || DEFAULT_LABELS.project
			);
		case 'task':
			return row.task_name_snapshot || row.name || DEFAULT_LABELS.task;
		default:
			return 'Other';
	}
};

export const normalizeWorkBreakdown = (
	rows = [],
	{ dimension, activityTypes = [], boards = [] } = {}
) => {
	const grouped = new Map();
	rows.forEach( ( row ) => {
		if (
			[ 'activity', 'capacity' ].includes( dimension ) &&
			row.kind === 'residual'
		) {
			return;
		}
		const label = getBreakdownLabel(
			row,
			dimension,
			activityTypes,
			boards
		);
		grouped.set(
			label,
			( grouped.get( label ) || 0 ) + Number( row.duration_seconds || 0 )
		);
	} );

	return Array.from( grouped, ( [ label, durationSeconds ] ) => ( {
		label,
		duration_seconds: durationSeconds,
	} ) ).sort( ( first, second ) =>
		second.duration_seconds === first.duration_seconds
			? first.label.localeCompare( second.label )
			: second.duration_seconds - first.duration_seconds
	);
};

export const getWorkEntryPresentation = (
	entry,
	{ activityTypes = [], boards = [] } = {}
) => {
	const taskAllocation = ( entry.allocations || [] ).find(
		( allocation ) => allocation.task_id_snapshot
	);
	const boardAllocation = ( entry.allocations || [] ).find(
		( allocation ) => allocation.board_name
	);
	const isResidual = entry.kind === 'residual';

	return {
		isResidual,
		title: isResidual
			? taskAllocation?.task_name_snapshot || 'Other task time'
			: entry.title,
		typeLabel: isResidual
			? 'Other task time'
			: getWorkTypeLabel( entry.activity_type, activityTypes ),
		contextLabel:
			taskAllocation?.task_name_snapshot ||
			getBoardLabel( boardAllocation?.board_name, boards ),
		task: taskAllocation
			? {
					id: taskAllocation.task_id_snapshot,
					name: taskAllocation.task_name_snapshot,
			  }
			: null,
	};
};
