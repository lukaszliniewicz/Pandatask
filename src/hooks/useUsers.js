import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useUsers = (
	search = '',
	overrideBoardName,
	includeUserIds = []
) => {
	const { apiClient, boardName: contextBoardName } = useConfig();
	const activeBoard = overrideBoardName || contextBoardName;

	return useQuery( {
		queryKey: [ 'users', activeBoard, search, includeUserIds ],
		queryFn: async () => {
			if ( ! activeBoard ) {
				return [];
			}
			const params = new URLSearchParams();
			if ( search ) {
				params.append( 'search', search );
			}
			params.append( 'board_name', activeBoard );
			includeUserIds.forEach( ( userId ) =>
				params.append( 'include[]', userId )
			);

			const response = await apiClient.get( `users`, { params } );
			return response.users;
		},
		enabled: !! activeBoard,
		staleTime: 60000,
	} );
};
