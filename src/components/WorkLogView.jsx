import React, { useMemo, useState } from 'react';
import { useConfig } from '../context/ConfigContext';
import {
	useActivityTypes,
	useInfiniteWorkEntries,
	useWorkMutations,
	useWorkReport,
} from '../hooks/useWorkLog';
import { useUserBoards } from '../hooks/useUserBoards';
import { workLogCsv, workLogRangeForPreset } from '../workLogUiModel.mjs';
import {
	getWorkAllocationLabel,
	getWorkEntryPresentation,
} from '../workReportModel.mjs';
import Icon from './Icon';
import WorkEntryTimeline from './work/WorkEntryTimeline';
import WorkLogSharingDialog from './work/WorkLogSharingDialog';
import WorkLogToolbar from './work/WorkLogToolbar';
import WorkReportSummary from './work/WorkReportSummary';
import WorkSuggestionsPanel from './work/WorkSuggestionsPanel';

const downloadText = ( content, filename, type ) => {
	const url = URL.createObjectURL( new Blob( [ content ], { type } ) );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.click();
	window.setTimeout( () => URL.revokeObjectURL( url ), 0 );
};

const WorkLogView = ( { onLogWork, onManageWorkTypes, onOpenTask } ) => {
	const initialRange = useMemo(
		() => workLogRangeForPreset( 'last_30_days' ),
		[]
	);
	const [ startDate, setStartDate ] = useState( initialRange.startDate );
	const [ endDate, setEndDate ] = useState( initialRange.endDate );
	const [ preset, setPreset ] = useState( 'last_30_days' );
	const [ deleteError, setDeleteError ] = useState( '' );
	const [ exportError, setExportError ] = useState( '' );
	const [ exportBusy, setExportBusy ] = useState( false );
	const [ sharingOpen, setSharingOpen ] = useState( false );
	const filters = useMemo(
		() => ( { start_date: startDate, end_date: endDate } ),
		[ startDate, endDate ]
	);
	const entriesQuery = useInfiniteWorkEntries( filters );
	const entries = useMemo(
		() => entriesQuery.data?.pages.flat() || [],
		[ entriesQuery.data ]
	);
	const {
		data: report,
		isLoading: isReportLoading,
		isError: isReportError,
	} = useWorkReport( filters );
	const { data: activityTypes = [] } = useActivityTypes();
	const { data: boards = [] } = useUserBoards();
	const { apiClient } = useConfig();
	const { deleteEntry } = useWorkMutations();

	const changePreset = ( value ) => {
		setPreset( value );
		if ( value !== 'custom' ) {
			const range = workLogRangeForPreset( value );
			setStartDate( range.startDate );
			setEndDate( range.endDate );
		}
	};

	const exportCsv = async () => {
		setExportBusy( true );
		setExportError( '' );
		try {
			const allEntries = [];
			const pageSize = 500;
			let offset = 0;
			for ( ;; ) {
				const response = await apiClient.get( 'users/me/work-entries', {
					params: { ...filters, limit: pageSize, offset },
				} );
				const page = response.entries || [];
				allEntries.push( ...page );
				if ( page.length < pageSize ) break;
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
						boards,
					} );
					return [
						entry.work_date,
						presentation.typeLabel,
						presentation.title,
						Math.round( Number( entry.duration_seconds || 0 ) / 60 ),
						entry.capacity || '',
						( entry.allocations || [] )
							.map( ( allocation ) =>
								getWorkAllocationLabel( allocation, boards )
							)
							.join( ' | ' ),
						entry.notes || '',
					];
				} ),
			];
			downloadText(
				workLogCsv( rows ),
				`pandatask-work-${ startDate }-${ endDate }.csv`,
				'text/csv;charset=utf-8'
			);
		} catch ( error ) {
			setExportError(
				error?.message || 'The complete CSV export could not be prepared.'
			);
		} finally {
			setExportBusy( false );
		}
	};

	const remove = async ( entry ) => {
		if ( ! window.confirm( `Delete work entry “${ entry.title }”?` ) ) return;
		setDeleteError( '' );
		try {
			await deleteEntry.mutateAsync( { id: entry.id } );
		} catch ( error ) {
			setDeleteError( error?.message || 'The work entry could not be deleted.' );
		}
	};

	return (
		<section className="pandat69-work-log">
			<header className="pandat69-work-log-header">
				<div className="pandat69-work-log-heading">
					<span className="pandat69-work-eyebrow">
						Your time, without the timesheet theatre
					</span>
					<h2>Work log</h2>
					<p>
						Record what happened, then connect it to tasks or boards only
						when that adds useful context.
					</p>
				</div>
				<div className="pandat69-work-log-primary-actions">
					<button
						type="button"
						className="pandat69-button"
						onClick={ () => setSharingOpen( true ) }
					>
						<Icon name="share" size={ 17 } /> Sharing
					</button>
					<button
						type="button"
						className="pandat69-button"
						onClick={ onManageWorkTypes }
					>
						<Icon name="tags" size={ 17 } /> Work types
					</button>
					<button
						type="button"
						className="pandat69-button pandat69-button-primary"
						onClick={ () => onLogWork() }
					>
						<Icon name="clock" size={ 17 } /> Log work
					</button>
				</div>
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
				canExport={ Boolean( report?.total_seconds ) }
				exportBusy={ exportBusy }
			/>

			{ exportError && (
				<div className="pandat69-error" role="alert">
					{ exportError }
				</div>
			) }

			<WorkSuggestionsPanel filters={ filters } />

			<section
				className="pandat69-work-insights"
				aria-labelledby="pandat69-work-insights-title"
			>
				<div className="pandat69-work-section-heading">
					<div>
						<span>Overview</span>
						<h3 id="pandat69-work-insights-title">Where the time went</h3>
					</div>
				</div>
				{ isReportLoading ? (
					<div className="pandat69-loading" role="status">
						Calculating your work summary…
					</div>
				) : isReportError ? (
					<div className="pandat69-error" role="alert">
						The work summary could not be loaded.
					</div>
				) : (
					<WorkReportSummary report={ report } onOpenTask={ onOpenTask } />
				) }
			</section>

			<section
				className="pandat69-work-history-panel"
				aria-labelledby="pandat69-work-history-title"
			>
				<div className="pandat69-work-section-heading">
					<div>
						<span>History</span>
						<h3 id="pandat69-work-history-title">Recorded work</h3>
					</div>
					<small>{ entries.length } loaded</small>
				</div>
				{ deleteError && (
					<div className="pandat69-error" role="alert">
						{ deleteError }
					</div>
				) }
				{ entriesQuery.isLoading ? (
					<div className="pandat69-loading" role="status">
						Loading work…
					</div>
				) : entriesQuery.isError ? (
					<div className="pandat69-empty-state">
						<p>Your work entries could not be loaded.</p>
						<button
							type="button"
							className="pandat69-button"
							onClick={ () => entriesQuery.refetch() }
						>
							Try again
						</button>
					</div>
				) : (
					<WorkEntryTimeline
						entries={ entries }
						activityTypes={ activityTypes }
						boards={ boards }
						editable
						onEdit={ ( entry ) => onLogWork( { entry } ) }
						onDelete={ remove }
						onAddDetail={ ( task ) => onLogWork( { task } ) }
						isDeleting={ deleteEntry.isPending }
						emptyText="Log the useful reality, not a forensic reconstruction of your entire day."
					/>
				) }
				{ entriesQuery.hasNextPage && (
					<button
						type="button"
						className="pandat69-button pandat69-work-load-more"
						onClick={ () => entriesQuery.fetchNextPage() }
						disabled={ entriesQuery.isFetchingNextPage }
					>
						{ entriesQuery.isFetchingNextPage
							? 'Loading…'
							: 'Load older entries' }
					</button>
				) }
			</section>

			<WorkLogSharingDialog
				isOpen={ sharingOpen }
				onClose={ () => setSharingOpen( false ) }
			/>
		</section>
	);
};

export default WorkLogView;
