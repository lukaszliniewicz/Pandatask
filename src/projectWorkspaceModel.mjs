const keyOf = ( value ) => String( value ?? '' );

export const PROJECT_WORKSPACE_VIEWS = [
	{ id: 'list', label: 'List', icon: 'list-tree' },
	{ id: 'flow', label: 'Flow', icon: 'share' },
	{ id: 'timeline', label: 'Timeline', icon: 'gantt' },
];

export const getProjectTaskGroups = ( tasks = [] ) => {
	const native = [];
	const external = [];
	const related = [];

	for ( const task of tasks ) {
		if ( task.origin === 'native' ) {
			native.push( task );
		} else if ( task.visible_in_visuals === false ) {
			related.push( task );
		} else {
			external.push( task );
		}
	}

	return { native, external, related };
};

export const toProjectVisualTasks = ( tasks = [] ) =>
	tasks
		.filter( ( task ) => task.visible_in_visuals !== false )
		.map( ( task ) => ( {
			...task,
			id: task.workspace_key,
			canonical_task_id: task.task_id,
			parent_task_id: task.parent_workspace_key,
			predecessor_ids: task.predecessor_keys || [],
			project_name:
				task.origin === 'external'
					? task.project_name ||
					  task.board_display_name ||
					  'External task'
					: task.project_name,
			status: task.restricted ? 'restricted' : task.status,
		} ) );

export const findWorkspaceTask = ( tasks, workspaceKey ) =>
	( tasks || [] ).find(
		( task ) => keyOf( task.workspace_key ) === keyOf( workspaceKey )
	) || null;

const GROUP_WIDTH = 300;
const ROOT_HEIGHT = 92;
const CHILD_HEIGHT = 42;
const COLUMN_GAP = 104;
const ROW_GAP = 42;
const PADDING = 36;

const visibleTaskKeys = ( tasks, filter ) => {
	const visible = new Set();

	for ( const task of tasks ) {
		if ( task.visible_in_visuals === false ) {
			continue;
		}
		if ( filter === 'open' && task.status === 'done' ) {
			continue;
		}
		if ( filter === 'blocked' && ! task.is_blocked ) {
			continue;
		}
		visible.add( keyOf( task.workspace_key ) );
	}

	return visible;
};

const contextualizeDependencies = ( visible, dependencies ) => {
	if ( visible.size === 0 ) {
		return visible;
	}
	const expanded = new Set( visible );
	for ( const edge of dependencies ) {
		const from = keyOf( edge.predecessor_key );
		const to = keyOf( edge.successor_key );
		if ( visible.has( from ) || visible.has( to ) ) {
			expanded.add( from );
			expanded.add( to );
		}
	}
	return expanded;
};

const contextualizeHierarchy = ( visible, tasksByKey ) => {
	const expanded = new Set( visible );
	for ( const key of visible ) {
		let current = tasksByKey.get( key );
		const visited = new Set();
		while ( current ) {
			const parentKey = keyOf( current.parent_workspace_key );
			if ( ! parentKey || visited.has( parentKey ) ) {
				break;
			}
			visited.add( parentKey );
			const parent = tasksByKey.get( parentKey );
			if ( ! parent ) {
				break;
			}
			expanded.add( parentKey );
			current = parent;
		}
	}
	return expanded;
};

const buildDependencyEdges = ( dependencies, allowed ) => {
	const edges = [];
	const seen = new Set();

	for ( const dependency of dependencies ) {
		const from = keyOf( dependency.predecessor_key );
		const to = keyOf( dependency.successor_key );
		const id = `dependency:${ from }:${ to }`;
		if ( allowed.has( from ) && allowed.has( to ) && ! seen.has( id ) ) {
			seen.add( id );
			edges.push( { ...dependency, id, from, to, kind: 'dependency' } );
		}
	}

	return edges;
};

const compareTasks = ( left, right ) =>
	String( left.task.name || '' ).localeCompare(
		String( right.task.name || '' )
	);

const groupNodesByRoot = ( nodes ) => {
	const nodesByKey = new Map( nodes.map( ( node ) => [ node.key, node ] ) );
	const rootByKey = new Map();

	for ( const node of nodes ) {
		let current = node;
		const visited = new Set( [ node.key ] );
		while ( current.parentKey && nodesByKey.has( current.parentKey ) ) {
			if ( visited.has( current.parentKey ) ) {
				current = nodesByKey.get(
					[ ...visited ].sort( ( left, right ) =>
						left.localeCompare( right )
					)[ 0 ]
				);
				break;
			}
			visited.add( current.parentKey );
			current = nodesByKey.get( current.parentKey );
		}
		rootByKey.set( node.key, current.key );
	}

	const groups = new Map();
	for ( const node of nodes ) {
		const rootKey = rootByKey.get( node.key );
		if ( ! groups.has( rootKey ) ) {
			groups.set( rootKey, [] );
		}
		groups.get( rootKey ).push( node );
	}

	return { groups, rootByKey };
};

