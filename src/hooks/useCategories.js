import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useCategories = ( overrideBoardName ) => {
	const { apiClient, boardName: contextBoardName } = useConfig();
	const activeBoard = overrideBoardName || contextBoardName;

	return useQuery( {
		queryKey: queryKeys.categories( activeBoard ),
		queryFn: async ( { signal } ) => {
			if ( ! activeBoard ) {
				return [];
			}
			const response = await apiClient.get(
				`boards/${ activeBoard }/categories`,
				{ signal }
			);
			return response.categories;
		},
		staleTime: 60000, // 1 minute
		enabled: !! activeBoard,
	} );
};
