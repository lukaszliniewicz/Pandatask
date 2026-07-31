import { useEffect, useRef, useState } from 'react';

export const useBoardFullscreen = () => {
	const [ isFullscreen, setIsFullscreen ] = useState( false );
	const fullscreenToggleRef = useRef( null );
	const shouldRestoreFocusRef = useRef( false );

	useEffect( () => {
		if ( ! isFullscreen ) {
			return undefined;
		}

		document.body.classList.add( 'pandat69-viewport-open' );
		const handleKeyDown = ( event ) => {
			if (
				event.key !== 'Escape' ||
				document.querySelector( '.pandat69-react-modal[open]' )
			) {
				return;
			}
			event.preventDefault();
			setIsFullscreen( false );
		};
		document.addEventListener( 'keydown', handleKeyDown );

		return () => {
			document.removeEventListener( 'keydown', handleKeyDown );
			window.requestAnimationFrame( () => {
				if ( ! document.querySelector( '.pandat69-viewport-shell' ) ) {
					document.body.classList.remove( 'pandat69-viewport-open' );
				}
			} );
		};
	}, [ isFullscreen ] );

	useEffect( () => {
		if ( isFullscreen ) {
			shouldRestoreFocusRef.current = true;
			return undefined;
		}
		if ( ! shouldRestoreFocusRef.current ) {
			return undefined;
		}

		shouldRestoreFocusRef.current = false;
		const focusFrame = window.requestAnimationFrame( () => {
			fullscreenToggleRef.current?.focus();
		} );
		return () => window.cancelAnimationFrame( focusFrame );
	}, [ isFullscreen ] );

	return {
		fullscreenToggleRef,
		isFullscreen,
		toggleFullscreen: () => setIsFullscreen( ( active ) => ! active ),
	};
};
