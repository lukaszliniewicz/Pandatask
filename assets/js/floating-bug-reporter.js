( function () {
	const rootId = 'pandat69-floating-bug-reporter-root';
	const launcherId = 'pandat69-floating-bug-reporter-lite';
	const positionKey = 'pandat69_bug_widget_pos';
	const settings = window.pandatask_floating_reporter_settings || {};

	const onReady = ( callback ) => {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', callback, {
				once: true,
			} );
			return;
		}

		callback();
	};

	const ensureStylesheet = ( href ) => {
		if ( ! href || document.querySelector( `link[href="${ href }"]` ) ) {
			return;
		}

		const link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = href;
		link.dataset.pandataskLazy = 'full-style';
		document.head.appendChild( link );
	};

	const loadScript = ( src ) =>
		new Promise( ( resolve, reject ) => {
			if (
				window.Pandatask &&
				typeof window.Pandatask.mountFloatingBugReporter === 'function'
			) {
				resolve();
				return;
			}

			const existing = document.querySelector(
				'script[data-pandatask-lazy="full-script"]'
			);
			if ( existing ) {
				existing.addEventListener( 'load', () => resolve(), {
					once: true,
				} );
				existing.addEventListener(
					'error',
					() =>
						reject(
							new Error( 'Unable to load Pandatask reporter.' )
						),
					{ once: true }
				);
				return;
			}

			if ( ! src ) {
				reject(
					new Error( 'Pandatask reporter script URL is missing.' )
				);
				return;
			}

			const script = document.createElement( 'script' );
			script.src = src;
			script.async = true;
			script.dataset.pandataskLazy = 'full-script';
			script.addEventListener( 'load', () => resolve(), { once: true } );
			script.addEventListener(
				'error',
				() =>
					reject( new Error( 'Unable to load Pandatask reporter.' ) ),
				{ once: true }
			);
			document.body.appendChild( script );
		} );

	const applySavedPosition = ( launcher ) => {
		const savedPosition = window.localStorage
			? window.localStorage.getItem( positionKey )
			: null;
		if ( ! savedPosition ) {
			return;
		}

		try {
			const position = JSON.parse( savedPosition );
			if ( position.left && position.top ) {
				launcher.style.left = position.left;
				launcher.style.top = position.top;
				launcher.style.right = 'auto';
				launcher.style.bottom = 'auto';
			}
		} catch {
			window.localStorage.removeItem( positionKey );
		}
	};

	const makeDraggable = ( launcher ) => {
		let pointerOffsetX = 0;
		let pointerOffsetY = 0;
		let dragging = false;
		let moved = false;

		launcher.addEventListener( 'pointerdown', ( event ) => {
			if ( event.button !== 0 ) {
				return;
			}

			const rect = launcher.getBoundingClientRect();
			pointerOffsetX = event.clientX - rect.left;
			pointerOffsetY = event.clientY - rect.top;
			dragging = true;
			moved = false;
			launcher.setPointerCapture( event.pointerId );
			launcher.classList.add( 'is-dragging' );
		} );

		launcher.addEventListener( 'pointermove', ( event ) => {
			if ( ! dragging ) {
				return;
			}

			moved = true;
			const rect = launcher.getBoundingClientRect();
			const x = Math.max(
				0,
				Math.min(
					event.clientX - pointerOffsetX,
					window.innerWidth - rect.width
				)
			);
			const y = Math.max(
				0,
				Math.min(
					event.clientY - pointerOffsetY,
					window.innerHeight - rect.height
				)
			);

			launcher.style.left = `${ x }px`;
			launcher.style.top = `${ y }px`;
			launcher.style.right = 'auto';
			launcher.style.bottom = 'auto';
		} );

		launcher.addEventListener( 'pointerup', ( event ) => {
			if ( ! dragging ) {
				return;
			}

			dragging = false;
			launcher.classList.remove( 'is-dragging' );
			launcher.releasePointerCapture( event.pointerId );

			if ( moved && window.localStorage ) {
				const rect = launcher.getBoundingClientRect();
				window.localStorage.setItem(
					positionKey,
					JSON.stringify( {
						left: `${ Math.round( rect.left ) }px`,
						top: `${ Math.round( rect.top ) }px`,
					} )
				);

				window.setTimeout( () => {
					moved = false;
				}, 80 );
			}
		} );

		return () => moved;
	};

	const mountFullReporter = async ( root, button ) => {
		if ( root.dataset.pandataskLoading === 'true' ) {
			return;
		}

		root.dataset.pandataskLoading = 'true';
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		try {
			const apiSettings = settings.apiSettings || {};
			window.pandatask_api_settings = apiSettings;
			ensureStylesheet( settings.fullStyleUrl );
			await loadScript( settings.fullScriptUrl );

			if (
				! window.Pandatask ||
				typeof window.Pandatask.mountFloatingBugReporter !== 'function'
			) {
				throw new Error(
					'Pandatask reporter mount API is unavailable.'
				);
			}

			window.Pandatask.mountFloatingBugReporter( root, {
				boardName: root.dataset.boardName,
				defaultAssigneeId: root.dataset.defaultAssigneeId,
				apiSettings,
				currentUser: {
					id: apiSettings.current_user_id,
					name: apiSettings.current_user_display_name,
				},
				initialOpen: true,
			} );
		} catch ( error ) {
			root.dataset.pandataskLoading = 'false';
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			button.title = error.message || 'Unable to load reporter';
			button.classList.add( 'has-error' );
		}
	};

	onReady( () => {
		const root = document.getElementById( rootId );
		if ( ! root || root.dataset.reactMounted === 'true' ) {
			return;
		}

		root.innerHTML = '';

		const launcher = document.createElement( 'div' );
		launcher.id = launcherId;
		launcher.className = 'pandat69-floating-reporter-lite';

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'pandat69-floating-btn';
		button.title = 'Report a bug';
		button.setAttribute( 'aria-label', 'Report a bug' );
		button.innerHTML = '<span aria-hidden="true">!</span>';

		launcher.appendChild( button );
		root.appendChild( launcher );
		applySavedPosition( launcher );

		const wasDragged = makeDraggable( launcher );
		button.addEventListener( 'click', ( event ) => {
			if ( wasDragged() ) {
				event.preventDefault();
				return;
			}

			mountFullReporter( root, button );
		} );
	} );
} )();
