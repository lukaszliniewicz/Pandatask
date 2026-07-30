import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useUsers = (
	search = '',
	overrideBoardName,
	includeUserIds = []
) => {
	const { apiClient, boardName: contextBoardName } = useConfig();
	const activeBoard = overrideBoardName || contextBoardName;

	return useQuery( {
		queryKey: queryKeys.users( activeBoard, search, includeUserIds ),
		queryFn: async ( { signal } ) => {
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

			const response = await apiClient.get( `users`, { params, signal } );
			return response.users;
		},
		enabled: !! activeBoard,
		staleTime: 60000,
	} );
};
