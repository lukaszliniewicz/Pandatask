import axios from 'axios';

export const createApiClient = ( config ) => {
	const { root, nonce } = config;

	const instance = axios.create( {
		baseURL: root,
		headers: {
			'X-WP-Nonce': nonce,
			'Content-Type': 'application/json',
		},
	} );

	// Add interceptor for WP-specific error handling if needed
	instance.interceptors.response.use(
		( response ) => response.data,
		( error ) => {
			const message =
				error.response?.data?.message ||
				error.message ||
				'An unknown error occurred';
			return Promise.reject( new Error( message ) );
		}
	);

	return instance;
};
