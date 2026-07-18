import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useTaskHistory = ( taskId ) => {
	const { apiClient } = useConfig();

	return useQuery( {
		queryKey: [ 'task_history', taskId ],
		queryFn: async () => {
			if ( ! taskId ) {
				return [];
			}
			const response = await apiClient.get( `tasks/${ taskId }/history` );
			return response.history;
		},
		enabled: !! taskId,
	} );
};
