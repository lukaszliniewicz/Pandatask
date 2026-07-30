import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useTaskHistory = ( taskId ) => {
	const { apiClient } = useConfig();

	return useQuery( {
		queryKey: queryKeys.taskHistory( taskId ),
		queryFn: async ( { signal } ) => {
			if ( ! taskId ) {
				return [];
			}
			const response = await apiClient.get( `tasks/${ taskId }/history`, {
				signal,
			} );
			return response.history;
		},
		enabled: !! taskId,
	} );
};
