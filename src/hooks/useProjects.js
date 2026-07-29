import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useProjects = ( overrideBoardName, options = {} ) => {
	const { apiClient, boardName: contextBoardName } = useConfig();
	const activeBoard = overrideBoardName || contextBoardName;
	const privateOnly = !! options.privateOnly;

	return useQuery( {
		queryKey: [ 'projects', activeBoard, { privateOnly } ],
		queryFn: async () => {
			if ( ! activeBoard ) {
				return [];
			}
			const response = await apiClient.get(
				`boards/${ activeBoard }/projects`,
				{
					params: privateOnly ? { private_only: 'true' } : {},
				}
			);
			return response.projects;
		},
		staleTime: 60000, // 1 minute
		enabled: !! activeBoard,
	} );
};
