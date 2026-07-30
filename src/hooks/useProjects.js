import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useProjects = ( overrideBoardName, options = {} ) => {
	const { apiClient, boardName: contextBoardName } = useConfig();
	const activeBoard = overrideBoardName || contextBoardName;
	const privateOnly = !! options.privateOnly;

	return useQuery( {
		queryKey: queryKeys.projects.list( activeBoard, privateOnly ),
		queryFn: async ( { signal } ) => {
			if ( ! activeBoard ) {
				return [];
			}
			const response = await apiClient.get(
				`boards/${ activeBoard }/projects`,
				{
					params: privateOnly ? { private_only: 'true' } : {},
					signal,
				}
			);
			return response.projects;
		},
		staleTime: 60000, // 1 minute
		enabled: !! activeBoard,
	} );
};
