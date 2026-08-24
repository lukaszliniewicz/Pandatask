import React, { useState } from 'react';
import { useWorkMutations, useWorkSuggestions } from '../../hooks/useWorkLog';
import WorkEntryForm from './WorkEntryForm';
import { buildSuggestionAllocationOverride } from '../../workLogModel.mjs';

const formatDuration = ( seconds ) => {
	const totalMinutes = Math.max( 0, Math.round( Number( seconds || 0 ) / 60 ) );
	const hours = Math.floor( totalMinutes / 60 );
	const minutes = totalMinutes % 60;
	if ( hours && minutes ) return `${ hours }h ${ minutes }m`;
	if ( hours ) return `${ hours }h`;
	return `${ minutes }m`;
};

const WorkSuggestionsPanel = ( { filters = {} } ) => {
	const { data: suggestions = [], isLoading } = useWorkSuggestions( filters );
	const { confirmSuggestion, dismissSuggestion } = useWorkMutations();
	const [ adjustingKey, setAdjustingKey ] = useState( '' );
	const [ error, setError ] = useState( '' );

	if ( isLoading || suggestions.length === 0 ) return null;

	const keyFor = ( suggestion ) =>
		`${ suggestion.provider_key }:${ suggestion.external_key }`;

	const confirm = async ( suggestion ) => {
		setError( '' );
		try {
			await confirmSuggestion.mutateAsync( {
				provider_key: suggestion.provider_key,
				external_key: suggestion.external_key,
			} );
			setAdjustingKey( '' );
		} catch ( err ) {
			setError(
				err?.response?.data?.message ||
					err?.message ||
					'Could not confirm this work.'
			);
		}
	};

	const confirmAdjusted = async ( suggestion, workPayload ) => {
		setError( '' );
		const taskAllocations = Array.isArray( workPayload.allocations )
			? workPayload.allocations
			: [];
		const durationSeconds = Number( workPayload.duration_seconds || 0 );
		const overrides = {
			title: workPayload.title,
			notes: workPayload.notes,
			activity_type: workPayload.activity_type,
			capacity: workPayload.capacity,
			work_date: workPayload.work_date,
			duration_seconds: durationSeconds,
		};

		const allocationOverride = buildSuggestionAllocationOverride(
			durationSeconds,
			suggestion.allocations || [],
			taskAllocations
		);
		if ( allocationOverride ) overrides.allocations = allocationOverride;

		const result = await confirmSuggestion.mutateAsync( {
			provider_key: suggestion.provider_key,
			external_key: suggestion.external_key,
			...overrides,
		} );
		setAdjustingKey( '' );
		return result?.entry || result;
	};

	const dismiss = async ( suggestion ) => {
		setError( '' );
		try {
			await dismissSuggestion.mutateAsync( {
				provider_key: suggestion.provider_key,
				external_key: suggestion.external_key,
			} );
		} catch ( err ) {
			setError(
				err?.response?.data?.message ||
					err?.message ||
					'Could not dismiss this suggestion.'
			);
		}
	};

	return (
		<section className="pandat69-work-suggestions" aria-labelledby="pandat69-work-suggestions-heading">
			<div className="pandat69-work-suggestions-header">
				<div>
					<h3 id="pandat69-work-suggestions-heading">
						Needs confirmation <span>({ suggestions.length })</span>
					</h3>
					<p>Possible work from connected providers. Nothing here counts toward your totals until you confirm it.</p>
				</div>
			</div>

			{ error && <div className="pandat69-error" role="alert">{ error }</div> }

			<ul className="pandat69-work-suggestion-list">
				{ suggestions.map( ( suggestion ) => {
					const suggestionKey = keyFor( suggestion );
					const isAdjusting = adjustingKey === suggestionKey;
					return (
						<li key={ suggestionKey }>
							{ isAdjusting ? (
								<div style={ { width: '100%' } }>
									<WorkEntryForm
										key={ suggestionKey }
										compact
										initialValues={ suggestion }
										onSubmitOverride={ ( payload ) =>
											confirmAdjusted( suggestion, payload )
										}
										isSubmitting={ confirmSuggestion.isPending }
										submitLabel="Confirm adjusted"
										allocationHint="Allocate any part of this work to tasks if useful. When the provider supplied a group allocation, any remaining time stays with that group."
									/>
									<button
										type="button"
										className="pandat69-button"
										onClick={ () => setAdjustingKey( '' ) }
									>
										Cancel adjustment
									</button>
								</div>
							) : (
								<>
									<div className="pandat69-work-suggestion-main">
										<strong>{ suggestion.title }</strong>
										<span>
											{ suggestion.work_date } · { formatDuration( suggestion.duration_seconds ) } · { suggestion.provider_label }
										</span>
										{ suggestion.reason && <small>{ suggestion.reason }</small> }
										{ suggestion.source_url && (
											<a href={ suggestion.source_url } target="_blank" rel="noreferrer">Open source</a>
										) }
									</div>
									<div className="pandat69-work-suggestion-actions">
										<button
											type="button"
											className="pandat69-button pandat69-button-primary"
											disabled={ confirmSuggestion.isPending || dismissSuggestion.isPending }
											onClick={ () => confirm( suggestion ) }
										>
											Confirm { formatDuration( suggestion.duration_seconds ) }
										</button>
										<button
											type="button"
											className="pandat69-button"
											onClick={ () => setAdjustingKey( suggestionKey ) }
										>
											Adjust
										</button>
										<button
											type="button"
											className="pandat69-button"
											disabled={ confirmSuggestion.isPending || dismissSuggestion.isPending }
											onClick={ () => dismiss( suggestion ) }
										>
											Didn't attend
										</button>
									</div>
								</>
							) }
						</li>
					);
				} ) }
			</ul>
		</section>
	);
};

export default WorkSuggestionsPanel;
