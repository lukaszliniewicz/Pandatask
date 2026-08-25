import React, { useEffect, useId, useRef, useState } from 'react';
import Icon from '../Icon';

const WorkLogMenu = ( {
	label,
	icon,
	children,
	disabled = false,
	align = 'start',
} ) => {
	const [ open, setOpen ] = useState( false );
	const containerRef = useRef( null );
	const triggerRef = useRef( null );
	const menuRef = useRef( null );
	const menuId = useId();

	useEffect( () => {
		if ( ! open ) return undefined;
		const closeOnOutsideClick = ( event ) => {
			if ( ! containerRef.current?.contains( event.target ) ) {
				setOpen( false );
			}
		};
		const closeOnEscape = ( event ) => {
			if ( event.key === 'Escape' ) {
				setOpen( false );
				triggerRef.current?.focus();
			}
		};
		document.addEventListener( 'pointerdown', closeOnOutsideClick );
		document.addEventListener( 'keydown', closeOnEscape );
		window.requestAnimationFrame( () =>
			menuRef.current?.querySelector( '[role="menuitem"]' )?.focus()
		);
		return () => {
			document.removeEventListener( 'pointerdown', closeOnOutsideClick );
			document.removeEventListener( 'keydown', closeOnEscape );
		};
	}, [ open ] );

	const choose = ( callback ) => {
		setOpen( false );
		triggerRef.current?.focus();
		callback?.();
	};

	const navigate = ( event ) => {
		if ( ! [ 'ArrowDown', 'ArrowUp', 'Home', 'End' ].includes( event.key ) ) {
			return;
		}
		event.preventDefault();
		const items = Array.from(
			menuRef.current?.querySelectorAll( '[role="menuitem"]:not(:disabled)' ) || []
		);
		if ( ! items.length ) return;
		const current = items.indexOf( document.activeElement );
		let next = event.key === 'End' ? items.length - 1 : 0;
		if ( event.key === 'ArrowDown' ) next = ( current + 1 ) % items.length;
		if ( event.key === 'ArrowUp' ) {
			next = ( current - 1 + items.length ) % items.length;
		}
		items[ next ].focus();
	};

	return (
		<div className="pandat69-work-menu" ref={ containerRef }>
			<button
				ref={ triggerRef }
				type="button"
				className="pandat69-button pandat69-compact-control pandat69-work-menu-trigger"
				onClick={ () => setOpen( ( current ) => ! current ) }
				disabled={ disabled }
				aria-controls={ menuId }
				aria-expanded={ open }
				aria-haspopup="menu"
			>
				{ icon && <Icon name={ icon } size={ 16 } /> }
				<span>{ label }</span>
				<Icon name="chevron-down" size={ 14 } />
			</button>
			{ open && (
				<div
					id={ menuId }
					ref={ menuRef }
					className={ `pandat69-work-menu-popover is-${ align }` }
					role="menu"
					onKeyDown={ navigate }
				>
					{ children( choose ) }
				</div>
			) }
		</div>
	);
};

export default WorkLogMenu;
