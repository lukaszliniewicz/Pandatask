import React from 'react';
import { useActivityTypes } from '../../hooks/useWorkLog';
import { useUserBoards } from '../../hooks/useUserBoards';
import {
	formatWorkDuration,
	normalizeWorkBreakdown,
} from '../../workReportModel.mjs';

const BreakdownCard = ( { title, rows, totalSeconds, empty } ) => {
	const visibleRows = rows.slice( 0, 6 );
	return (
		<section className="pandat69-work-breakdown-card">
			<h4>{ title }</h4>
			{ visibleRows.length ? (
				<ul>
					{ visibleRows.map( ( row ) => {
						const percentage = totalSeconds
							? Math.min(
									100,
									( row.duration_seconds / totalSeconds ) *
										100
							  )
							: 0;
						return (
							<li key={ row.label }>
								<div>
									<span title={ row.label }>
										{ row.label }
									</span>
									<strong>
										{ formatWorkDuration(
											row.duration_seconds
										) }
									</strong>
								</div>
								<span
									className="pandat69-work-breakdown-track"
									aria-hidden="true"
								>
									<span
										style={ { width: `${ percentage }%` } }
									/>
								</span>
							</li>
						);
					} ) }
				</ul>
			) : (
				<p className="pandat69-report-empty">{ empty }</p>
			) }
			{ rows.length > visibleRows.length && (
				<p className="pandat69-work-breakdown-more">
					+{ rows.length - visibleRows.length } more
				</p>
			) }
		</section>
	);
};

const WorkReportSummary = ( {
	report,
	isUserBoard = true,
	compact = false,
	onOpenTask = null,
	activityTypes: activityTypesOverride = null,
	boards: boardsOverride = null,
} ) => {
	const { data: personalActivityTypes = [] } = useActivityTypes();
	const { data: personalBoards = [] } = useUserBoards();
	const activityTypes = activityTypesOverride || personalActivityTypes;
	const boards = boardsOverride || personalBoards;
	if ( ! report ) return null;

	const breakdowns = [
		{
			title: 'Work types',
			dimension: 'activity',
			rows: report.activity_breakdown || report.breakdown || [],
			empty: 'No classified work in this period.',
		},
		{
			title: 'Tasks',
			dimension: 'task',
			rows: report.task_breakdown || [],
			empty: 'No task-linked work in this period.',
		},
		...( isUserBoard
			? [
					{
						title: 'Boards',
						dimension: 'board',
						rows: report.board_breakdown || [],
						empty: 'No board-allocated work in this period.',
					},
			  ]
			: [] ),
		{
			title: 'Projects',
			dimension: 'project',
			rows: report.project_breakdown || [],
			empty: 'No project-attributed work in this period.',
		},
		{
			title: 'Task categories',
			dimension: 'category',
			rows: report.category_breakdown || [],
			empty: 'No task-category data in this period.',
		},
		{
			title: 'Capacity',
			dimension: 'capacity',
			rows: report.capacity_breakdown || [],
			empty: 'No capacity data in this period.',
		},
	].map( ( breakdown ) => ( {
		...breakdown,
		rows: normalizeWorkBreakdown( breakdown.rows, {
			dimension: breakdown.dimension,
			activityTypes,
			boards,
		} ),
	} ) );

	const unresolved = Number( report.unresolved_occurrences || 0 );
	const residual = Number( report.residual_seconds || 0 );
	return (
		<div
			className={ `pandat69-work-report-summary${
				compact ? ' is-compact' : ''
			}` }
		>
			<div className="pandat69-work-summary-hero">
				<div>
					<span>
						{ isUserBoard
							? 'Recorded in this period'
							: 'Allocated to this board' }
					</span>
					<strong>
						{ formatWorkDuration( report.total_seconds ) }
					</strong>
				</div>
				<p>
					Each entry is counted once. The figures alongside it explain
					where that time went.
				</p>
			</div>

			<div className="pandat69-work-classification-grid">
				<div>
					<strong>
						{ formatWorkDuration( report.task_linked_seconds ) }
					</strong>
					<span>Linked to tasks</span>
				</div>
				<div>
					<strong>
						{ formatWorkDuration( report.board_only_seconds ) }
					</strong>
					<span>Board-only work</span>
				</div>
				{ isUserBoard && (
					<div>
						<strong>
							{ formatWorkDuration( report.unallocated_seconds ) }
						</strong>
						<span>Personal, not allocated</span>
					</div>
				) }
			</div>

			{ ( residual > 0 || unresolved > 0 ) && (
				<div className="pandat69-work-attention" role="status">
					{ residual > 0 && (
						<div>
							<strong>{ formatWorkDuration( residual ) }</strong>
							<span>Other task time</span>
							<small>
								Part of declared task totals that is not
								represented by specific work entries.
							</small>
						</div>
					) }
					{ unresolved > 0 && (
						<div>
							<strong>{ unresolved }</strong>
							<span>Completions awaiting a time decision</span>
							<small>
								This is your all-time backlog, not limited to
								the selected dates.
							</small>
						</div>
					) }
				</div>
			) }

			{ isUserBoard && report.unresolved?.length > 0 && (
				<details className="pandat69-work-unresolved-preview">
					<summary>
						Review completions awaiting a decision ({ unresolved })
					</summary>
					<ul>
						{ report.unresolved.slice( 0, 5 ).map( ( item ) => (
							<li key={ item.occurrence_id }>
								{ onOpenTask ? (
									<button
										type="button"
										onClick={ () =>
											onOpenTask( item.task_id )
										}
									>
										{ item.task_name_snapshot }
									</button>
								) : (
									<strong>{ item.task_name_snapshot }</strong>
								) }
								<span>
									Completed{ ' ' }
									{ String( item.completed_at || '' ).slice(
										0,
										10
									) }{ ' ' }
									·{ ' ' }
									{ formatWorkDuration(
										item.specific_seconds
									) }{ ' ' }
									detailed
								</span>
							</li>
						) ) }
					</ul>
					{ unresolved > Math.min( 5, report.unresolved.length ) && (
						<small>
							Showing { Math.min( 5, report.unresolved.length ) }{ ' ' }
							of { unresolved }.
						</small>
					) }
				</details>
			) }

			<div className="pandat69-work-breakdown-grid">
				{ breakdowns.map( ( breakdown ) => (
					<BreakdownCard
						key={ breakdown.dimension }
						title={ breakdown.title }
						rows={ breakdown.rows }
						totalSeconds={ Number( report.total_seconds || 0 ) }
						empty={ breakdown.empty }
					/>
				) ) }
			</div>
		</div>
	);
};

export default WorkReportSummary;
