import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useVisibleTaskSearch = ( search ) => {
	const { apiClient } = useConfig();
	const normalizedSearch = String( search || '' ).trim();

	return useQuery( {
		queryKey: queryKeys.visibleTasks( normalizedSearch ),
		queryFn: async ( { signal } ) => {
			const response = await apiClient.get( 'users/me/tasks', {
				params: {
					search: normalizedSearch,
					status_filter: 'all',
					archived: 0,
					include_templates: false,
					limit: 50,
					fields: 'id,name,status,board_name,board_display_name,project_id,project_name',
				},
				signal,
			} );
			return response.tasks || [];
		},
		placeholderData: keepPreviousData,
		staleTime: 30_000,
		enabled: normalizedSearch.length >= 2,
	} );
};
