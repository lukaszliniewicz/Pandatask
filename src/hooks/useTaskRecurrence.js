import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useTaskRecurrence = ( task, beforeSequence = null ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: [ 'task-recurrence', task?.id, beforeSequence ],
		placeholderData: keepPreviousData,
		enabled: Boolean( task?.recurrence_series_id ),
		queryFn: () =>
			apiClient.get( `tasks/${ task.id }/recurrence`, {
				params: {
					limit: 20,
					...( beforeSequence
						? { before_sequence: beforeSequence }
						: {} ),
				},
			} ),
	} );
};
