import React, { useEffect, useRef, useState } from 'react';
import Icon from './Icon';

const QuickAddMenu = ( { workLogEnabled, onAddTask, onLogWork } ) => {
	const [ open, setOpen ] = useState( false );
	const containerRef = useRef( null );
	const firstItemRef = useRef( null );
	const menuRef = useRef( null );
	const triggerRef = useRef( null );

	useEffect( () => {
		if ( ! open ) return undefined;
		const closeOnOutsideClick = ( event ) => {
			if ( ! containerRef.current?.contains( event.target ) )
				setOpen( false );
		};
		const closeOnEscape = ( event ) => {
			if ( event.key === 'Escape' ) {
				setOpen( false );
				triggerRef.current?.focus();
			}
		};
		document.addEventListener( 'pointerdown', closeOnOutsideClick );
		document.addEventListener( 'keydown', closeOnEscape );
		firstItemRef.current?.focus();
		return () => {
			document.removeEventListener( 'pointerdown', closeOnOutsideClick );
			document.removeEventListener( 'keydown', closeOnEscape );
		};
	}, [ open ] );

	if ( ! workLogEnabled ) {
		return (
			<button
				type="button"
				className="pandat69-icon-button pandat69-add-task-btn"
				title="Add new task"
				onClick={ onAddTask }
				aria-label="Add new task"
			>
				<Icon name="plus" />
			</button>
		);
	}

	const choose = ( callback ) => {
		setOpen( false );
		triggerRef.current?.focus();
		callback();
	};

	const navigateMenu = ( event ) => {
		if (
			! [ 'ArrowDown', 'ArrowUp', 'Home', 'End' ].includes( event.key )
		) {
			return;
		}
		event.preventDefault();
		const items = Array.from(
			menuRef.current?.querySelectorAll( '[role="menuitem"]' ) || []
		);
		if ( ! items.length ) return;
		const currentIndex = items.indexOf( document.activeElement );
		let nextIndex = event.key === 'End' ? items.length - 1 : 0;
		if ( event.key === 'ArrowDown' ) {
			nextIndex = ( currentIndex + 1 ) % items.length;
		}
		if ( event.key === 'ArrowUp' ) {
			nextIndex = ( currentIndex - 1 + items.length ) % items.length;
		}
		items[ nextIndex ].focus();
	};

	return (
		<div className="pandat69-quick-add" ref={ containerRef }>
			<button
				ref={ triggerRef }
				type="button"
				className="pandat69-icon-button pandat69-add-task-btn pandat69-quick-add-trigger"
				title="Add…"
				onClick={ () => setOpen( ( current ) => ! current ) }
				aria-label="Add task or log work"
				aria-haspopup="menu"
				aria-expanded={ open }
			>
				<Icon name="plus" />
			</button>
			{ open && (
				<div
					ref={ menuRef }
					className="pandat69-quick-add-menu"
					role="menu"
					aria-label="Add"
					onKeyDown={ navigateMenu }
				>
					<button
						ref={ firstItemRef }
						type="button"
						role="menuitem"
						onClick={ () => choose( onAddTask ) }
					>
						<span className="pandat69-quick-add-icon">
							<Icon name="list-plus" />
						</span>
						<span>
							<strong>New task</strong>
							<small>Create something to do</small>
						</span>
					</button>
					<button
						type="button"
						role="menuitem"
						onClick={ () => choose( onLogWork ) }
					>
						<span className="pandat69-quick-add-icon is-work">
							<Icon name="clock" />
						</span>
						<span>
							<strong>Log work</strong>
							<small>Record something you did</small>
						</span>
					</button>
				</div>
			) }
		</div>
	);
};

export default QuickAddMenu;
