import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useProjects = ( overrideBoardName ) => {
	const { apiClient, boardName: contextBoardName } = useConfig();
	const activeBoard = overrideBoardName || contextBoardName;

	return useQuery( {
		queryKey: [ 'projects', activeBoard ],
		queryFn: async () => {
			if ( ! activeBoard ) {
				return [];
			}
			const response = await apiClient.get(
				`boards/${ activeBoard }/projects`
			);
			return response.projects;
		},
		staleTime: 60000, // 1 minute
		enabled: !! activeBoard,
	} );
};
