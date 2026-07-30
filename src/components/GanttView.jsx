import React, { useEffect, useId, useMemo, useRef, useState } from 'react';
import {
	addGanttDays,
	buildGanttTimelineWindow,
	buildGanttModel,
	formatGanttDate,
	ganttDayDifference,
	getGanttTaskSet,
	parseGanttDate,
} from '../ganttModel.mjs';
import Icon from './Icon';

const LABEL_WIDTH = 320;
const HEADER_HEIGHT = 52;
const ROW_HEIGHT = 46;

const ZOOM_LEVELS = {
	week: {
		label: 'Week',
		dayWidth: 30,
		padding: 7,
	},
	month: {
		label: 'Month',
		dayWidth: 14,
		padding: 14,
	},
	quarter: {
		label: 'Quarter',
		dayWidth: 6,
		padding: 31,
	},
};

const utcDateFormatter = ( options ) =>
	new Intl.DateTimeFormat( undefined, {
		...options,
		timeZone: 'UTC',
	} );

const compactDate = utcDateFormatter( { month: 'short', day: 'numeric' } );
const dayDate = utcDateFormatter( { day: 'numeric', weekday: 'short' } );
const monthDate = utcDateFormatter( { month: 'short', year: 'numeric' } );

const getLocalToday = () => {
	const now = new Date();
	return parseGanttDate(
		[
			now.getFullYear(),
			String( now.getMonth() + 1 ).padStart( 2, '0' ),
			String( now.getDate() ).padStart( 2, '0' ),
		].join( '-' )
	);
};

const getStatusLabel = ( status ) => {
	if ( status === 'in-progress' ) {
		return 'In progress';
	}
	if ( status === 'done' ) {
		return 'Done';
	}
	return 'Pending';
};

const buildHeaderPeriods = ( start, dayCount, zoom ) => {
	const periods = [];

	if ( zoom === 'week' ) {
		for ( let index = 0; index < dayCount; index += 1 ) {
			const date = addGanttDays( start, index );
			periods.push( {
				key: formatGanttDate( date ),
				label: dayDate.format( date ),
				days: 1,
				isWeekend: [ 0, 6 ].includes( date.getUTCDay() ),
			} );
		}
		return periods;
	}

	let index = 0;
	while ( index < dayCount ) {
		const date = addGanttDays( start, index );
		const month = date.getUTCMonth();
		const year = date.getUTCFullYear();
		let days = 1;

		while ( index + days < dayCount ) {
			const next = addGanttDays( start, index + days );
			if (
				next.getUTCMonth() !== month ||
				next.getUTCFullYear() !== year
			) {
				break;
			}
			days += 1;
		}

		periods.push( {
			key: `${ year }-${ month }`,
			label: monthDate.format( date ),
			days,
		} );
		index += days;
	}

	return periods;
};

