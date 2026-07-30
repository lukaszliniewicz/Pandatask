import axios, {
	type AxiosError,
	type AxiosRequestConfig,
	type AxiosResponse,
} from 'axios';

interface WordPressErrorPayload {
	code?: string;
	message?: string;
	data?: unknown;
}

export interface ApiClient {
	get: < T = unknown >(
		path: string,
		config?: AxiosRequestConfig
	) => Promise< T >;
	post: < T = unknown >(
		path: string,
		data?: unknown,
		config?: AxiosRequestConfig
	) => Promise< T >;
	put: < T = unknown >(
		path: string,
		data?: unknown,
		config?: AxiosRequestConfig
	) => Promise< T >;
	patch: < T = unknown >(
		path: string,
		data?: unknown,
		config?: AxiosRequestConfig
	) => Promise< T >;
	delete: < T = unknown >(
		path: string,
		config?: AxiosRequestConfig
	) => Promise< T >;
}

export interface ApiClientSettings {
	root: string;
	nonce: string;
}

export class PandataskApiError extends Error {
	readonly status: number;
	readonly code: string;
	readonly details: unknown;
	readonly canceled: boolean;

	constructor(
		message: string,
		{
			status = 0,
			code = 'pandatask_api_error',
			details,
			canceled = false,
			cause,
		}: {
			status?: number;
			code?: string;
			details?: unknown;
			canceled?: boolean;
			cause?: unknown;
		} = {}
	) {
		super( message, { cause } );
		this.name = 'PandataskApiError';
		this.status = status;
		this.code = code;
		this.details = details;
		this.canceled = canceled;
	}
}

const toApiError = ( error: unknown ): PandataskApiError => {
	if ( error instanceof PandataskApiError ) {
		return error;
	}

	if ( axios.isCancel( error ) ) {
		return new PandataskApiError( 'The request was cancelled.', {
			code: 'pandatask_request_cancelled',
			canceled: true,
			cause: error,
		} );
	}

	const axiosError = error as AxiosError< WordPressErrorPayload >;
	const payload = axiosError.response?.data;

	return new PandataskApiError(
		payload?.message ||
			axiosError.message ||
			'An unknown API error occurred.',
		{
			status: axiosError.response?.status || 0,
			code:
				payload?.code ||
				axiosError.code ||
				( axiosError.response
					? `http_${ axiosError.response.status }`
					: 'pandatask_network_error' ),
			details: payload?.data ?? payload,
			cause: error,
		}
	);
};

export const createApiClient = ( config: ApiClientSettings ): ApiClient => {
	const { root, nonce } = config;

	if ( ! root ) {
		throw new PandataskApiError(
			'The Pandatask REST API root is missing.',
			{
				code: 'pandatask_configuration_error',
			}
		);
	}

	const instance = axios.create( {
		baseURL: root.endsWith( '/' ) ? root : `${ root }/`,
		headers: {
			'X-WP-Nonce': nonce,
			'Content-Type': 'application/json',
		},
		timeout: 30_000,
	} );

	instance.interceptors.response.use(
		( response: AxiosResponse ) => response.data,
		( error: unknown ) => Promise.reject( toApiError( error ) )
	);

	// The interceptor intentionally unwraps AxiosResponse to the API payload.
	return instance as unknown as ApiClient;
};
