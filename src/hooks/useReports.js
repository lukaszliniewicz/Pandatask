import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';
import { queryKeys } from '../query/queryKeys';

export const useReports = ( filters ) => {
	const { apiClient, boardName } = useConfig();

	return useQuery( {
		queryKey: queryKeys.reports.detail( boardName, filters ),
		queryFn: async ( { signal } ) => {
			const params = new URLSearchParams();
			if ( filters.period ) {
				params.append( 'period', filters.period );
			}
			if ( filters.start_date ) {
				params.append( 'start_date', filters.start_date );
			}
			if ( filters.end_date ) {
				params.append( 'end_date', filters.end_date );
			}

			const response = await apiClient.get(
				`boards/${ boardName }/report`,
				{ params, signal }
			);
			return response;
		},
		staleTime: 5 * 60 * 1000,
		enabled:
			!! filters.period &&
			( filters.period !== 'custom' ||
				( !! filters.start_date && !! filters.end_date ) ),
	} );
};
