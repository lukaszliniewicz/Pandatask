const normalizeId = ( value ) => Number.parseInt( value, 10 ) || 0;

const getPersonalContext = ( task, currentUserId ) => {
	const assignedIds = Array.isArray( task.assigned_user_ids )
		? task.assigned_user_ids.map( normalizeId )
		: [];
	const isAssigned = assignedIds.includes( currentUserId );
	const isCreator = normalizeId( task.creator_id ) === currentUserId;

	if ( ! isAssigned && isCreator ) {
		return {
			key: 'added-by-me',
			label: 'Added by me',
			order: 1,
		};
	}

	return {
		key: `board-${ task.board_name || 'personal' }`,
		label: task.board_display_name || 'My Tasks',
		order: 0,
	};
};

export const buildTaskListHierarchy = ( tasks = [] ) => {
	const taskMap = new Map();

	for ( const task of tasks ) {
		const id = normalizeId( task.id );
		if ( id ) {
			taskMap.set( id, { ...task, id, children: [] } );
		}
	}

	const roots = [];
	for ( const task of tasks ) {
		const id = normalizeId( task.id );
		const node = taskMap.get( id );
		const parentId = normalizeId( task.parent_task_id );

		if ( ! node ) {
			continue;
		}

		if ( parentId && parentId !== id && taskMap.has( parentId ) ) {
			taskMap.get( parentId ).children.push( node );
		} else {
			roots.push( node );
		}
	}

	const reachable = new Set();
	const markReachable = ( node ) => {
		if ( reachable.has( node.id ) ) {
			return;
		}
		reachable.add( node.id );
		for ( const child of node.children ) {
			markReachable( child );
		}
	};
	roots.forEach( markReachable );

	// Invalid legacy cycles should remain visible instead of disappearing.
	for ( const node of taskMap.values() ) {
		if ( ! reachable.has( node.id ) ) {
			roots.push( { ...node, children: [] } );
		}
	}

	return roots;
};

export const groupTaskRoots = (
	roots = [],
	{ isUserBoard = false, currentUserId = 0, groupByProject = true } = {}
) => {
	const contexts = new Map();

	for ( const task of roots ) {
		const context = isUserBoard
			? getPersonalContext( task, normalizeId( currentUserId ) )
			: { key: 'current-board', label: '', order: 0 };
		const projectId = normalizeId( task.project_id );
		const project = groupByProject
			? {
					key: projectId ? `project-${ projectId }` : 'project-none',
					label: task.project_name || 'No project',
			  }
			: { key: 'all-projects', label: '' };

		if ( ! contexts.has( context.key ) ) {
			contexts.set( context.key, { ...context, projects: new Map() } );
		}

		const contextEntry = contexts.get( context.key );
		if ( ! contextEntry.projects.has( project.key ) ) {
			contextEntry.projects.set( project.key, { ...project, tasks: [] } );
		}
		contextEntry.projects.get( project.key ).tasks.push( task );
	}

	return Array.from( contexts.values() )
		.sort( ( first, second ) => {
			if ( first.order !== second.order ) {
				return first.order - second.order;
			}
			return first.label.localeCompare( second.label );
		} )
		.map( ( context ) => ( {
			...context,
			projects: Array.from( context.projects.values() ),
		} ) );
};

export const countTaskTree = ( tasks = [] ) =>
	tasks.reduce(
		( total, task ) => total + 1 + countTaskTree( task.children || [] ),
		0
	);
