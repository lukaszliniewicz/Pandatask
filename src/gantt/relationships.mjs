export const toGanttId = ( value ) => {
	if ( value === null || value === undefined || value === '' ) {
		return '';
	}
	return String( value );
};

export const getGanttPredecessorIds = ( task ) => {
	const explicitIds = Array.isArray( task.predecessor_ids )
		? task.predecessor_ids
		: [];
	const hydratedIds = Array.isArray( task.predecessors )
		? task.predecessors.map( ( predecessor ) => predecessor.id )
		: [];
	const ids = new Set();

	for ( const value of [ ...explicitIds, ...hydratedIds ] ) {
		const id = toGanttId( value );
		if ( id ) {
			ids.add( id );
		}
	}
	return Array.from( ids );
};
