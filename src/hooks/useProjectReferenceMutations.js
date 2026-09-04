import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useProjectReferenceMutations = ( projectId ) => {
	const { apiClient, boardName } = useConfig();
	const queryClient = useQueryClient();
	const normalizedId = Number( projectId );

	const refresh = () => {
		queryClient.invalidateQueries( {
			queryKey: queryKeys.projects.workspace( normalizedId ),
		} );
		queryClient.invalidateQueries( {
			queryKey: queryKeys.projects.references( normalizedId ),
		} );
		queryClient.invalidateQueries( {
			queryKey: queryKeys.projects.board( boardName ),
		} );
		queryClient.invalidateQueries( { queryKey: queryKeys.tasks.all() } );
	};

	const addReference = useMutation( {
		mutationFn: ( data ) =>
			apiClient.post( `projects/${ normalizedId }/references`, data ),
		onSuccess: refresh,
	} );

	const updateReference = useMutation( {
		mutationFn: ( { referenceKey, relationType } ) =>
			apiClient.patch(
				`projects/${ normalizedId }/references/${ referenceKey }`,
				{ relation_type: relationType }
			),
		onSuccess: refresh,
	} );

	const removeReference = useMutation( {
		mutationFn: ( referenceKey ) =>
			apiClient.delete(
				`projects/${ normalizedId }/references/${ referenceKey }`
			),
		onSuccess: refresh,
	} );

	return { addReference, updateReference, removeReference };
};
