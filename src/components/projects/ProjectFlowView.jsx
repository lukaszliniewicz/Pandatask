import React, { useEffect, useId, useMemo, useRef, useState } from 'react';
import { buildProjectFlowModel } from '../../projectWorkspaceModel.mjs';
import Icon from '../Icon';

const STATUS_LABELS = {
	pending: 'Pending',
	'in-progress': 'In progress',
	done: 'Done',
	restricted: 'Restricted',
};

const ProjectFlowView = ( { dependencies, onTaskAction, tasks } ) => {
	const [ filter, setFilter ] = useState( 'all' );
	const [ zoom, setZoom ] = useState( 1 );
	const [ isExpanded, setIsExpanded ] = useState( false );
	const expandButtonRef = useRef( null );
	const restoreFocusRef = useRef( false );
	const markerId = `pandatask-flow-arrow-${ useId().replace( /[^a-z0-9_-]/gi, '' ) }`;
	const model = useMemo(
		() => buildProjectFlowModel( tasks, dependencies, filter ),
		[ dependencies, filter, tasks ]
	);

	useEffect( () => {
		if ( ! isExpanded ) {
			if ( restoreFocusRef.current ) {
				restoreFocusRef.current = false;
				expandButtonRef.current?.focus();
			}
			return undefined;
		}
		restoreFocusRef.current = true;
		document.body.classList.add( 'pandat69-project-canvas-open' );
		const handleKeyDown = ( event ) => {
			if ( event.key === 'Escape' && ! document.querySelector( '.pandat69-react-modal[open]' ) ) {
				event.preventDefault();
				setIsExpanded( false );
			}
		};
		document.addEventListener( 'keydown', handleKeyDown );
		return () => {
			document.removeEventListener( 'keydown', handleKeyDown );
			document.body.classList.remove( 'pandat69-project-canvas-open' );
		};
	}, [ isExpanded ] );

	const changeZoom = ( amount ) => {
		setZoom( ( current ) =>
			Math.min( 1.4, Math.max( 0.65, Number( ( current + amount ).toFixed( 2 ) ) ) )
		);
	};

	return (
		<section
			className={ `pandat69-project-flow ${ isExpanded ? 'is-viewport-expanded' : '' }` }
			aria-labelledby="pandatask-project-flow-title"
		>
			<header className="pandat69-project-canvas-toolbar">
				<div>
					<h4 id="pandatask-project-flow-title">Dependency flow</h4>
					<p>Solid arrows are blockers; dashed lines show parent and child structure.</p>
				</div>
				<div className="pandat69-project-canvas-controls">
					<label>
						<span className="pandat69-visually-hidden">Filter flow tasks</span>
						<select value={ filter } onChange={ ( event ) => setFilter( event.target.value ) }>
							<option value="all">All visual tasks</option>
							<option value="open">Open work</option>
							<option value="blocked">Blocked work</option>
						</select>
					</label>
					<div className="pandat69-project-zoom-controls" aria-label="Flow zoom">
						<button
							type="button"
							onClick={ () => changeZoom( -0.1 ) }
							disabled={ zoom <= 0.65 }
							aria-label="Zoom out"
						>
							<span aria-hidden="true">−</span>
						</button>
						<button type="button" onClick={ () => setZoom( 1 ) }>
							{ Math.round( zoom * 100 ) }%
						</button>
						<button
							type="button"
							onClick={ () => changeZoom( 0.1 ) }
							disabled={ zoom >= 1.4 }
							aria-label="Zoom in"
						>
							<span aria-hidden="true">+</span>
						</button>
					</div>
					<button
						type="button"
						ref={ expandButtonRef }
						className="pandat69-project-canvas-expand"
						onClick={ () => setIsExpanded( ( expanded ) => ! expanded ) }
						aria-label={ isExpanded ? 'Exit expanded flow view' : 'Expand flow to full viewport' }
						aria-pressed={ isExpanded }
					>
						<Icon name={ isExpanded ? 'minimize' : 'maximize' } size={ 16 } />
						{ isExpanded ? 'Exit focus' : 'Focus canvas' }
					</button>
				</div>
			</header>

			{ model.nodes.length ? (
				<div
					className="pandat69-project-flow-scroll"
					tabIndex="0"
					aria-label="Scrollable project dependency diagram"
				>
					<div
						className="pandat69-project-flow-stage"
						style={ {
							width: `${ model.width * zoom }px`,
							height: `${ model.height * zoom }px`,
						} }
					>
						<div
							className="pandat69-project-flow-canvas"
							style={ {
								width: `${ model.width }px`,
								height: `${ model.height }px`,
								transform: `scale(${ zoom })`,
							} }
						>
							<svg
								className="pandat69-project-flow-edges"
								width={ model.width }
								height={ model.height }
								aria-hidden="true"
							>
								<defs>
									<marker
										id={ markerId }
										viewBox="0 0 10 10"
										refX="9"
										refY="5"
										markerWidth="7"
										markerHeight="7"
										orient="auto-start-reverse"
									>
										<path d="M 0 0 L 10 5 L 0 10 z" />
									</marker>
								</defs>
								{ model.edges.map( ( edge ) => (
									<path
										key={ edge.id }
										d={ edge.path }
										className={ `is-${ edge.kind }` }
										markerEnd={ edge.kind === 'dependency' ? `url(#${ markerId })` : undefined }
									/>
								) ) }
							</svg>

							<ul className="pandat69-project-flow-nodes" aria-label="Project tasks">
								{ model.nodes.map( ( node ) => {
									const task = node.task;
									const content = (
										<>
											<span className="pandat69-project-flow-node-topline">
												<span className={ `pandat69-project-status status-${ task.restricted ? 'restricted' : task.status }` }>
													{ STATUS_LABELS[ task.restricted ? 'restricted' : task.status ] || 'Pending' }
												</span>
												{ task.origin === 'external' && <span>External</span> }
											</span>
											<strong>{ task.name }</strong>
											<small>
												{ task.restricted
													? 'Details hidden by source permissions'
													: task.project_name || task.board_display_name || 'This project' }
												{ task.is_blocked ? ' · blocked' : '' }
											</small>
										</>
									);
									return (
										<li
											key={ node.key }
											className={ `status-${ task.restricted ? 'restricted' : task.status } ${ task.origin === 'external' ? 'is-external' : '' }` }
											style={ {
												left: `${ node.x }px`,
												top: `${ node.y }px`,
												width: `${ node.width }px`,
												height: `${ node.height }px`,
											} }
										>
											{ task.restricted ? (
												<div aria-label="Restricted external task">{ content }</div>
											) : (
												<button type="button" onClick={ () => onTaskAction( 'view', task ) }>
													{ content }
												</button>
											) }
										</li>
									);
								} ) }
							</ul>
						</div>
					</div>
				</div>
			) : (
				<div className="pandat69-project-canvas-empty">
					<Icon name="share" size={ 24 } />
					<p>No tasks match this flow filter.</p>
				</div>
			) }
		</section>
	);
};

export default ProjectFlowView;
