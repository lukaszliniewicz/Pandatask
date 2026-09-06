import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useTaskChecklist = ( taskId ) => {
	const { apiClient } = useConfig();
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( {
			items,
			expectedVersion,
			recurrenceScope,
			expectedSeriesVersion,
		} ) =>
			apiClient.post( `tasks/${ taskId }/checklist`, {
				items,
				expected_version: expectedVersion,
				...( recurrenceScope
					? {
							recurrence_scope: recurrenceScope,
							expected_series_version: expectedSeriesVersion,
					  }
					: {} ),
			} ),
		onMutate: async ( { items } ) => {
			await queryClient.cancelQueries( {
				queryKey: queryKeys.task( taskId ),
			} );
			const previous = queryClient.getQueryData(
				queryKeys.task( taskId )
			);
			if ( previous ) {
				queryClient.setQueryData( queryKeys.task( taskId ), {
					...previous,
					checklist: items,
					checklist_total: items.length,
					checklist_checked: items.filter( ( item ) => item.checked )
						.length,
				} );
			}
			return { previous };
		},
		onError: ( _, __, context ) => {
			if ( context?.previous ) {
				queryClient.setQueryData(
					queryKeys.task( taskId ),
					context.previous
				);
			}
		},
		onSuccess: ( checklist ) => {
			queryClient.setQueryData( queryKeys.task( taskId ), ( task ) =>
				task ? { ...task, ...checklist } : task
			);
		},
		onSettled: () =>
			Promise.all( [
				queryClient.invalidateQueries( {
					queryKey: [ 'task-recurrence' ],
				} ),
				queryClient.invalidateQueries( {
					queryKey: queryKeys.task( taskId ),
				} ),
				queryClient.invalidateQueries( {
					queryKey: queryKeys.tasks.all(),
				} ),
				queryClient.invalidateQueries( {
					queryKey: queryKeys.projects.all(),
				} ),
				queryClient.invalidateQueries( {
					queryKey: queryKeys.inbox.all(),
				} ),
				queryClient.invalidateQueries( {
					queryKey: queryKeys.taskHistory( taskId ),
				} ),
			] ),
	} );
};
