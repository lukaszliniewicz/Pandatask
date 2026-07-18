import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useCategoryMutations = () => {
	const { apiClient, boardName } = useConfig();
	const queryClient = useQueryClient();

	const createCategory = useMutation( {
		mutationFn: async ( variables ) => {
			const name =
				typeof variables === 'string' ? variables : variables.name;
			const targetBoard =
				typeof variables === 'string'
					? boardName
					: variables.boardName || boardName;
			const response = await apiClient.post(
				`boards/${ targetBoard }/categories`,
				{ name }
			);
			return response.category;
		},
		onSuccess: ( _, variables ) => {
			const targetBoard =
				typeof variables === 'string'
					? boardName
					: variables.boardName || boardName;
			queryClient.invalidateQueries( {
				queryKey: [ 'categories', targetBoard ],
			} );
		},
	} );

	const deleteCategory = useMutation( {
		mutationFn: async ( id ) => {
			await apiClient.delete( `categories/${ id }`, {
				params: { board_name: boardName },
			} );
		},
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: [ 'categories', boardName ],
			} );
			queryClient.invalidateQueries( {
				queryKey: [ 'tasks', boardName ],
			} );
		},
	} );

	return { createCategory, deleteCategory };
};
