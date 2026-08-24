export const minutesToSeconds = ( minutes ) =>
	Math.max( 0, Math.round( Number( minutes || 0 ) * 60 ) );

export const summarizeAllocationDrafts = (
	durationMinutes,
	allocations = []
) => {
	const totalMinutes = Math.max( 0, Number( durationMinutes || 0 ) );
	const allocatedMinutes = allocations.reduce(
		( total, allocation ) =>
			total + Math.max( 0, Number( allocation.minutes || 0 ) ),
		0
	);
	return {
		totalMinutes,
		allocatedMinutes,
		remainingMinutes: Math.max( 0, totalMinutes - allocatedMinutes ),
		overallocatedMinutes: Math.max( 0, allocatedMinutes - totalMinutes ),
	};
};

export const validateAllocationDrafts = (
	durationSeconds,
	allocations = []
) => {
	if ( ! allocations.length ) {
		return '';
	}
	const targetKeys = [];
	for ( const allocation of allocations ) {
		const targetType =
			allocation.targetType ||
			( allocation.boardName ? 'board' : 'task' );
		if ( targetType === 'board' ) {
			if ( ! String( allocation.boardName || '' ).trim() ) {
				return 'Choose a board for each board allocation, or remove the empty allocation row.';
			}
			targetKeys.push(
				`board:${ String( allocation.boardName ).trim() }`
			);
		} else {
			if ( ! allocation.taskId ) {
				return 'Choose a task for each task allocation, or remove the empty allocation row.';
			}
			targetKeys.push( `task:${ Number( allocation.taskId ) }` );
		}
		if ( minutesToSeconds( allocation.minutes ) <= 0 ) {
			return 'Each allocation needs a positive duration.';
		}
	}
	if ( new Set( targetKeys ).size !== targetKeys.length ) {
		return 'A task or board can appear only once in a work entry. Combine its allocated minutes into one row.';
	}
	const allocatedSeconds = allocations.reduce(
		( total, allocation ) => total + minutesToSeconds( allocation.minutes ),
		0
	);
	if ( allocatedSeconds > Math.max( 0, Number( durationSeconds || 0 ) ) ) {
		return 'Allocated time cannot exceed the total work-entry duration.';
	}
	return '';
};

export const buildAllocationPayload = ( allocations = [] ) =>
	allocations.map( ( allocation ) => {
		const targetType =
			allocation.targetType ||
			( allocation.boardName ? 'board' : 'task' );
		return {
			...( targetType === 'board'
				? { board_name: String( allocation.boardName || '' ).trim() }
				: { task_id: Number( allocation.taskId ) } ),
			seconds: minutesToSeconds( allocation.minutes ),
			...( targetType === 'task' && allocation.residualHandling
				? { residual_handling: allocation.residualHandling }
				: {} ),
		};
	} );

export const workAllocationTargetLabel = ( allocation = {} ) => {
	const taskName = String( allocation.task_name_snapshot || '' ).trim();
	if ( taskName ) {
		return taskName;
	}

	const boardName = String( allocation.board_name_snapshot || '' ).trim();
	if ( /^group_\d+$/.test( boardName ) ) {
		return 'Group board';
	}
	if ( /^user_\d+$/.test( boardName ) ) {
		return 'Private board';
	}
	return boardName || 'Board';
};

export const buildSuggestionAllocationOverride = (
	durationSeconds,
	providerAllocations = [],
	taskAllocations = []
) => {
	if ( ! taskAllocations.length ) {
		return null;
	}

	const totalSeconds = Math.max( 0, Number( durationSeconds || 0 ) );
	const allocatedSeconds = taskAllocations.reduce(
		( total, allocation ) =>
			total + Math.max( 0, Number( allocation.seconds || 0 ) ),
		0
	);
	if ( allocatedSeconds > totalSeconds ) {
		throw new Error(
			'Task allocations cannot exceed the confirmed work duration.'
		);
	}

	const allocations = taskAllocations.map( ( allocation ) => ( {
		...allocation,
	} ) );
	const providerBoardAllocation = providerAllocations.find(
		( allocation ) => allocation?.board_name && ! allocation?.task_id
	);
	if ( providerBoardAllocation && allocatedSeconds < totalSeconds ) {
		allocations.push( {
			board_name: providerBoardAllocation.board_name,
			seconds: totalSeconds - allocatedSeconds,
		} );
	}
	return allocations;
};
