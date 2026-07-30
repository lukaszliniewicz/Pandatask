import React, { useMemo } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import TaskBoard from './components/TaskBoard';
import BugTracker from './components/BugTracker';
import FloatingBugReporter from './components/FloatingBugReporter';
import AppErrorBoundary from './components/AppErrorBoundary';
import { ConfigProvider } from './context/ConfigContext';
import { createApiClient } from './api/client';
import { createPandataskQueryClient } from './query/createQueryClient';
import '../assets/scss/main.scss';

const mountedRoots = new WeakMap();

const applyPandataskBoundary = ( root ) => {
	if ( ! root ) {
		return;
	}

	// The mount node is deliberately separate from the application shell. Keeping
	// `.pandat69-container` on both levels makes host-theme list/layout rules leak
	// into the nested board and causes duplicated spacing on shortcode pages.
	root.classList.remove( 'pandat69-container', 'pandat69-viewport-shell' );
	root.classList.add(
		'pandat69-mount',
		'pandat69-root',
		'iarf-app',
		'iarf-app--pandatask',
		'iarf-plugin',
		'iarf-plugin--pandatask'
	);
	root.setAttribute( 'data-iarf-product', 'pandatask' );
	root.setAttribute( 'data-iarf-app', 'pandatask' );
	root.setAttribute( 'data-iarf-plugin', 'pandatask' );
	root.setAttribute( 'data-iarf-product-kind', 'react-plugin' );
};

const renderInto = ( container, element ) => {
	if ( ! container ) {
		throw new Error( 'A valid PandaTask mount container is required.' );
	}

	applyPandataskBoundary( container );
	let record = mountedRoots.get( container );

	if ( ! record ) {
		container.replaceChildren();
		record = { root: createRoot( container ), token: null };
		mountedRoots.set( container, record );
	}

	const token = Symbol( 'pandatask-mount' );
	record.token = token;
	record.root.render( element );
	container.dataset.reactMounted = 'true';

	return () => {
		const current = mountedRoots.get( container );
		if ( ! current || current.token !== token ) {
			return;
		}
		current.root.unmount();
		mountedRoots.delete( container );
		delete container.dataset.reactMounted;
	};
};

// Wrapper for simple components that need API context but not the full board.
const AppWrapper = ( { apiSettings, currentUser, children, boardName } ) => {
	const queryClient = useMemo( () => createPandataskQueryClient(), [] );
	const apiClient = useMemo(
		() =>
			apiSettings.apiClient ||
			createApiClient( {
				root: apiSettings.root,
				nonce: apiSettings.nonce,
			} ),
		[ apiSettings.apiClient, apiSettings.nonce, apiSettings.root ]
	);
	const config = useMemo(
		() => ( {
			boardName,
			apiClient,
			currentUser,
			isStandalone: true,
			text: apiSettings.text || {},
		} ),
		[ apiClient, apiSettings.text, boardName, currentUser ]
	);

	return (
		<ConfigProvider config={ config }>
			<QueryClientProvider client={ queryClient }>
				<AppErrorBoundary>{ children }</AppErrorBoundary>
			</QueryClientProvider>
		</ConfigProvider>
	);
};

const resolveSettings = ( apiSettings ) =>
	apiSettings || window.pandatask_api_settings || {};

const resolveCurrentUser = ( settings, currentUser ) =>
	currentUser || {
		id: settings.current_user_id,
		name: settings.current_user_display_name,
	};

const mountBoard = ( container, props = {} ) => {
	const settings = resolveSettings( props.apiSettings );
	return renderInto(
		container,
		<AppErrorBoundary>
			<TaskBoard
				boardName={ props.boardName || container?.dataset?.boardName }
				apiSettings={ settings }
				currentUser={ resolveCurrentUser( settings, props.currentUser ) }
				isStandalone={ props.isStandalone ?? true }
			/>
		</AppErrorBoundary>
	);
};

const mountFloatingBugReporter = ( container, props = {} ) => {
	const settings = resolveSettings( props.apiSettings );
	const boardName = props.boardName || container?.dataset?.boardName;
	return renderInto(
		container,
		<AppErrorBoundary>
			<AppWrapper
				apiSettings={ settings }
				currentUser={ resolveCurrentUser( settings, props.currentUser ) }
				boardName={ boardName }
			>
				<FloatingBugReporter
					boardName={ boardName }
					defaultAssigneeId={
						props.defaultAssigneeId ||
						container?.dataset?.defaultAssigneeId
					}
					initialOpen={ props.initialOpen || false }
				/>
			</AppWrapper>
		</AppErrorBoundary>
	);
};

// Merge with integrations registered by the host instead of replacing the
// shared namespace.
window.Pandatask = {
	...( window.Pandatask || {} ),
	mountBoard,
	mountFloatingBugReporter,
};

const bootstrapStandaloneMounts = () => {
	const apiSettings = resolveSettings();
	const currentUser = resolveCurrentUser( apiSettings );

	document
		.querySelectorAll( '[data-pandatask-board-root]' )
		.forEach( ( container ) => {
			if ( container.dataset.reactMounted !== 'true' ) {
				mountBoard( container, {
					boardName: container.dataset.boardName,
					apiSettings,
					currentUser,
					isStandalone: true,
				} );
			}
		} );

	document
		.querySelectorAll( '.pandat69-bug-tracker-container' )
		.forEach( ( container ) => {
			if ( container.dataset.reactMounted === 'true' ) {
				return;
			}
			const { boardName, defaultAssigneeId } = container.dataset;
			renderInto(
				container,
				<AppErrorBoundary>
					<AppWrapper
						apiSettings={ apiSettings }
						currentUser={ currentUser }
						boardName={ boardName }
					>
						<BugTracker
							boardName={ boardName }
							defaultAssigneeId={ defaultAssigneeId }
						/>
					</AppWrapper>
				</AppErrorBoundary>
			);
		} );

	const floatingContainer = document.getElementById(
		'pandat69-floating-bug-reporter-root'
	);
	if (
		floatingContainer &&
		floatingContainer.dataset.reactMounted !== 'true'
	) {
		mountFloatingBugReporter( floatingContainer, {
			boardName: floatingContainer.dataset.boardName,
			defaultAssigneeId: floatingContainer.dataset.defaultAssigneeId,
			apiSettings,
			currentUser,
		} );
	}
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', bootstrapStandaloneMounts, {
		once: true,
	} );
} else {
	queueMicrotask( bootstrapStandaloneMounts );
}
