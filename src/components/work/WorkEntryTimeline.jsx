import React, { useMemo } from 'react';
import {
	formatWorkDuration,
	getWorkAllocationLabel,
	getWorkEntryPresentation,
} from '../../workReportModel.mjs';
import Icon from '../Icon';

const formatDay = ( value ) =>
	new Date( `${ value }T12:00:00` ).toLocaleDateString( undefined, {
		weekday: 'short',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} );

const WorkEntryTimeline = ( {
	entries,
	activityTypes = [],
	boards = [],
	editable = false,
	onEdit,
	onDelete,
	onAddDetail,
	isDeleting = false,
	emptyTitle = 'No work recorded for these dates',
	emptyText = 'Nothing has been recorded in the selected period.',
} ) => {
	const groupedEntries = useMemo( () => {
		const groups = new Map();
		entries.forEach( ( entry ) => {
			if ( ! groups.has( entry.work_date ) ) {
				groups.set( entry.work_date, [] );
			}
			groups.get( entry.work_date ).push( entry );
		} );
		return Array.from( groups.entries() );
	}, [ entries ] );

	if ( ! groupedEntries.length ) {
		return (
			<div className="pandat69-empty-state pandat69-work-empty">
				<Icon name="clock" size={ 28 } />
				<h4>{ emptyTitle }</h4>
				<p>{ emptyText }</p>
			</div>
		);
	}

	return (
		<div className="pandat69-work-entry-groups">
			{ groupedEntries.map( ( [ date, dateEntries ] ) => (
				<section key={ date } className="pandat69-work-entry-day">
					<h4>
						<span>{ formatDay( date ) }</span>
						<strong>
							{ formatWorkDuration(
								dateEntries.reduce(
									( total, entry ) =>
										total + Number( entry.duration_seconds || 0 ),
									0
								)
							) }
						</strong>
					</h4>
					<ol className="pandat69-work-entry-list">
						{ dateEntries.map( ( entry ) => {
							const presentation = getWorkEntryPresentation( entry, {
								activityTypes,
								boards,
							} );
							return (
								<li
									key={ entry.id }
									className={
										presentation.isResidual ? 'is-residual' : ''
									}
								>
									<div
										className="pandat69-work-entry-type"
										aria-hidden="true"
									>
										<span />
									</div>
									<div className="pandat69-work-entry-main">
										<div className="pandat69-work-entry-title-row">
											<strong>{ presentation.title }</strong>
											<span>
												{ formatWorkDuration(
													entry.duration_seconds
												) }
											</span>
										</div>
										<div className="pandat69-work-entry-meta">
											<span
												className={
													presentation.isResidual
														? 'is-attention'
														: ''
												}
											>
												{ presentation.typeLabel }
											</span>
											{ entry.capacity && <span>{ entry.capacity }</span> }
											{ ! presentation.isResidual &&
												entry.allocations?.length > 0 && (
													<span>
														{ entry.allocations
															.map( ( allocation ) =>
																getWorkAllocationLabel(
																	allocation,
																	boards
																)
															)
															.join( ' · ' ) }
													</span>
												) }
										</div>
										{ entry.notes && <p>{ entry.notes }</p> }
										{ presentation.isResidual && (
											<small>
												Included in the declared task total but not assigned
												to a more specific work entry.
											</small>
										) }
									</div>
									{ editable && (
										<div className="pandat69-work-entry-actions">
											{ presentation.isResidual ? (
												presentation.task &&
												onAddDetail && (
													<button
														type="button"
														className="pandat69-button pandat69-compact-control"
														onClick={ () =>
															onAddDetail( presentation.task )
														}
													>
														Add detail
													</button>
												)
											) : (
												<>
													<button
														type="button"
														className="pandat69-button pandat69-compact-control"
														onClick={ () => onEdit?.( entry ) }
													>
														Edit
													</button>
													<button
														type="button"
														className="pandat69-button pandat69-button-danger pandat69-compact-control"
														disabled={ isDeleting }
														onClick={ () => onDelete?.( entry ) }
													>
														Delete
													</button>
												</>
											) }
										</div>
									) }
								</li>
							);
						} ) }
					</ol>
				</section>
			) ) }
		</div>
	);
};

export default WorkEntryTimeline;
