export const serializeCompletionWorkItems = (
	items,
	availableSeconds = null
) => {
	const payload = items.map( ( item ) => {
		const minutes = Number( item.minutes );
		if (
			! Number.isFinite( minutes ) ||
			minutes <= 0 ||
			! item.activity_type
		) {
			throw new Error(
				'Each itemised entry needs a positive duration and work type.'
			);
		}
		return {
			duration_seconds: Math.round( minutes * 60 ),
			activity_type: item.activity_type,
			...( item.capacity ? { capacity: item.capacity } : {} ),
			...( item.title?.trim() ? { title: item.title.trim() } : {} ),
		};
	} );
	if ( availableSeconds !== null ) {
		const total = payload.reduce(
			( sum, item ) => sum + item.duration_seconds,
			0
		);
		if ( total > Math.max( 0, Number( availableSeconds || 0 ) ) ) {
			throw new Error(
				'Itemised work cannot exceed the remaining unlogged actual time.'
			);
		}
	}
	return payload;
};

export const serializeResidualClassification = ( residual ) => {
	const payload = {};
	if ( residual.activity_type ) {
		payload.activity_type = residual.activity_type;
	}
	if ( residual.capacity ) {
		payload.capacity = residual.capacity;
	}
	return payload;
};
