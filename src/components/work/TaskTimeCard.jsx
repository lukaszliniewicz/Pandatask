import React, { useState } from 'react';
import { useTaskWork, useWorkMutations } from '../../hooks/useWorkLog';
import WorkEntryForm from './WorkEntryForm';

const CompletionWorkBreakdownFields = React.lazy(() => import('./CompletionWorkBreakdownFields'));
const CompletedTaskLifecycle = React.lazy(() => import('./CompletedTaskLifecycle'));

const formatDuration = ( seconds ) => {
	if ( seconds == null ) return '—';
	const minutes = Math.round( Number( seconds ) / 60 );
	const hours = Math.floor( minutes / 60 );
	const remainder = minutes % 60;
	return hours
		? `${ hours }h${ remainder ? ` ${ remainder }m` : '' }`
		: `${ minutes }m`;
};

const TimeResolutionForm = ( { taskId, specificSeconds } ) => {
	const { resolveTaskTime } = useWorkMutations();
	const [ hours, setHours ] = useState(
		Math.floor( Number( specificSeconds || 0 ) / 3600 )
	);
	const [ minutes, setMinutes ] = useState(
		Math.round( ( Number( specificSeconds || 0 ) % 3600 ) / 60 )
	);
	const [ notTracked, setNotTracked ] = useState( false );
	const [ workItems, setWorkItems ] = useState( [] );
	const [ residual, setResidual ] = useState( { activity_type: '', capacity: '' } );
	const [ error, setError ] = useState( '' );

	const actualSeconds = Math.max(
		0,
		Number( hours || 0 ) * 3600 + Number( minutes || 0 ) * 60
	);

	const submit = async ( event ) => {
		event.preventDefault();
		setError( '' );
		try {
			const { serializeCompletionWorkItems, serializeResidualClassification } = await import('./completionWorkModel.mjs');
			await resolveTaskTime.mutateAsync( {
				taskId,
				actualSeconds: notTracked ? null : actualSeconds,
				notTracked,
				workItems: notTracked ? [] : serializeCompletionWorkItems( workItems, actualSeconds - Number( specificSeconds || 0 ) ),
				residual: notTracked ? {} : serializeResidualClassification( residual ),
			} );
		} catch ( err ) {
			setError(
				err?.response?.data?.message ||
					err?.message ||
					'Failed to resolve task time.'
			);
		}
	};

	return (
		<form className="pandat69-task-time-resolution" onSubmit={ submit }>
			<strong>Resolve your time for this completed task</strong>
			<p className="pandat69-field-hint">
				Detailed time already logged:{ ' ' }
				{ formatDuration( specificSeconds ) }. Confirm your cumulative
				actual time or mark it as not tracked.
			</p>
			<label className="pandat69-checkbox-label">
				<input
					type="checkbox"
					checked={ notTracked }
					onChange={ ( event ) =>
						setNotTracked( event.target.checked )
					}
				/>{ ' ' }
				Not tracked
			</label>
			{ ! notTracked && (
				<div className="pandat69-form-row">
					<label className="pandat69-form-field pandat69-form-field-half">
						Hours
						<input
							className="pandat69-input"
							type="number"
							min="0"
							value={ hours }
							onChange={ ( event ) =>
								setHours( event.target.value )
							}
						/>
					</label>
					<label className="pandat69-form-field pandat69-form-field-half">
						Minutes
						<input
							className="pandat69-input"
							type="number"
							min="0"
							max="59"
							value={ minutes }
							onChange={ ( event ) =>
								setMinutes( event.target.value )
							}
						/>
					</label>
				</div>
			) }
			{ ! notTracked && (
				<React.Suspense fallback={ null }>
<CompletionWorkBreakdownFields
					actualSeconds={ actualSeconds }
					specificSeconds={ specificSeconds }
					workItems={ workItems }
					onWorkItemsChange={ setWorkItems }
					residual={ residual }
					onResidualChange={ setResidual }
					disabled={ resolveTaskTime.isPending }
				/>
</React.Suspense>
			) }
			{ error && (
				<div className="pandat69-error" role="alert">
					{ error }
				</div>
			) }
			<button
				type="submit"
				className="pandat69-button pandat69-button-primary"
				disabled={ resolveTaskTime.isPending }
			>
				{ resolveTaskTime.isPending ? 'Saving…' : 'Resolve time' }
			</button>
		</form>
	);
};

