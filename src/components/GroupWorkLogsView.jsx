import React, { useEffect, useMemo, useState } from 'react';
import { useConfig } from '../context/ConfigContext';
import {
	useGroupWorkLogs,
	useInfiniteSharedWorkLog,
} from '../hooks/useWorkLog';
import { workLogCsv, workLogRangeForPreset } from '../workLogUiModel.mjs';
import {
	formatWorkDuration,
	getWorkAllocationLabel,
	getWorkEntryPresentation,
} from '../workReportModel.mjs';
import Icon from './Icon';
import WorkEntryTimeline from './work/WorkEntryTimeline';
import WorkLogToolbar from './work/WorkLogToolbar';
import WorkReportSummary from './work/WorkReportSummary';
import '../../assets/scss/components/_group-work-logs.scss';

const downloadCsv = ( content, filename ) => {
	const url = URL.createObjectURL(
		new Blob( [ content ], { type: 'text/csv;charset=utf-8' } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.click();
	window.setTimeout( () => URL.revokeObjectURL( url ), 0 );
};

const initials = ( name ) =>
	String( name || '?' )
		.split( /\s+/ )
		.slice( 0, 2 )
		.map( ( part ) => part.charAt( 0 ) )
		.join( '' )
		.toUpperCase();

const PresenterAvatar = ( { presenter, size = 'normal' } ) => (
	<span className={ `pandat69-work-presenter-avatar is-${ size }` }>
		{ presenter.avatar_url ? (
			<img src={ presenter.avatar_url } alt="" />
		) : (
			<span aria-hidden="true">{ initials( presenter.name ) }</span>
		) }
	</span>
);

const GroupWorkLogsView = ( { groupId } ) => {
	const initialRange = useMemo(
		() => workLogRangeForPreset( 'last_30_days' ),
		[]
	);
	const [ startDate, setStartDate ] = useState( initialRange.startDate );
	const [ endDate, setEndDate ] = useState( initialRange.endDate );
	const [ preset, setPreset ] = useState( 'last_30_days' );
	const [ selectedUserId, setSelectedUserId ] = useState( 0 );
	const [ exportBusy, setExportBusy ] = useState( false );
	const [ exportError, setExportError ] = useState( '' );
	const filters = useMemo(
		() => ( { start_date: startDate, end_date: endDate } ),
		[ startDate, endDate ]
	);
	const rosterQuery = useGroupWorkLogs( groupId, filters );
	const presenters = rosterQuery.data?.presenters || [];

	useEffect( () => {
		if ( ! presenters.length ) {
			setSelectedUserId( 0 );
			return;
		}
		if ( ! presenters.some( ( item ) => Number( item.id ) === selectedUserId ) ) {
			setSelectedUserId( Number( presenters[ 0 ].id ) );
		}
	}, [ presenters, selectedUserId ] );

	const detailQuery = useInfiniteSharedWorkLog(
		groupId,
		selectedUserId,
		filters
	);
	const firstPage = detailQuery.data?.pages?.[ 0 ];
	const entries = useMemo(
		() => detailQuery.data?.pages.flatMap( ( page ) => page.entries || [] ) || [],
		[ detailQuery.data ]
	);
	const owner = firstPage?.owner;
	const group = firstPage?.group || rosterQuery.data?.group;
	const activityTypes = firstPage?.activity_types || [];
	const report = firstPage?.report;
	const { apiClient } = useConfig();

	const changePreset = ( value ) => {
		setPreset( value );
		if ( value !== 'custom' ) {
			const range = workLogRangeForPreset( value );
			setStartDate( range.startDate );
			setEndDate( range.endDate );
		}
	};

	const exportCsv = async () => {
		if ( ! selectedUserId ) return;
		setExportBusy( true );
		setExportError( '' );
		try {
			const allEntries = [];
			const pageSize = 500;
			let offset = 0;
			for ( ;; ) {
				const exportPage = await apiClient.get(
					`groups/${ groupId }/work-logs/${ selectedUserId }`,
					{
						params: { ...filters, limit: pageSize, offset },
					}
				);
				const pageEntries = exportPage.entries || [];
				allEntries.push( ...pageEntries );
				if ( pageEntries.length < pageSize ) break;
				offset += pageSize;
			}
			const rows = [
				[
					'Date',
					'Work type',
					'Title',
					'Duration (minutes)',
					'Capacity',
					'Allocated targets',
					'Notes',
				],
				...allEntries.map( ( entry ) => {
					const presentation = getWorkEntryPresentation( entry, {
						activityTypes,
						boards: [],
					} );
					return [
						entry.work_date,
						presentation.typeLabel,
						presentation.title,
						Math.round( Number( entry.duration_seconds || 0 ) / 60 ),
						entry.capacity || '',
						( entry.allocations || [] )
							.map( ( allocation ) =>
								getWorkAllocationLabel( allocation, [] )
							)
							.join( ' | ' ),
						entry.notes || '',
					];
				} ),
			];
			downloadCsv(
				workLogCsv( rows ),
				`pandatask-${ String( owner?.name || 'member' )
					.toLowerCase()
					.replaceAll( /[^a-z0-9]+/g, '-' ) }-${ startDate }-${ endDate }.csv`
			);
		} catch ( error ) {
			setExportError(
				error?.message || 'The complete CSV export could not be prepared.'
			);
		} finally {
			setExportBusy( false );
		}
	};

	if ( rosterQuery.isLoading ) {
		return (
			<div className="pandat69-loading pandat69-work-group-loading" role="status">
				Loading shared work logs…
			</div>
		);
	}

	if ( rosterQuery.isError ) {
		return (
			<div className="pandat69-error" role="alert">
				{ rosterQuery.error?.message ||
					'This group’s shared work logs could not be loaded.' }
			</div>
		);
	}

	return (
		<section className="pandat69-work-log pandat69-group-work-logs">
			<header className="pandat69-work-log-header pandat69-group-work-header">
				<div className="pandat69-work-log-heading">
					<span className="pandat69-work-eyebrow">Shared with this group</span>
					<h2>Member work logs</h2>
					<p>
						A read-only view of logs members have chosen to share with{ ' ' }
						{ group?.name || 'this group' }. Each person can withdraw access
						at any time.
					</p>
				</div>
				<span className="pandat69-work-consent-badge">
					<Icon name="shield" size={ 16 } /> Member-controlled
				</span>
			</header>

			<WorkLogToolbar
				startDate={ startDate }
				endDate={ endDate }
				preset={ preset }
				onPresetChange={ changePreset }
				onStartDateChange={ setStartDate }
				onEndDateChange={ setEndDate }
				onExportCsv={ exportCsv }
				onPrint={ () => window.print() }
				canExport={ Boolean( selectedUserId && report?.total_seconds ) }
				exportBusy={ exportBusy }
			/>

			{ exportError && (
				<div className="pandat69-error" role="alert">
					{ exportError }
				</div>
			) }

			<section className="pandat69-work-presenters" aria-labelledby="shared-by-title">
				<div className="pandat69-work-section-heading">
					<div>
						<span>People</span>
						<h3 id="shared-by-title">Who is sharing</h3>
					</div>
					<small>
						{ presenters.length } { presenters.length === 1 ? 'member' : 'members' }
					</small>
				</div>
				{ presenters.length ? (
					<ul className="pandat69-work-presenter-list">
						{ presenters.map( ( presenter ) => {
							const id = Number( presenter.id );
							return (
								<li key={ id }>
									<button
										type="button"
										className={
											id === selectedUserId ? 'is-selected' : ''
										}
										onClick={ () => setSelectedUserId( id ) }
										aria-pressed={ id === selectedUserId }
									>
										<PresenterAvatar presenter={ presenter } />
										<span>
											<strong>{ presenter.name }</strong>
											<small>
												{ formatWorkDuration(
													presenter.total_seconds
												) }{ ' ' }
												in this period
											</small>
										</span>
										<Icon name="chevron-right" size={ 15 } />
									</button>
								</li>
							);
						} ) }
					</ul>
				) : (
					<div className="pandat69-empty-state pandat69-work-empty">
						<Icon name="users" size={ 30 } />
						<h4>No one is sharing yet</h4>
						<p>
							Members can opt in from Sharing in their personal Work log.
						</p>
					</div>
				) }
			</section>

			{ selectedUserId > 0 && (
				<>
					<section className="pandat69-shared-work-owner">
						{ owner ? (
							<>
								<PresenterAvatar presenter={ owner } size="large" />
								<div>
									<span>Presented by</span>
									<h3>{ owner.name }</h3>
									<p>Shared voluntarily · Read-only for group members</p>
								</div>
								{ owner.profile_url && (
									<a className="pandat69-button" href={ owner.profile_url }>
										View profile
									</a>
								) }
							</>
						) : (
							<div className="pandat69-loading" role="status">
								Loading this member’s log…
							</div>
						) }
					</section>

					{ detailQuery.isError ? (
						<div className="pandat69-error" role="alert">
							{ detailQuery.error?.message ||
								'This shared work log is no longer available.' }
						</div>
					) : report ? (
						<>
							<section className="pandat69-work-insights">
								<div className="pandat69-work-section-heading">
									<div>
										<span>Overview</span>
										<h3>Where the time went</h3>
									</div>
								</div>
								<WorkReportSummary
									report={ report }
									activityTypes={ activityTypes }
									boards={ [] }
								/>
							</section>
							<section className="pandat69-work-history-panel">
								<div className="pandat69-work-section-heading">
									<div>
										<span>History</span>
										<h3>Recorded work</h3>
									</div>
									<small>{ entries.length } loaded</small>
								</div>
								<WorkEntryTimeline
									entries={ entries }
									activityTypes={ activityTypes }
									emptyText="This member has no work entries in the selected period."
								/>
								{ detailQuery.hasNextPage && (
									<button
										type="button"
										className="pandat69-button pandat69-work-load-more"
										onClick={ () => detailQuery.fetchNextPage() }
										disabled={ detailQuery.isFetchingNextPage }
									>
										{ detailQuery.isFetchingNextPage
											? 'Loading…'
											: 'Load older entries' }
									</button>
								) }
							</section>
						</>
					) : null }
				</>
			) }
		</section>
	);
};

export default GroupWorkLogsView;