const GanttView = ( { tasks, onTaskAction } ) => {
	const [ zoom, setZoom ] = useState( 'month' );
	const [ showCompleted, setShowCompleted ] = useState( false );
	const [ collapsedIds, setCollapsedIds ] = useState( new Set() );
	const scrollRef = useRef( null );
	const didInitialScroll = useRef( false );
	const dependencyMarkerId = `pandat69-gantt-arrow-${ useId().replace(
		/[^a-z0-9_-]/gi,
		''
	) }`;

	const ganttTasks = useMemo(
		() => getGanttTaskSet( tasks || [], showCompleted ),
		[ tasks, showCompleted ]
	);
	const model = useMemo( () => buildGanttModel( ganttTasks ), [ ganttTasks ] );

	const visibleRows = useMemo(
		() =>
			model.rows.filter( ( row ) => {
				let parent = row.parent;
				while ( parent ) {
					if ( collapsedIds.has( parent.id ) ) {
						return false;
					}
					parent = parent.parent;
				}
				return true;
			} ),
		[ model.rows, collapsedIds ]
	);

	const allScheduledRows = visibleRows.filter(
		( row ) => row.effectiveStart && row.effectiveEnd
	);
	const unscheduledRows = visibleRows.filter(
		( row ) => ! row.effectiveStart || ! row.effectiveEnd
	);
	const zoomConfig = ZOOM_LEVELS[ zoom ];
	const today = getLocalToday();
	const timelineWindow = buildGanttTimelineWindow(
		allScheduledRows,
		today,
		zoomConfig.padding
	);
	const scheduledRows = timelineWindow.visibleRows;
	const rowIndexes = new Map(
		scheduledRows.map( ( row, index ) => [ row.id, index ] )
	);
	const rowsById = new Map( model.rows.map( ( row ) => [ row.id, row ] ) );
	const visibleEdges = model.edges.filter(
		( edge ) => rowIndexes.has( edge.from ) && rowIndexes.has( edge.to )
	);
	const conflictCount = visibleRows.reduce(
		( count, row ) => count + row.warnings.length,
		0
	);

	const timelineStart = timelineWindow.start;
	const dayCount = timelineWindow.dayCount;
	const timelineWidth = Math.max(
		720,
		dayCount * zoomConfig.dayWidth
	);
	const canvasWidth = LABEL_WIDTH + timelineWidth;
	const canvasHeight =
		HEADER_HEIGHT + Math.max( scheduledRows.length, 1 ) * ROW_HEIGHT;
	const headerPeriods = buildHeaderPeriods( timelineStart, dayCount, zoom );
	const todayOffset =
		ganttDayDifference( timelineStart, today ) * zoomConfig.dayWidth +
		zoomConfig.dayWidth / 2;
	const initialFocusDate = timelineWindow.focusDate;
	const initialFocusOffset =
		ganttDayDifference( timelineStart, initialFocusDate ) *
			zoomConfig.dayWidth +
		zoomConfig.dayWidth / 2;
	const initialFocusRowIndex = Math.max(
		0,
		scheduledRows.findIndex(
			( row ) =>
				row.effectiveStart <= initialFocusDate &&
				row.effectiveEnd >= initialFocusDate
		)
	);

	useEffect( () => {
		if (
			didInitialScroll.current ||
			! scrollRef.current ||
			! scheduledRows.length
		) {
			return;
		}

		didInitialScroll.current = true;
		scrollRef.current.scrollLeft = Math.max(
			0,
			initialFocusOffset -
				( scrollRef.current.clientWidth - LABEL_WIDTH ) / 2
		);
		scrollRef.current.scrollTop = Math.max(
			0,
			HEADER_HEIGHT +
				initialFocusRowIndex * ROW_HEIGHT -
				scrollRef.current.clientHeight / 2
		);
	}, [
		scheduledRows.length,
		initialFocusOffset,
		initialFocusRowIndex,
	] );

	const toggleCollapsed = ( id ) => {
		setCollapsedIds( ( current ) => {
			const next = new Set( current );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );
	};

	const scrollTimeline = ( direction ) => {
		if ( ! scrollRef.current ) {
			return;
		}

		scrollRef.current.scrollBy( {
			left: direction * scrollRef.current.clientWidth * 0.7,
			behavior: 'smooth',
		} );
	};

	const scrollToToday = () => {
		if ( ! scrollRef.current || ! timelineWindow.todayIsVisible ) {
			return;
		}

		scrollRef.current.scrollTo( {
			left: Math.max(
				0,
				todayOffset -
					( scrollRef.current.clientWidth - LABEL_WIDTH ) / 2
			),
			behavior: 'smooth',
		} );
	};

	const collapseAll = () => {
		setCollapsedIds(
			new Set(
				model.rows
					.filter( ( row ) => row.children.length )
					.map( ( row ) => row.id )
			)
		);
	};

	const expandAll = () => setCollapsedIds( new Set() );

	return (
		<div className="pandat69-gantt-view">
			<div className="pandat69-gantt-toolbar">
				<div className="pandat69-gantt-toolbar-group pandat69-gantt-navigation">
					<button
						type="button"
						className="pandat69-gantt-toolbar-button"
						onClick={ () => scrollTimeline( -1 ) }
						aria-label="Earlier dates"
						title="Earlier dates"
					>
						<Icon name="chevron-left" />
					</button>
					<button
						type="button"
						className="pandat69-gantt-toolbar-button is-text"
						onClick={ scrollToToday }
						disabled={ ! timelineWindow.todayIsVisible }
						title={
							timelineWindow.todayIsVisible
								? 'Scroll to today'
								: 'Today is outside the bounded timeline window'
						}
					>
						Today
					</button>
					<button
						type="button"
						className="pandat69-gantt-toolbar-button"
						onClick={ () => scrollTimeline( 1 ) }
						aria-label="Later dates"
						title="Later dates"
					>
						<Icon name="chevron-right" />
					</button>
				</div>

				<div
					className="pandat69-gantt-zoom"
					role="group"
					aria-label="Timeline scale"
				>
					{ Object.entries( ZOOM_LEVELS ).map(
						( [ id, config ] ) => (
							<button
								type="button"
								key={ id }
								className={ zoom === id ? 'active' : '' }
								onClick={ () => setZoom( id ) }
								aria-pressed={ zoom === id }
							>
								{ config.label }
							</button>
						)
					) }
				</div>

				<div className="pandat69-gantt-toolbar-group pandat69-gantt-options">
					<button
						type="button"
						className="pandat69-gantt-toolbar-button is-text"
						onClick={
							collapsedIds.size ? expandAll : collapseAll
						}
					>
						{ collapsedIds.size ? 'Expand all' : 'Collapse all' }
					</button>
					<label className="pandat69-gantt-completed-toggle">
						<input
							type="checkbox"
							checked={ showCompleted }
							onChange={ ( event ) =>
								setShowCompleted( event.target.checked )
							}
						/>
						Show completed
					</label>
				</div>
			</div>

			<div className="pandat69-gantt-summary" aria-live="polite">
				<span>
					<strong>{ allScheduledRows.length }</strong> scheduled
				</span>
				<span>
					<strong>{ unscheduledRows.length }</strong> unscheduled
				</span>
				{ conflictCount > 0 && (
					<span className="has-warning">
						<Icon name="circle-alert" size={ 15 } />
						<strong>{ conflictCount }</strong>{ ' ' }
						schedule { conflictCount === 1 ? 'warning' : 'warnings' }
					</span>
				) }
				{ timelineWindow.wasBounded && (
					<span className="has-warning">
						<Icon name="circle-alert" size={ 15 } />
						Timeline limited to { dayCount } days
						{ timelineWindow.excludedRowCount > 0
							? `; ${ timelineWindow.excludedRowCount } scheduled ${
									timelineWindow.excludedRowCount === 1
										? 'task is'
										: 'tasks are'
							  } outside this window`
							: ''}
					</span>
				) }
				<span className="pandat69-gantt-semantics">
					Dependency arrows are explicit; subtask order is never assumed.
				</span>
			</div>

			{ scheduledRows.length ? (
				<>
					<div
						className="pandat69-gantt-scroll"
						ref={ scrollRef }
						role="table"
						aria-label="Task schedule"
					>
						<div
							className="pandat69-gantt-canvas"
							style={ {
								width: `${ canvasWidth }px`,
								minHeight: `${ canvasHeight }px`,
								'--pandatask-gantt-label-width': `${ LABEL_WIDTH }px`,
								'--pandatask-gantt-timeline-width': `${ timelineWidth }px`,
								'--pandatask-gantt-day-width': `${ zoomConfig.dayWidth }px`,
							} }
						>
							<div
								className="pandat69-gantt-header-row"
								role="row"
							>
								<div
									className="pandat69-gantt-corner"
									role="columnheader"
								>
									Task
								</div>
								<div
									className="pandat69-gantt-date-header"
									role="columnheader"
								>
									{ headerPeriods.map( ( period ) => (
										<div
											key={ period.key }
											className={
												period.isWeekend
													? 'is-weekend'
													: ''
											}
											style={ {
												width: `${
													period.days *
													zoomConfig.dayWidth
												}px`,
											} }
										>
											{ period.label }
										</div>
									) ) }
								</div>
							</div>

							{ scheduledRows.map( ( row ) => {
								const visibleStart =
									row.effectiveStart < timelineWindow.start
										? timelineWindow.start
										: row.effectiveStart;
								const visibleEnd =
									row.effectiveEnd > timelineWindow.end
										? timelineWindow.end
										: row.effectiveEnd;
								const left =
									ganttDayDifference(
										timelineStart,
										visibleStart
									) * zoomConfig.dayWidth;
								const width = Math.max(
									zoomConfig.dayWidth,
									( ganttDayDifference(
										visibleStart,
										visibleEnd
									) +
										1 ) *
										zoomConfig.dayWidth
								);
								const hasVisibleOwnRange =
									row.ownStart &&
									row.ownEnd &&
									row.ownStart <= timelineWindow.end &&
									row.ownEnd >= timelineWindow.start;
								const visibleOwnStart = hasVisibleOwnRange
									? row.ownStart < timelineWindow.start
										? timelineWindow.start
										: row.ownStart
									: null;
								const visibleOwnEnd = hasVisibleOwnRange
									? row.ownEnd > timelineWindow.end
										? timelineWindow.end
										: row.ownEnd
									: null;
								const ownLeft = visibleOwnStart
									? ganttDayDifference(
											visibleStart,
											visibleOwnStart
									  ) * zoomConfig.dayWidth
									: 0;
								const ownWidth =
									visibleOwnStart && visibleOwnEnd
										? Math.max(
												zoomConfig.dayWidth,
												( ganttDayDifference(
													visibleOwnStart,
													visibleOwnEnd
												) +
													1 ) *
													zoomConfig.dayWidth
										  )
										: 0;
								const isCollapsed = collapsedIds.has( row.id );
								const dateLabel = `${ compactDate.format(
									row.effectiveStart
								) } – ${ compactDate.format(
									row.effectiveEnd
								) }`;

								return (
									<div
										key={ row.id }
										className={ `pandat69-gantt-row ${
											row.task.is_gantt_context
												? 'is-context'
												: ''
										}` }
										role="row"
									>
										<div
											className="pandat69-gantt-task-cell"
											role="rowheader"
										>
											<div
												className="pandat69-gantt-task-main"
												style={ {
													paddingLeft: `${ Math.min(
														row.depth * 18,
														90
													) }px`,
												} }
											>
												{ row.children.length ? (
													<button
														type="button"
														className="pandat69-gantt-expand"
														onClick={ () =>
															toggleCollapsed(
																row.id
															)
														}
														aria-label={
															isCollapsed
																? 'Expand subtasks'
																: 'Collapse subtasks'
														}
														aria-expanded={
															! isCollapsed
														}
													>
														<Icon
															name={
																isCollapsed
																	? 'chevron-right'
																	: 'chevron-down'
															}
															size={ 15 }
														/>
													</button>
												) : (
													<span className="pandat69-gantt-expand-spacer" />
												) }
												<span
													className={ `pandat69-gantt-status status-${ row.task.status }` }
													title={ getStatusLabel(
														row.task.status
													) }
												/>
												<button
													type="button"
													className="pandat69-gantt-task-name"
													onClick={ () =>
														onTaskAction(
															'view',
															row.task
														)
													}
													title={ row.task.name }
												>
													{ row.task.name }
												</button>
												{ row.warnings.length > 0 && (
													<span
														className="pandat69-gantt-warning"
														title={ row.warnings
															.map(
																( warning ) =>
																	warning.label
															)
															.join( ' ' ) }
													>
														<Icon
															name="circle-alert"
															size={ 15 }
														/>
													</span>
												) }
											</div>
											<div className="pandat69-gantt-task-meta">
												{ row.task.project_name ||
													row.task.board_display_name ||
													'No project' }
												{ row.scheduleKind.includes(
													'summary'
												) ||
												row.scheduleKind ===
													'rollup-only'
													? ' · roll-up'
													: '' }
												{ row.task.is_blocked
													? ' · blocked'
													: '' }
											</div>
										</div>
										<div
											className="pandat69-gantt-timeline-cell"
											role="cell"
											aria-label={ dateLabel }
										>
											{ timelineWindow.todayIsVisible && (
												<div
													className="pandat69-gantt-today-line"
													style={ {
														left: `${ todayOffset }px`,
													} }
													aria-hidden="true"
												/>
											) }
											<div
												className={ `pandat69-gantt-bar status-${ row.task.status } kind-${ row.scheduleKind } ${
													row.warnings.length
														? 'has-warning'
														: ''
												}` }
												style={ {
													left: `${ left }px`,
													width: `${ width }px`,
												} }
												title={ `${ row.task.name }: ${ dateLabel }` }
											>
												{ row.children.length > 0 &&
													hasVisibleOwnRange && (
														<span
															className="pandat69-gantt-own-range"
															style={ {
																left: `${ ownLeft }px`,
																width: `${ ownWidth }px`,
															} }
														/>
													) }
												<span>{ row.task.name }</span>
											</div>
										</div>
									</div>
								);
							} ) }

							<svg
								className="pandat69-gantt-dependencies"
								width={ timelineWidth }
								height={ scheduledRows.length * ROW_HEIGHT }
								viewBox={ `0 0 ${ timelineWidth } ${
									scheduledRows.length * ROW_HEIGHT
								}` }
								aria-hidden="true"
							>
								<defs>
									<marker
										id={ dependencyMarkerId }
										viewBox="0 0 10 10"
										refX="9"
										refY="5"
										markerWidth="6"
										markerHeight="6"
										orient="auto-start-reverse"
									>
										<path d="M 0 0 L 10 5 L 0 10 z" />
									</marker>
								</defs>
								{ visibleEdges.map( ( edge ) => {
									const from = rowsById.get( edge.from );
									const to = rowsById.get( edge.to );
									const rawFromX =
										( ganttDayDifference(
											timelineStart,
											from.effectiveEnd
										) +
											1 ) *
										zoomConfig.dayWidth;
									const rawToX =
										ganttDayDifference(
											timelineStart,
											to.effectiveStart
										) *
										zoomConfig.dayWidth;
									const fromX = Math.min(
										timelineWidth,
										Math.max( 0, rawFromX )
									);
									const toX = Math.min(
										timelineWidth,
										Math.max( 0, rawToX )
									);
									const fromY =
										rowIndexes.get( edge.from ) *
											ROW_HEIGHT +
										ROW_HEIGHT / 2;
									const toY =
										rowIndexes.get( edge.to ) *
											ROW_HEIGHT +
										ROW_HEIGHT / 2;
									const bendX =
										toX > fromX + 18
											? fromX + ( toX - fromX ) / 2
											: Math.max( fromX, toX ) + 18;

									return (
										<path
											key={ edge.id }
											d={ `M ${ fromX } ${ fromY } H ${ bendX } V ${ toY } H ${ toX }` }
											className={
												edge.hasConflict
													? 'has-conflict'
													: ''
											}
											markerEnd={ `url(#${ dependencyMarkerId })` }
										/>
									);
								} ) }
							</svg>
						</div>
					</div>

					<div className="pandat69-gantt-mobile-list">
						{ scheduledRows.map( ( row ) => (
							<button
								type="button"
								key={ row.id }
								className="pandat69-gantt-mobile-card"
								onClick={ () =>
									onTaskAction( 'view', row.task )
								}
							>
								<span>{ row.task.name }</span>
								<small>
									{ formatGanttDate( row.effectiveStart ) } –{ ' ' }
									{ formatGanttDate( row.effectiveEnd ) }
									{ row.scheduleKind === 'rollup-only'
										? ' · subtask roll-up'
										: '' }
								</small>
							</button>
						) ) }
					</div>
				</>
			) : (
				<div className="pandat69-gantt-empty">
					No scheduled tasks match the current filters.
				</div>
			) }

			{ unscheduledRows.length > 0 && (
				<section className="pandat69-gantt-unscheduled">
					<div className="pandat69-gantt-unscheduled-heading">
						<div>
							<h3>Unscheduled</h3>
							<p>
								These tasks stay visible without fabricated dates.
							</p>
						</div>
						<span>{ unscheduledRows.length }</span>
					</div>
					<ul>
						{ unscheduledRows.map( ( row ) => (
							<li key={ row.id }>
								<button
									type="button"
									className="pandat69-gantt-unscheduled-name"
									onClick={ () =>
										onTaskAction( 'view', row.task )
									}
								>
									{ row.task.name }
								</button>
								<span>
									{ row.task.project_name ||
										row.task.board_display_name ||
										'No project' }
									{ row.task.is_blocked ? ' · blocked' : '' }
								</span>
								<button
									type="button"
									className="pandat69-gantt-schedule-button"
									onClick={ () =>
										onTaskAction( 'edit', row.task )
									}
								>
									<Icon name="calendar-plus" size={ 15 } />
									Set dates
								</button>
							</li>
						) ) }
					</ul>
				</section>
			) }

			<div className="pandat69-gantt-legend">
				<span>
					<i className="status-pending" /> Pending
				</span>
				<span>
					<i className="status-in-progress" /> In progress
				</span>
				<span>
					<i className="status-done" /> Done
				</span>
				<span>
					<i className="is-rollup" /> Parent/subtask roll-up
				</span>
				<span>
					<i className="has-warning" /> Schedule warning
				</span>
			</div>
		</div>
	);
};

export default GanttView;