const orderGroupNodes = ( groupNodes, rootKey ) => {
	const nodesByKey = new Map(
		groupNodes.map( ( node ) => [ node.key, node ] )
	);
	const childrenByKey = new Map();
	for ( const node of groupNodes ) {
		if ( node.key === rootKey || ! nodesByKey.has( node.parentKey ) ) {
			continue;
		}
		if ( ! childrenByKey.has( node.parentKey ) ) {
			childrenByKey.set( node.parentKey, [] );
		}
		childrenByKey.get( node.parentKey ).push( node );
	}
	for ( const children of childrenByKey.values() ) {
		children.sort( compareTasks );
	}

	const ordered = [];
	const visited = new Set();
	const walk = ( node, depth ) => {
		if ( ! node || visited.has( node.key ) ) {
			return;
		}
		visited.add( node.key );
		ordered.push( { ...node, depth } );
		for ( const child of childrenByKey.get( node.key ) || [] ) {
			walk( child, depth + 1 );
		}
	};

	walk( nodesByKey.get( rootKey ), 0 );
	for ( const node of [ ...groupNodes ].sort( compareTasks ) ) {
		walk( node, node.key === rootKey ? 0 : 1 );
	}
	return ordered;
};

const assignLayers = ( nodes, edges ) => {
	const incoming = new Map( nodes.map( ( node ) => [ node.key, 0 ] ) );
	const outgoing = new Map( nodes.map( ( node ) => [ node.key, [] ] ) );
	for ( const edge of edges ) {
		incoming.set( edge.to, ( incoming.get( edge.to ) || 0 ) + 1 );
		outgoing.get( edge.from )?.push( edge.to );
	}

	const layerByKey = new Map();
	const queue = nodes
		.filter( ( node ) => incoming.get( node.key ) === 0 )
		.map( ( node ) => node.key );
	for ( const key of queue ) {
		layerByKey.set( key, 0 );
	}

	for ( let index = 0; index < queue.length; index += 1 ) {
		const key = queue[ index ];
		const nextLayer = ( layerByKey.get( key ) || 0 ) + 1;
		for ( const target of outgoing.get( key ) || [] ) {
			layerByKey.set(
				target,
				Math.max( layerByKey.get( target ) || 0, nextLayer )
			);
			incoming.set( target, incoming.get( target ) - 1 );
			if ( incoming.get( target ) === 0 ) {
				queue.push( target );
			}
		}
	}

	let fallbackLayer = Math.max( 0, ...layerByKey.values() );
	for ( const node of nodes ) {
		if ( ! layerByKey.has( node.key ) ) {
			fallbackLayer += 1;
			layerByKey.set( node.key, fallbackLayer );
		}
	}

	return layerByKey;
};

