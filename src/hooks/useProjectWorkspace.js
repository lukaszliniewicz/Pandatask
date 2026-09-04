import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useProjectWorkspace = ( projectId ) => {
	const { apiClient } = useConfig();
	const normalizedId = Number( projectId );

	return useQuery( {
		queryKey: queryKeys.projects.workspace( normalizedId ),
		queryFn: ( { signal } ) =>
			apiClient.get( `projects/${ normalizedId }/workspace`, { signal } ),
		enabled: Number.isInteger( normalizedId ) && normalizedId > 0,
		staleTime: 30_000,
	} );
};
