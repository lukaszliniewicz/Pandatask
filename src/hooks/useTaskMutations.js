import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

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
				queryKey: queryKeys.tasks.board( targetBoard ),
			} );
			if ( targetBoard !== boardName ) {
				queryClient.invalidateQueries( {
					queryKey: queryKeys.tasks.board( boardName ),
				} );
				queryClient.invalidateQueries( {
					queryKey: queryKeys.reports.board( boardName ),
				} );
			}
			queryClient.invalidateQueries( {
				queryKey: queryKeys.reports.board( targetBoard ),
			} );
			queryClient.invalidateQueries( {
				queryKey: queryKeys.projects.all(),
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
				queryKey: queryKeys.tasks.board( boardName ),
			} );
			const previousTasks = queryClient.getQueriesData( {
				queryKey: queryKeys.tasks.board( boardName ),
			} );

			queryClient.setQueriesData(
				{ queryKey: queryKeys.tasks.board( boardName ) },
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
		onSettled: ( updatedTask, __, { id, data } ) => {
			queryClient.invalidateQueries( {
				queryKey: queryKeys.tasks.board( boardName ),
			} );
			queryClient.invalidateQueries( {
				queryKey: queryKeys.reports.board( boardName ),
			} );
			queryClient.invalidateQueries( { queryKey: queryKeys.task( id ) } );
			queryClient.invalidateQueries( {
				queryKey: queryKeys.taskHistory( id ),
			} );
			queryClient.invalidateQueries( {
				queryKey: queryKeys.projects.all(),
			} );

			const targetBoard = data.board_name || updatedTask?.board_name;
			if ( targetBoard && targetBoard !== boardName ) {
				queryClient.invalidateQueries( {
					queryKey: queryKeys.tasks.board( targetBoard ),
				} );
				queryClient.invalidateQueries( {
					queryKey: queryKeys.reports.board( targetBoard ),
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
				queryKey: queryKeys.tasks.board( boardName ),
			} );
			const previousTasks = queryClient.getQueriesData( {
				queryKey: queryKeys.tasks.board( boardName ),
			} );

			queryClient.setQueriesData(
				{ queryKey: queryKeys.tasks.board( boardName ) },
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
				queryKey: queryKeys.tasks.board( boardName ),
			} );
			queryClient.invalidateQueries( {
				queryKey: queryKeys.reports.board( boardName ),
			} );
			queryClient.removeQueries( { queryKey: queryKeys.task( id ) } );
			queryClient.removeQueries( {
				queryKey: queryKeys.taskHistory( id ),
			} );
			queryClient.invalidateQueries( {
				queryKey: queryKeys.projects.all(),
			} );
		},
	} );

	return { createTask, updateTask, deleteTask };
};
