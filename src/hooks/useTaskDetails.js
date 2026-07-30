import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useTaskDetails = ( taskId ) => {
	const { apiClient } = useConfig();

	return useQuery( {
		queryKey: queryKeys.task( taskId ),
		queryFn: async ( { signal } ) => {
			if ( ! taskId ) {
				return null;
			}
			const response = await apiClient.get( `tasks/${ taskId }`, {
				signal,
			} );
			return response.task;
		},
		enabled: !! taskId,
	} );
};
