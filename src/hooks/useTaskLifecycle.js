import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useTaskFollowUps = ( taskId ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.taskFollowUps( taskId || 0 ),
		queryFn: async ( { signal } ) => {
			const response = await apiClient.get(
				`tasks/${ taskId }/follow-ups`,
				{ signal }
			);
			return response.tasks || [];
		},
		enabled: Boolean( taskId ),
	} );
};

export const useTaskLifecycleMutations = () => {
	const { apiClient, boardName } = useConfig();
	const queryClient = useQueryClient();

	const invalidateTask = ( taskId, boards = [] ) => {
		queryClient.invalidateQueries( { queryKey: queryKeys.task( taskId ) } );
		queryClient.invalidateQueries( {
			queryKey: queryKeys.taskHistory( taskId ),
		} );
		queryClient.invalidateQueries( {
			queryKey: queryKeys.taskWork( taskId ),
		} );
		queryClient.invalidateQueries( {
			queryKey: queryKeys.taskFollowUps( taskId ),
		} );
		queryClient.invalidateQueries( { queryKey: queryKeys.work.all() } );
		[ boardName, ...boards ].filter( Boolean ).forEach( ( board ) => {
			queryClient.invalidateQueries( {
				queryKey: queryKeys.tasks.board( board ),
			} );
			queryClient.invalidateQueries( {
				queryKey: queryKeys.reports.board( board ),
			} );
		} );
		queryClient.invalidateQueries( { queryKey: queryKeys.inbox.all() } );
	};

	const previewMove = useMutation( {
		mutationFn: ( { taskId, data } ) =>
			apiClient.post( `tasks/${ taskId }/move-preview`, data ),
	} );

	const moveTask = useMutation( {
		mutationFn: ( { taskId, data } ) =>
			apiClient.post( `tasks/${ taskId }/move`, data ),
		onSuccess: ( response, variables ) => {
			invalidateTask( variables.taskId, [
				response?.plan?.source_board,
				response?.plan?.destination_board,
			] );
		},
	} );

	const createFollowUp = useMutation( {
		mutationFn: async ( { taskId, data } ) => {
			const response = await apiClient.post(
				`tasks/${ taskId }/follow-ups`,
				data
			);
			return response.task;
		},
		onSuccess: ( task, variables ) => {
			invalidateTask( variables.taskId, [ task?.board_name ] );
		},
	} );

	return { previewMove, moveTask, createFollowUp };
};
