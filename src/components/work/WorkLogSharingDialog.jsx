import React, { useEffect, useMemo, useState } from 'react';
import { useWorkLogSharing, useWorkMutations } from '../../hooks/useWorkLog';
import Icon from '../Icon';
import Modal from '../Modal';

const WorkLogSharingDialog = ( { isOpen, onClose } ) => {
	const sharingQuery = useWorkLogSharing( { enabled: isOpen } );
	const { updateSharing } = useWorkMutations();
	const [ selected, setSelected ] = useState( [] );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! isOpen || ! sharingQuery.data ) return;
		setSelected(
			( sharingQuery.data.shared_group_ids || [] ).map( ( id ) => Number( id ) )
		);
		setError( '' );
	}, [ isOpen, sharingQuery.data ] );

	const groups = sharingQuery.data?.groups || [];
	const enabledGroups = useMemo(
		() => groups.filter( ( group ) => group.enabled ),
		[ groups ]
	);
	const disabledGroups = useMemo(
		() => groups.filter( ( group ) => ! group.enabled ),
		[ groups ]
	);

	const toggle = ( groupId ) => {
		setSelected( ( current ) =>
			current.includes( groupId )
				? current.filter( ( id ) => id !== groupId )
				: [ ...current, groupId ]
		);
	};

	const save = async () => {
		setError( '' );
		try {
			await updateSharing.mutateAsync( selected );
			onClose();
		} catch ( requestError ) {
			setError(
				requestError?.message || 'Your sharing choices could not be saved.'
			);
		}
	};

	return (
		<Modal isOpen={ isOpen } onClose={ onClose } title="Share your work log">
			<div className="pandat69-work-sharing-dialog">
				<div className="pandat69-work-sharing-warning">
					<span aria-hidden="true">
						<Icon name="shield" size={ 20 } />
					</span>
					<div>
						<strong>You stay in control</strong>
						<p>
							Selecting a group shares your complete work log—past and
							future entries, including titles, notes, time, work types,
							and allocations—with current members of that group and site
							administrators.
						</p>
						<small>
							Members can view it, but they cannot edit it. Clearing a group
							stops access immediately.
						</small>
					</div>
				</div>

				{ sharingQuery.isLoading ? (
					<div className="pandat69-loading" role="status">
						Loading your groups…
					</div>
				) : sharingQuery.isError ? (
					<div className="pandat69-error" role="alert">
						Your group sharing settings could not be loaded.
					</div>
				) : (
					<>
						<fieldset className="pandat69-work-sharing-groups">
							<legend>Groups that can receive your log</legend>
							{ enabledGroups.length ? (
								enabledGroups.map( ( group ) => {
									const groupId = Number( group.id );
									return (
										<label key={ groupId }>
											<input
												type="checkbox"
												checked={ selected.includes( groupId ) }
												onChange={ () => toggle( groupId ) }
											/>
											<span>
												<strong>{ group.name }</strong>
												<small>
													{ selected.includes( groupId )
														? 'Your full log is shared here'
														: 'Not currently shared' }
												</small>
											</span>
										</label>
									);
								} )
							) : (
								<p className="pandat69-report-empty">
									None of your groups has enabled member work logs yet.
								</p>
							) }
						</fieldset>

						{ disabledGroups.length > 0 && (
							<div className="pandat69-work-sharing-unavailable">
								<strong>Not available yet</strong>
								<p>
									{ disabledGroups.map( ( group ) => group.name ).join( ', ' ) }
								</p>
								<small>
									A group administrator must enable Member work logs first.
								</small>
							</div>
						) }
					</>
				) }

				{ error && (
					<div className="pandat69-error" role="alert">
						{ error }
					</div>
				) }

				<div className="pandat69-modal-actions">
					<button
						type="button"
						className="pandat69-button"
						onClick={ onClose }
					>
						Cancel
					</button>
					<button
						type="button"
						className="pandat69-button pandat69-button-primary"
						disabled={
							sharingQuery.isLoading ||
							sharingQuery.isError ||
							! sharingQuery.data ||
							updateSharing.isPending
						}
						onClick={ save }
					>
						{ updateSharing.isPending ? 'Sharing…' : 'Share' }
					</button>
				</div>
			</div>
		</Modal>
	);
};

export default WorkLogSharingDialog;
