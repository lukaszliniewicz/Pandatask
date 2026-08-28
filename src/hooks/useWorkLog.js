import {
	useInfiniteQuery,
	useMutation,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useActivityTypes = () => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.work.activityTypes(),
		queryFn: async ( { signal } ) => {
			const response = await apiClient.get( 'work/activity-types', {
				signal,
			} );
			return response.activity_types || [];
		},
		staleTime: Infinity,
	} );
};

export const useWorkEntries = ( filters = {} ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.work.entries( filters ),
		queryFn: async ( { signal } ) => {
			const response = await apiClient.get( 'users/me/work-entries', {
				params: filters,
				signal,
			} );
			return response.entries || [];
		},
	} );
};

export const useInfiniteWorkEntries = ( filters = {}, pageSize = 40 ) => {
	const { apiClient } = useConfig();
	return useInfiniteQuery( {
		queryKey: queryKeys.work.entries( { ...filters, pageSize } ),
		queryFn: async ( { pageParam = 0, signal } ) => {
			const response = await apiClient.get( 'users/me/work-entries', {
				params: {
					...filters,
					limit: pageSize,
					offset: pageParam,
				},
				signal,
			} );
			return response.entries || [];
		},
		initialPageParam: 0,
		getNextPageParam: ( lastPage, pages ) =>
			lastPage.length === pageSize ? pages.length * pageSize : undefined,
	} );
};

export const useWorkSuggestions = ( filters = {} ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.work.suggestions( filters ),
		queryFn: async ( { signal } ) => {
			const response = await apiClient.get( 'users/me/work-suggestions', {
				params: filters,
				signal,
			} );
			return response.suggestions || [];
		},
	} );
};

export const useWorkReport = ( filters = {}, options = {} ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.work.report( filters ),
		queryFn: async ( { signal } ) =>
			apiClient.get( 'users/me/work-report', {
				params: filters,
				signal,
			} ),
		enabled: options.enabled !== false,
	} );
};

export const useWorkLogSharing = ( options = {} ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.work.sharing(),
		queryFn: ( { signal } ) =>
			apiClient.get( 'users/me/work-log-sharing', { signal } ),
		enabled: options.enabled !== false,
	} );
};

export const useGroupWorkLogs = ( groupId, filters = {} ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.work.groupPresenters( groupId, filters ),
		queryFn: ( { signal } ) =>
			apiClient.get( `groups/${ groupId }/work-logs`, {
				params: filters,
				signal,
			} ),
		enabled: Boolean( groupId ),
	} );
};

export const useInfiniteSharedWorkLog = (
	groupId,
	userId,
	filters = {},
	pageSize = 40
) => {
	const { apiClient } = useConfig();
	return useInfiniteQuery( {
		queryKey: queryKeys.work.groupLog( groupId, userId, {
			...filters,
			pageSize,
		} ),
		queryFn: ( { pageParam = 0, signal } ) =>
			apiClient.get( `groups/${ groupId }/work-logs/${ userId }`, {
				params: {
					...filters,
					limit: pageSize,
					offset: pageParam,
				},
				signal,
			} ),
		initialPageParam: 0,
		getNextPageParam: ( lastPage, pages ) => {
			const pagination = lastPage?.pagination;
			if ( pagination && typeof pagination.has_more === 'boolean' ) {
				return pagination.has_more &&
					pagination.next_offset !== null &&
					pagination.next_offset !== undefined
					? Number( pagination.next_offset )
					: undefined;
			}
			return ( lastPage.entries || [] ).length === pageSize
				? pages.length * pageSize
				: undefined;
		},
		enabled: Boolean( groupId && userId ),
	} );
};

export const useBoardWorkReport = ( filters = {}, options = {} ) => {
	const { apiClient, boardName } = useConfig();
	const isUserBoard = boardName?.startsWith( 'user_' );
	return useQuery( {
		queryKey: queryKeys.work.boardReport( boardName, filters ),
		queryFn: async ( { signal } ) =>
			apiClient.get( `boards/${ boardName }/work-report`, {
				params: filters,
				signal,
			} ),
		enabled:
			Boolean( boardName ) && ! isUserBoard && options.enabled !== false,
	} );
};

export const useTaskWork = ( taskId ) => {
	const { apiClient } = useConfig();
	return useQuery( {
		queryKey: queryKeys.taskWork( taskId ),
		queryFn: async ( { signal } ) =>
			apiClient.get( `tasks/${ taskId }/work`, { signal } ),
		enabled: Boolean( taskId ),
	} );
};

