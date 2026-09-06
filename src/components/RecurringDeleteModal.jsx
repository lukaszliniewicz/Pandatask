import React from 'react';
import Modal from './Modal';

const RecurringDeleteModal = ( {
	isOpen,
	onClose,
	onConfirm,
	isPending = false,
} ) => (
	<Modal isOpen={ isOpen } onClose={ onClose } title="Manage repeating task">
		<div className="pandat69-modal-body-content">
			<p>
				Skipping archives this occurrence and keeps its work history.
				Stopping the series prevents new occurrences.
			</p>
			<div className="pandat69-form-actions">
				<button
					type="button"
					className="pandat69-button"
					disabled={ isPending }
					onClick={ () => onConfirm( 'this' ) }
				>
					Skip this occurrence
				</button>
				<button
					type="button"
					className="pandat69-button"
					disabled={ isPending }
					onClick={ () => onConfirm( 'following' ) }
				>
					Stop repeating
				</button>
				<button
					type="button"
					className="pandat69-button pandat69-button-danger"
					disabled={ isPending }
					onClick={ () => onConfirm( 'all' ) }
				>
					Skip and stop repeating
				</button>
				<button
					type="button"
					className="pandat69-button"
					disabled={ isPending }
					onClick={ onClose }
				>
					Cancel
				</button>
			</div>
		</div>
	</Modal>
);
export default RecurringDeleteModal;
