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

const NODE_WIDTH = 248;
const NODE_HEIGHT = 94;
const COLUMN_GAP = 92;
const ROW_GAP = 34;
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

const buildEdges = ( tasks, dependencies, allowed ) => {
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

	for ( const task of tasks ) {
		const from = keyOf( task.parent_workspace_key );
		const to = keyOf( task.workspace_key );
		const id = `hierarchy:${ from }:${ to }`;
		if (
			from &&
			allowed.has( from ) &&
			allowed.has( to ) &&
			! seen.has( id )
		) {
			seen.add( id );
			edges.push( { id, from, to, kind: 'hierarchy' } );
		}
	}

	return edges;
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
	const allowed = contextualizeDependencies( baseVisible, dependencies );
	const nodes = tasks
		.filter( ( task ) => allowed.has( keyOf( task.workspace_key ) ) )
		.map( ( task ) => ( { key: keyOf( task.workspace_key ), task } ) );
	const edges = buildEdges( tasks, dependencies, allowed );
	const layerByKey = assignLayers( nodes, edges );
	const layers = new Map();

	for ( const node of nodes ) {
		const layer = layerByKey.get( node.key ) || 0;
		if ( ! layers.has( layer ) ) {
			layers.set( layer, [] );
		}
		layers.get( layer ).push( node );
	}

	for ( const layer of layers.values() ) {
		layer.sort( ( left, right ) =>
			String( left.task.name || '' ).localeCompare(
				String( right.task.name || '' )
			)
		);
	}

	let maxLayer = 0;
	let maxRows = 1;
	const positionedNodes = [];
	for ( const [ layerIndex, layerNodes ] of layers ) {
		maxLayer = Math.max( maxLayer, layerIndex );
		maxRows = Math.max( maxRows, layerNodes.length );
		layerNodes.forEach( ( node, rowIndex ) => {
			positionedNodes.push( {
				...node,
				x: PADDING + layerIndex * ( NODE_WIDTH + COLUMN_GAP ),
				y: PADDING + rowIndex * ( NODE_HEIGHT + ROW_GAP ),
				width: NODE_WIDTH,
				height: NODE_HEIGHT,
			} );
		} );
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
		const startX = from.x + from.width;
		const startY = from.y + from.height / 2;
		const endX = to.x;
		const endY = to.y + to.height / 2;
		const bend = Math.max( 38, Math.abs( endX - startX ) / 2 );
		return [
			{
				...edge,
				path: `M ${ startX } ${ startY } C ${
					startX + bend
				} ${ startY }, ${ endX - bend } ${ endY }, ${ endX } ${ endY }`,
			},
		];
	} );

	return {
		nodes: positionedNodes,
		edges: positionedEdges,
		width:
			PADDING * 2 + ( maxLayer + 1 ) * NODE_WIDTH + maxLayer * COLUMN_GAP,
		height: PADDING * 2 + maxRows * NODE_HEIGHT + ( maxRows - 1 ) * ROW_GAP,
	};
};
