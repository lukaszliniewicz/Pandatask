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
	if ( allocations.some( ( allocation ) => ! allocation.taskId ) ) {
		return 'Choose a task for each allocation, or remove the empty allocation row.';
	}
	if (
		allocations.some(
			( allocation ) => minutesToSeconds( allocation.minutes ) <= 0
		)
	) {
		return 'Each allocation needs a positive duration.';
	}
	const taskIds = allocations.map( ( allocation ) =>
		Number( allocation.taskId )
	);
	if ( new Set( taskIds ).size !== taskIds.length ) {
		return 'A task can appear only once in a work entry. Combine its allocated minutes into one row.';
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
	allocations.map( ( allocation ) => ( {
		task_id: Number( allocation.taskId ),
		seconds: minutesToSeconds( allocation.minutes ),
		...( allocation.residualHandling
			? { residual_handling: allocation.residualHandling }
			: {} ),
	} ) );
