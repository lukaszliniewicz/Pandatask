import { lazy } from 'react';

const wait = ( milliseconds ) =>
	new Promise( ( resolve ) => window.setTimeout( resolve, milliseconds ) );

export const lazyWithRetry = ( importer ) =>
	lazy( async () => {
		try {
			return await importer();
		} catch ( firstError ) {
			await wait( 300 );
			try {
				return await importer();
			} catch {
				throw firstError;
			}
		}
	} );
