import { getGanttPredecessorIds, toGanttId } from './relationships.mjs';

export const getGanttTaskSet = ( tasks = [], showCompleted = false ) => {
	const realTasks = tasks.filter(
		( task ) => Number( task.is_recurring ) !== 1
	);
	if ( showCompleted ) {
		return realTasks;
	}

	const tasksById = new Map(
		realTasks.map( ( task ) => [ toGanttId( task.id ), task ] )
	);
	const activeTasks = realTasks.filter( ( task ) => task.status !== 'done' );
	const contextualIds = new Set();
	const queue = [ ...activeTasks ];
	const visited = new Set(
		activeTasks.map( ( task ) => toGanttId( task.id ) )
	);

	while ( queue.length ) {
		const task = queue.shift();
		const relatedIds = [
			toGanttId( task.parent_task_id ),
			...getGanttPredecessorIds( task ),
		].filter( Boolean );

		for ( const id of relatedIds ) {
			const relatedTask = tasksById.get( id );
			if ( ! relatedTask ) {
				continue;
			}
			if ( relatedTask.status === 'done' ) {
				contextualIds.add( id );
			}
			if ( ! visited.has( id ) ) {
				visited.add( id );
				queue.push( relatedTask );
			}
		}
	}

	return realTasks.flatMap( ( task ) => {
		const id = toGanttId( task.id );
		if ( task.status === 'done' && ! contextualIds.has( id ) ) {
			return [];
		}
		return [
			{
				...task,
				is_gantt_context:
					task.status === 'done' && contextualIds.has( id ),
			},
		];
	} );
};