export const useWorkMutations = () => {
	const { apiClient, boardName } = useConfig();
	const queryClient = useQueryClient();
	const taskIdsFromAllocations = ( allocations = [] ) =>
		Array.from(
			new Set(
				allocations
					.map( ( allocation ) =>
						Number(
							allocation?.task_id ??
								allocation?.task_id_snapshot ??
								allocation?.taskId
						)
					)
					.filter( Boolean )
			)
		);
	const entriesFromCache = ( data ) => {
		if ( Array.isArray( data ) ) {
			return data;
		}
		if ( Array.isArray( data?.pages ) ) {
			return data.pages.flatMap( ( page ) =>
				Array.isArray( page ) ? page : page?.entries || []
			);
		}
		return [];
	};
	const taskIdsForCachedEntry = ( entryId ) => {
		const taskIds = [];
		const entriesQueries = queryClient.getQueriesData( {
			queryKey: [ ...queryKeys.work.all(), 'entries' ],
		} );
		for ( const [ , data ] of entriesQueries ) {
			const entry = entriesFromCache( data ).find(
				( item ) => Number( item?.id ) === Number( entryId )
			);
			if ( entry ) {
				taskIds.push( ...taskIdsFromAllocations( entry.allocations ) );
			}
		}
		return Array.from( new Set( taskIds ) );
	};
	const invalidate = ( taskIds = [] ) => {
		queryClient.invalidateQueries( { queryKey: queryKeys.work.all() } );
		queryClient.invalidateQueries( {
			queryKey: queryKeys.reports.board( boardName ),
		} );
		Array.from(
			new Set( taskIds.map( Number ).filter( Boolean ) )
		).forEach( ( id ) =>
			queryClient.invalidateQueries( {
				queryKey: queryKeys.taskWork( id ),
			} )
		);
	};

	const createEntry = useMutation( {
		mutationFn: async ( data ) => {
			const response = await apiClient.post(
				'users/me/work-entries',
				data
			);
			return response.entry;
		},
		onSuccess: ( _, variables ) =>
			invalidate(
				( variables.allocations || [] ).map( ( item ) => item.task_id )
			),
	} );

	const updateEntry = useMutation( {
		mutationFn: async ( { id, data } ) => {
			const response = await apiClient.patch(
				`work-entries/${ id }`,
				data
			);
			return response.entry;
		},
		onMutate: ( { id, previousAllocations = [] } ) => ( {
			taskIds: Array.from(
				new Set( [
					...taskIdsForCachedEntry( id ),
					...taskIdsFromAllocations( previousAllocations ),
				] )
			),
		} ),
		onSuccess: ( entry, variables, context ) =>
			invalidate( [
				...( context?.taskIds || [] ),
				...taskIdsFromAllocations( entry?.allocations ),
				...taskIdsFromAllocations( variables.data?.allocations ),
			] ),
	} );

	const deleteEntry = useMutation( {
		mutationFn: async ( { id } ) =>
			apiClient.delete( `work-entries/${ id }` ),
		onMutate: ( { id } ) => ( {
			taskIds: taskIdsForCachedEntry( id ),
		} ),
		onSuccess: ( _, __, context ) => invalidate( context?.taskIds || [] ),
	} );

	const confirmSuggestion = useMutation( {
		mutationFn: async ( data ) =>
			apiClient.post( 'users/me/work-suggestions/confirm', data ),
		onSuccess: () => invalidate(),
	} );

	const dismissSuggestion = useMutation( {
		mutationFn: async ( data ) =>
			apiClient.post( 'users/me/work-suggestions/dismiss', data ),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: queryKeys.work.all() } ),
	} );

	const resolveTaskTime = useMutation( {
		mutationFn: async ( {
			taskId,
			actualSeconds = null,
			notTracked = false,
			workItems = [],
			residual = {},
		} ) => {
			const response = await apiClient.post(
				`tasks/${ taskId }/time-resolution`,
				{
					actual_seconds: actualSeconds,
					not_tracked: notTracked,
					...( workItems.length ? { work_items: workItems } : {} ),
					...( Object.keys( residual || {} ).length
						? { residual }
						: {} ),
				}
			);
			return response.time;
		},
		onSuccess: ( _, variables ) => invalidate( [ variables.taskId ] ),
	} );

	const resolveOccurrenceTime = useMutation( {
		mutationFn: async ( {
			occurrenceId,
			actualSeconds = null,
			notTracked = false,
			workItems = [],
			residual = {},
		} ) => {
			const response = await apiClient.post(
				`work-occurrences/${ occurrenceId }/time-resolution`,
				{
					actual_seconds: actualSeconds,
					not_tracked: notTracked,
					...( workItems.length ? { work_items: workItems } : {} ),
					...( Object.keys( residual || {} ).length
						? { residual }
						: {} ),
				}
			);
			return response.time;
		},
		onSuccess: () => invalidate(),
	} );

	const createActivityType = useMutation( {
		mutationFn: async ( data ) => {
			const response = await apiClient.post(
				'work/activity-types',
				data
			);
			return response.activity_type;
		},
		onSuccess: () =>
			queryClient.invalidateQueries( {
				queryKey: queryKeys.work.activityTypes(),
			} ),
	} );

	const updateActivityType = useMutation( {
		mutationFn: async ( { key, data } ) => {
			const response = await apiClient.patch(
				`work/activity-types/${ encodeURIComponent( key ) }`,
				data
			);
			return response.activity_type;
		},
		onSuccess: () =>
			queryClient.invalidateQueries( {
				queryKey: queryKeys.work.activityTypes(),
			} ),
	} );

	const archiveActivityType = useMutation( {
		mutationFn: async ( { key } ) =>
			apiClient.delete(
				`work/activity-types/${ encodeURIComponent( key ) }`
			),
		onSuccess: () =>
			queryClient.invalidateQueries( {
				queryKey: queryKeys.work.activityTypes(),
			} ),
	} );

	const updateSharing = useMutation( {
		mutationFn: ( groupIds ) =>
			apiClient.put( 'users/me/work-log-sharing', {
				group_ids: groupIds,
			} ),
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: queryKeys.work.sharing(),
			} );
			queryClient.invalidateQueries( {
				queryKey: [ ...queryKeys.work.all(), 'group-presenters' ],
			} );
		},
	} );

	return {
		createEntry,
		updateEntry,
		deleteEntry,
		confirmSuggestion,
		dismissSuggestion,
		resolveTaskTime,
		resolveOccurrenceTime,
		createActivityType,
		updateActivityType,
		archiveActivityType,
		updateSharing,
	};
};
