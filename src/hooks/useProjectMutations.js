import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useProjectMutations = () => {
	const { apiClient, boardName } = useConfig();
	const queryClient = useQueryClient();

	const createProject = useMutation( {
		mutationFn: async ( data ) => {
			// Ensure board_name is present
			const targetBoard = data.board_name || boardName;
			const payload = { ...data, board_name: targetBoard };
			const response = await apiClient.post(
				`boards/${ targetBoard }/projects`,
				payload
			);
			return response.project;
		},
		onSuccess: ( _, variables ) => {
			const targetBoard = variables.board_name || boardName;
			queryClient.invalidateQueries( {
				queryKey: [ 'projects', targetBoard ],
			} );
		},
	} );

	const updateProject = useMutation( {
		mutationFn: async ( { id, data } ) => {
			const response = await apiClient.post( `projects/${ id }`, data );
			return response.project;
		},
		onSuccess: ( _, { id } ) => {
			queryClient.invalidateQueries( {
				queryKey: [ 'projects', boardName ],
			} );
			queryClient.invalidateQueries( {
				queryKey: [ 'tasks', boardName ],
			} );
			queryClient.removeQueries( { queryKey: [ 'project', id ] } );
		},
	} );

	const deleteProject = useMutation( {
		mutationFn: async ( id ) => {
			await apiClient.delete( `projects/${ id }` );
		},
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: [ 'projects', boardName ],
			} );
			queryClient.invalidateQueries( {
				queryKey: [ 'tasks', boardName ],
			} );
		},
	} );

	return { createProject, updateProject, deleteProject };
};