export const buildProjectFlowModel = (
	tasks = [],
	dependencies = [],
	filter = 'all'
) => {
	const baseVisible = visibleTaskKeys( tasks, filter );
	const allVisualTasksByKey = new Map(
		tasks
			.filter( ( task ) => task.visible_in_visuals !== false )
			.map( ( task ) => [ keyOf( task.workspace_key ), task ] )
	);
	const allowed = contextualizeHierarchy(
		contextualizeDependencies( baseVisible, dependencies ),
		allVisualTasksByKey
	);
	const nodes = tasks
		.filter( ( task ) => allowed.has( keyOf( task.workspace_key ) ) )
		.map( ( task ) => ( {
			key: keyOf( task.workspace_key ),
			parentKey: keyOf( task.parent_workspace_key ),
			task,
		} ) );
	const edges = buildDependencyEdges( dependencies, allowed );
	const { groups: groupedNodes, rootByKey } = groupNodesByRoot( nodes );
	const groups = [ ...groupedNodes.entries() ].map( ( [ key, members ] ) => {
		const orderedNodes = orderGroupNodes( members, key );
		return {
			key,
			task: orderedNodes[ 0 ]?.task || members[ 0 ].task,
			members: orderedNodes,
			height:
				ROOT_HEIGHT +
				Math.max( 0, orderedNodes.length - 1 ) * CHILD_HEIGHT,
		};
	} );
	const groupEdges = [];
	const seenGroupEdges = new Set();
	for ( const edge of edges ) {
		const from = rootByKey.get( edge.from );
		const to = rootByKey.get( edge.to );
		const id = `${ from }:${ to }`;
		if ( from && to && from !== to && ! seenGroupEdges.has( id ) ) {
			seenGroupEdges.add( id );
			groupEdges.push( { from, to } );
		}
	}
	const layerByKey = assignLayers( groups, groupEdges );
	const layers = new Map();

	for ( const group of groups ) {
		const layer = layerByKey.get( group.key ) || 0;
		if ( ! layers.has( layer ) ) {
			layers.set( layer, [] );
		}
		layers.get( layer ).push( group );
	}

	for ( const layer of layers.values() ) {
		layer.sort( compareTasks );
	}

	let maxLayer = 0;
	let maxColumnHeight = 0;
	const positionedGroups = [];
	const positionedNodes = [];
	for ( const [ layerIndex, layerGroups ] of layers ) {
		maxLayer = Math.max( maxLayer, layerIndex );
		let columnHeight = 0;
		for ( const group of layerGroups ) {
			const x = PADDING + layerIndex * ( GROUP_WIDTH + COLUMN_GAP );
			const y = PADDING + columnHeight;
			let memberOffset = 0;
			const positionedMembers = group.members.map( ( node, index ) => {
				const height = index === 0 ? ROOT_HEIGHT : CHILD_HEIGHT;
				const positioned = {
					...node,
					groupKey: group.key,
					isRoot: index === 0,
					x,
					y: y + memberOffset,
					width: GROUP_WIDTH,
					height,
				};
				memberOffset += height;
				positionedNodes.push( positioned );
				return positioned;
			} );
			positionedGroups.push( {
				...group,
				x,
				y,
				width: GROUP_WIDTH,
				members: positionedMembers,
			} );
			columnHeight += group.height + ROW_GAP;
		}
		maxColumnHeight = Math.max(
			maxColumnHeight,
			Math.max( 0, columnHeight - ROW_GAP )
		);
	}

	const nodesByKey = new Map(
		positionedNodes.map( ( node ) => [ node.key, node ] )
	);
	const positionedEdges = edges.flatMap( ( edge ) => {
		const from = nodesByKey.get( edge.from );
		const to = nodesByKey.get( edge.to );
		if ( ! from || ! to ) {
			return [];
		}
		const isWithinGroup = from.groupKey === to.groupKey;
		const startX = from.x + from.width;
		const startY = from.y + from.height / 2;
		const endX = isWithinGroup ? to.x + to.width : to.x;
		const endY = to.y + to.height / 2;
		const bend = isWithinGroup
			? 38
			: Math.max( 42, Math.abs( endX - startX ) / 2 );
		return [
			{
				...edge,
				isWithinGroup,
				path: isWithinGroup
					? `M ${ startX } ${ startY } C ${
							startX + bend
					  } ${ startY }, ${
							startX + bend
					  } ${ endY }, ${ endX } ${ endY }`
					: `M ${ startX } ${ startY } C ${
							startX + bend
					  } ${ startY }, ${
							endX - bend
					  } ${ endY }, ${ endX } ${ endY }`,
			},
		];
	} );
	const hierarchy = positionedNodes.flatMap( ( node ) =>
		node.parentKey && nodesByKey.has( node.parentKey )
			? [ { from: node.parentKey, to: node.key } ]
			: []
	);

	return {
		nodes: positionedNodes,
		edges: positionedEdges,
		groups: positionedGroups,
		hierarchy,
		width:
			PADDING * 2 +
			( maxLayer + 1 ) * GROUP_WIDTH +
			maxLayer * COLUMN_GAP,
		height: PADDING * 2 + maxColumnHeight,
	};
};

export const buildProjectFlowFocus = ( model, selectedKey ) => {
	const selected = keyOf( selectedKey );
	if (
		! selected ||
		! model.nodes.some( ( node ) => node.key === selected )
	) {
		return { edgeIds: new Set(), taskKeys: new Set() };
	}

	const children = new Map();
	const parent = new Map();
	for ( const edge of model.hierarchy || [] ) {
		parent.set( edge.to, edge.from );
		if ( ! children.has( edge.from ) ) {
			children.set( edge.from, [] );
		}
		children.get( edge.from ).push( edge.to );
	}

	const branch = new Set();
	const queue = [ selected ];
	for ( let index = 0; index < queue.length; index += 1 ) {
		const key = queue[ index ];
		if ( branch.has( key ) ) {
			continue;
		}
		branch.add( key );
		queue.push( ...( children.get( key ) || [] ) );
	}

	const taskKeys = new Set( branch );
	let ancestor = parent.get( selected );
	while ( ancestor && ! taskKeys.has( ancestor ) ) {
		taskKeys.add( ancestor );
		ancestor = parent.get( ancestor );
	}

	const edgeIds = new Set();
	for ( const edge of model.edges || [] ) {
		if ( branch.has( edge.from ) || branch.has( edge.to ) ) {
			edgeIds.add( edge.id );
			taskKeys.add( edge.from );
			taskKeys.add( edge.to );
		}
	}

	for ( const key of [ ...taskKeys ] ) {
		let keyAncestor = parent.get( key );
		while ( keyAncestor && ! taskKeys.has( keyAncestor ) ) {
			taskKeys.add( keyAncestor );
			keyAncestor = parent.get( keyAncestor );
		}
	}

	return { edgeIds, taskKeys };
};
