import { useCallback, useEffect, useState } from 'react';

const COMPACT_BOARD_WIDTH = 1080;

export const useContainerMode = () => {
	const [ container, setContainer ] = useState( null );
	const [ width, setWidth ] = useState( () => window.innerWidth );

	const containerRef = useCallback( ( element ) => {
		setContainer( element );
	}, [] );

	useEffect( () => {
		if ( ! container ) {
			return undefined;
		}

		const updateWidth = () => {
			const nextWidth = Math.round(
				container.getBoundingClientRect().width
			);
			if ( nextWidth > 0 ) {
				setWidth( nextWidth );
			}
		};

		updateWidth();
		if ( typeof window.ResizeObserver === 'function' ) {
			const observer = new window.ResizeObserver( updateWidth );
			observer.observe( container );
			return () => observer.disconnect();
		}

		window.addEventListener( 'resize', updateWidth );
		return () => window.removeEventListener( 'resize', updateWidth );
	}, [ container ] );

	return {
		containerRef,
		containerWidth: width,
		isContainerNarrow: width < COMPACT_BOARD_WIDTH,
	};
};
