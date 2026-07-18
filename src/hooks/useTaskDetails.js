import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useTaskDetails = ( taskId ) => {
	const { apiClient } = useConfig();

	return useQuery( {
		queryKey: [ 'task', taskId ],
		queryFn: async () => {
			if ( ! taskId ) {
				return null;
			}
			const response = await apiClient.get( `tasks/${ taskId }` );
			return response.task;
		},
		enabled: !! taskId,
	} );
};
