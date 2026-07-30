import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useUserBoards = () => {
	const { apiClient, currentUser } = useConfig();

	return useQuery( {
		queryKey: queryKeys.userBoards( currentUser?.id ),
		queryFn: async ( { signal } ) => {
			const response = await apiClient.get( `users/me/boards`, {
				signal,
			} );
			return response.boards;
		},
		enabled: !! currentUser?.id,
		staleTime: 5 * 60 * 1000,
	} );
};
