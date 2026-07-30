import React from 'react';

class AppErrorBoundary extends React.Component {
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	static getDerivedStateFromError( error ) {
		return { error };
	}

	componentDidCatch( error, info ) {
		// Keep diagnostics available to site operators without rendering details
		// that may contain upstream response data.
		window.console?.error?.( 'PandaTask rendering error', error, info );
	}

	render() {
		if ( ! this.state.error ) {
			return this.props.children;
		}

		return (
			<div className="pandat69-error pandat69-fatal-error" role="alert">
				<strong>PandaTask could not render this view.</strong>
				<p>
					Refresh the page to retry loading the application. Your saved
					tasks have not been changed.
				</p>
				<button type="button" onClick={ () => window.location.reload() }>
					Refresh PandaTask
				</button>
			</div>
		);
	}
}

export default AppErrorBoundary;