const TaskTimeCard = ( { task, onNavigate } ) => {
	const { data, isLoading } = useTaskWork( task.id );
	const [ logging, setLogging ] = useState( false );
	const resolution = data?.my_time?.resolution;
	const specific = Number( data?.my_time?.specific_seconds || 0 );
	const actual =
		resolution?.state === 'resolved'
			? Number( resolution.declared_actual_seconds || 0 )
			: null;
	const aggregate = data?.aggregate;
	const needsResolution =
		task.status === 'done' &&
		( resolution?.state === 'unresolved' ||
			( ! resolution && specific > 0 ) );

	return (
		<section className="pandat69-task-time-card">
			<div className="pandat69-task-time-header">
				<h3>Time & lifecycle</h3>
				<div className="pandat69-task-time-actions">
					<button
						type="button"
						className="pandat69-button"
						onClick={ () => setLogging( ( value ) => ! value ) }
					>
						{ logging
							? 'Close logger'
							: task.status === 'done'
							? 'Log follow-up work'
							: 'Add time' }
					</button>
				</div>
			</div>
			{ task.status === 'done' && (
				<React.Suspense fallback={ null }>
					<CompletedTaskLifecycle task={ task } onNavigate={ onNavigate } />
				</React.Suspense>
			) }
			{ isLoading ? (
				<div className="pandat69-loading">Loading time…</div>
			) : (
				<div className="pandat69-task-time-stats">
					<span>
						<strong>
							{ formatDuration( task.estimated_effort_seconds ) }
						</strong>
						<small>Estimate</small>
					</span>
					<span>
						<strong>{ formatDuration( specific ) }</strong>
						<small>Detailed</small>
					</span>
					<span>
						<strong>
							{ resolution?.state === 'not_tracked'
								? 'Not tracked'
								: resolution?.state === 'unresolved'
								? 'Unresolved'
								: formatDuration( actual ) }
						</strong>
						<small>Declared actual</small>
					</span>
					{ resolution?.state === 'resolved' &&
						Number( resolution.declared_actual_seconds || 0 ) >
							specific && (
							<span>
								<strong>
									{ formatDuration(
										Number(
											resolution.declared_actual_seconds
										) - specific
									) }
								</strong>
								<small>Other task time</small>
							</span>
						) }
					{ task.status === 'done' && (
						<>
							<span>
								<strong>{ formatDuration( aggregate?.original_occurrence_seconds || 0 ) }</strong>
								<small>Original occurrence</small>
							</span>
							<span>
								<strong>{ formatDuration( aggregate?.post_completion_seconds || 0 ) }</strong>
								<small>Post-completion follow-up</small>
							</span>
							{ Number( aggregate?.follow_up_task_count || 0 ) > 0 && (
								<span>
									<strong>{ formatDuration( aggregate?.follow_up_task_seconds || 0 ) }</strong>
									<small>Follow-up tasks</small>
								</span>
							) }
							<span>
								<strong>{ formatDuration( aggregate?.related_work_seconds || 0 ) }</strong>
								<small>Related-work total</small>
							</span>
						</>
					) }
					<span>
						<strong>
							{ formatDuration( aggregate?.direct_seconds || 0 ) }
						</strong>
						<small>All recorded · direct</small>
					</span>
					{ Number( aggregate?.descendant_count || 0 ) > 0 && (
						<span>
							<strong>
								{ formatDuration(
									aggregate?.including_subtasks_seconds || 0
								) }
							</strong>
							<small>All recorded · incl. subtasks</small>
						</span>
					) }
				</div>
			) }
			{ task.status !== 'done' && (
				<p className="pandat69-field-hint">
					Add time as you work. These entries accumulate on the
					current task occurrence; the final cumulative actual is
					reconciled when the task is completed.
				</p>
			) }
			{ needsResolution && (
				<TimeResolutionForm
					taskId={ task.id }
					specificSeconds={ specific }
				/>
			) }
			{ logging && (
				<WorkEntryForm
					task={ task }
					compact
					onSaved={ () => setLogging( false ) }
				/>
			) }
			{ data?.entries?.length > 0 && (
				<details className="pandat69-task-work-details">
					<summary>
						{ data.entries.length } work entr
						{ data.entries.length === 1 ? 'y' : 'ies' }
					</summary>
					<ul>
						{ data.entries.map( ( entry ) => (
							<li key={ entry.id }>
								{ entry.work_date } ·{ ' ' }
								{ formatDuration( entry.duration_seconds ) } ·{ ' ' }
								{ entry.title }
							</li>
						) ) }
					</ul>
				</details>
			) }
		</section>
	);
};

export default TaskTimeCard;
