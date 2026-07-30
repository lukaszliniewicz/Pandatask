import { QueryClient } from '@tanstack/react-query';
import { PandataskApiError } from '../api/client';

const shouldRetry = ( failureCount: number, error: unknown ): boolean => {
	if ( failureCount >= 2 ) {
		return false;
	}

	if ( error instanceof PandataskApiError ) {
		if (
			error.canceled ||
			[ 400, 401, 403, 404, 409, 422 ].includes( error.status )
		) {
			return false;
		}
	}

	return true;
};

export const createPandataskQueryClient = (): QueryClient =>
	new QueryClient( {
		defaultOptions: {
			queries: {
				staleTime: 30_000,
				gcTime: 10 * 60_000,
				refetchOnWindowFocus: false,
				refetchOnReconnect: true,
				retry: shouldRetry,
			},
			mutations: {
				retry: false,
			},
		},
	} );
