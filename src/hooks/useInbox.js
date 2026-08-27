import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useInbox = ( ownerUserId = null, filters = {} ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.inbox.list( ownerUserId, filters ),
		queryFn: ( { signal } ) =>
			apiClient.get(
				ownerUserId ? `users/${ ownerUserId }/inbox` : 'users/me/inbox',
				{ params: filters, signal }
			),
	} );
};

export const useInboxDelegates = () => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.inbox.delegates(),
		queryFn: ( { signal } ) =>
			apiClient.get( 'users/me/inbox/delegates', { signal } ),
	} );
};

export const useSharedInboxes = () => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.inbox.shared(),
		queryFn: ( { signal } ) =>
			apiClient.get( 'users/me/inbox/shared-with-me', { signal } ),
	} );
};

export const useInboxMutations = () => {
	const { apiClient, boardName } = useConfig();
	const queryClient = useQueryClient();
	const invalidate = () => {
		queryClient.invalidateQueries( { queryKey: queryKeys.inbox.all() } );
		queryClient.invalidateQueries( { queryKey: queryKeys.tasks.all() } );
		queryClient.invalidateQueries( {
			queryKey: queryKeys.reports.board( boardName ),
		} );
	};

	const capture = useMutation( {
		mutationFn: async ( { ownerUserId = null, data } ) => {
			const response = await apiClient.post(
				ownerUserId ? `users/${ ownerUserId }/inbox` : 'users/me/inbox',
				data
			);
			return response.task;
		},
		onSuccess: invalidate,
	} );

	const setState = useMutation( {
		mutationFn: async ( { taskId, state } ) => {
			const response = await apiClient.post(
				`tasks/${ taskId }/inbox-state`,
				{ state }
			);
			return response.task;
		},
		onSuccess: ( _, variables ) => {
			invalidate();
			queryClient.invalidateQueries( {
				queryKey: queryKeys.task( variables.taskId ),
			} );
		},
	} );

	const replaceDelegates = useMutation( {
		mutationFn: ( delegates ) =>
			apiClient.put( 'users/me/inbox/delegates', { delegates } ),
		onSuccess: invalidate,
	} );

	return { capture, setState, replaceDelegates };
};
