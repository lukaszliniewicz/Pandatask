import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useUserBoards = () => {
	const { apiClient, currentUser } = useConfig();

	return useQuery( {
		queryKey: [ 'user_boards', currentUser?.id ],
		queryFn: async () => {
			const response = await apiClient.get( `users/me/boards` );
			return response.boards;
		},
		enabled: !! currentUser?.id,
		staleTime: 5 * 60 * 1000,
	} );
};
