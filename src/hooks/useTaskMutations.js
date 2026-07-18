import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useTaskMutations = () => {
	const { apiClient, boardName } = useConfig();
	const queryClient = useQueryClient();

	const createTask = useMutation( {
		mutationFn: async ( data ) => {
			const targetBoard = data.board_name || boardName;
			const payload = { ...data, board_name: targetBoard };
			const response = await apiClient.post(
				`boards/${ targetBoard }/tasks`,
				payload
			);
			return response.task;
		},
		onSuccess: ( _, variables ) => {
			const targetBoard = variables.board_name || boardName;
			queryClient.invalidateQueries( {
				queryKey: [ 'tasks', targetBoard ],
			} );
			queryClient.invalidateQueries( {
				queryKey: [ 'report', targetBoard ],
			} );
		},
	} );

	const updateTask = useMutation( {
		mutationFn: async ( { id, data } ) => {
			const response = await apiClient.post( `tasks/${ id }`, data );
			return response.task;
		},
		onMutate: async ( { id, data } ) => {
			if ( data.board_name && data.board_name !== boardName ) {
				return { previousTasks: null };
			}

			await queryClient.cancelQueries( {
				queryKey: [ 'tasks', boardName ],
			} );
			const previousTasks = queryClient.getQueriesData( {
				queryKey: [ 'tasks', boardName ],
			} );

			queryClient.setQueriesData(
				{ queryKey: [ 'tasks', boardName ] },
				( old ) => {
					if ( ! Array.isArray( old ) ) {
						return old;
					}
					return old.map( ( task ) =>
						task.id === id ? { ...task, ...data } : task
					);
				}
			);

			return { previousTasks };
		},
		onError: ( err, variables, context ) => {
			if ( context?.previousTasks ) {
				context.previousTasks.forEach( ( [ queryKey, data ] ) => {
					queryClient.setQueryData( queryKey, data );
				} );
			}
		},
		onSettled: ( _, __, { id, data } ) => {
			queryClient.invalidateQueries( {
				queryKey: [ 'tasks', boardName ],
			} );
			queryClient.invalidateQueries( {
				queryKey: [ 'report', boardName ],
			} );
			queryClient.invalidateQueries( { queryKey: [ 'task', id ] } );
			queryClient.invalidateQueries( {
				queryKey: [ 'task_history', id ],
			} );

			if ( data.board_name && data.board_name !== boardName ) {
				queryClient.invalidateQueries( {
					queryKey: [ 'tasks', data.board_name ],
				} );
				queryClient.invalidateQueries( {
					queryKey: [ 'report', data.board_name ],
				} );
			}
		},
	} );

	const deleteTask = useMutation( {
		mutationFn: async ( { id, scope } ) => {
			const config = scope ? { params: { delete_scope: scope } } : {};
			await apiClient.delete( `tasks/${ id }`, config );
		},
		onMutate: async ( { id, scope } ) => {
			// Only optimistically delete if it's a full delete or delete all instances
			if ( scope && scope !== 'all' ) {
				return { previousTasks: null };
			}

			await queryClient.cancelQueries( {
				queryKey: [ 'tasks', boardName ],
			} );
			const previousTasks = queryClient.getQueriesData( {
				queryKey: [ 'tasks', boardName ],
			} );

			queryClient.setQueriesData(
				{ queryKey: [ 'tasks', boardName ] },
				( old ) => {
					if ( ! Array.isArray( old ) ) {
						return old;
					}
					return old.filter( ( task ) => task.id !== id );
				}
			);

			return { previousTasks };
		},
		onError: ( err, variables, context ) => {
			if ( context?.previousTasks ) {
				context.previousTasks.forEach( ( [ queryKey, data ] ) => {
					queryClient.setQueryData( queryKey, data );
				} );
			}
		},
		onSettled: ( _, __, { id } ) => {
			queryClient.invalidateQueries( {
				queryKey: [ 'tasks', boardName ],
			} );
			queryClient.invalidateQueries( {
				queryKey: [ 'report', boardName ],
			} );
			queryClient.removeQueries( { queryKey: [ 'task', id ] } );
			queryClient.removeQueries( { queryKey: [ 'task_history', id ] } );
		},
	} );

	return { createTask, updateTask, deleteTask };
};
