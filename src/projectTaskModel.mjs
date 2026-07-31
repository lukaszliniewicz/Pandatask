const taskKey = ( value ) => {
	if (
		value === null ||
		value === undefined ||
		value === '' ||
		value === 0 ||
		value === '0'
	) {
		return null;
	}

	return String( value );
};

const createsParentCycle = ( node, parent, nodesById ) => {
	const visited = new Set( [ node.key ] );
	let current = parent;

	while ( current ) {
		if ( visited.has( current.key ) ) {
			return true;
		}

		visited.add( current.key );
		const parentKey = taskKey( current.task.parent_task_id );
		current = parentKey ? nodesById.get( parentKey ) : null;
	}

	return false;
};

export const buildProjectTaskTree = ( tasks = [] ) => {
	const nodes = tasks
		.filter( ( task ) => task.status !== 'done' )
		.map( ( task ) => ( {
			key: taskKey( task.id ),
			task,
			children: [],
		} ) )
		.filter( ( node ) => node.key );
	const nodesById = new Map( nodes.map( ( node ) => [ node.key, node ] ) );
	const roots = [];

	nodes.forEach( ( node ) => {
		const parentKey = taskKey( node.task.parent_task_id );
		const parent = parentKey ? nodesById.get( parentKey ) : null;

		if ( ! parent || createsParentCycle( node, parent, nodesById ) ) {
			roots.push( node );
			return;
		}

		parent.children.push( node );
	} );

	return {
		roots,
		total: nodes.length,
	};
};
