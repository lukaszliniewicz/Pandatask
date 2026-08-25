import React, { useState } from 'react';
import { useActivityTypes, useWorkMutations } from '../../hooks/useWorkLog';
import Icon from '../Icon';

const errorMessage = ( error ) =>
	error?.response?.data?.message ||
	error?.message ||
	'That change could not be saved. Please try again.';

const WorkTypeManager = () => {
	const {
		data: workTypes = [],
		isLoading,
		isError,
		refetch,
	} = useActivityTypes();
	const { createActivityType, updateActivityType, archiveActivityType } =
		useWorkMutations();
	const [ newLabel, setNewLabel ] = useState( '' );
	const [ editingKey, setEditingKey ] = useState( '' );
	const [ editingLabel, setEditingLabel ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const isMutating =
		createActivityType.isPending ||
		updateActivityType.isPending ||
		archiveActivityType.isPending;

	const create = async ( event ) => {
		event.preventDefault();
		if ( ! newLabel.trim() ) return;
		setError( '' );
		try {
			await createActivityType.mutateAsync( { label: newLabel.trim() } );
			setNewLabel( '' );
		} catch ( creationError ) {
			setError( errorMessage( creationError ) );
		}
	};

	const save = async ( key ) => {
		if ( ! editingLabel.trim() ) return;
		setError( '' );
		try {
			await updateActivityType.mutateAsync( {
				key,
				data: { label: editingLabel.trim() },
			} );
			setEditingKey( '' );
		} catch ( updateError ) {
			setError( errorMessage( updateError ) );
		}
	};

	const setActive = async ( type, isActive ) => {
		setError( '' );
		try {
			if ( isActive ) {
				await updateActivityType.mutateAsync( {
					key: type.key,
					data: { is_active: true },
				} );
			} else {
				await archiveActivityType.mutateAsync( { key: type.key } );
			}
		} catch ( updateError ) {
			setError( errorMessage( updateError ) );
		}
	};

	if ( isLoading ) {
		return <div className="pandat69-loading">Loading work types…</div>;
	}
	if ( isError ) {
		return (
			<div className="pandat69-empty-state">
				<p>Work types could not be loaded.</p>
				<button
					type="button"
					className="pandat69-button"
					onClick={ () => refetch() }
				>
					Try again
				</button>
			</div>
		);
	}

	return (
		<div className="pandat69-work-type-manager">
			<div className="pandat69-work-type-intro">
				<p>
					Work types describe <em>what kind of work you did</em> —
					such as research, writing or a meeting. Task categories
					remain separate and describe what the task is about.
				</p>
			</div>

			{ error && (
				<div className="pandat69-error" role="alert">
					{ error }
				</div>
			) }

			<form className="pandat69-work-type-create" onSubmit={ create }>
				<label htmlFor="pandat69-new-work-type">New work type</label>
				<div>
					<input
						id="pandat69-new-work-type"
						className="pandat69-input"
						value={ newLabel }
						onChange={ ( event ) =>
							setNewLabel( event.target.value )
						}
						placeholder="e.g. Facilitation"
						maxLength={ 80 }
					/>
					<button
						type="submit"
						className="pandat69-button pandat69-button-primary"
						disabled={
							createActivityType.isPending || ! newLabel.trim()
						}
					>
						<Icon name="plus" size={ 16 } /> Add type
					</button>
				</div>
			</form>

			<ul className="pandat69-work-type-list">
				{ workTypes.map( ( type ) => {
					const active =
						type.is_active !== false && type.is_active !== 0;
					const editing = editingKey === type.key;
					return (
						<li
							key={ type.key }
							className={ active ? '' : 'is-archived' }
						>
							<div className="pandat69-work-type-name">
								{ editing ? (
									<input
										className="pandat69-input"
										value={ editingLabel }
										onChange={ ( event ) =>
											setEditingLabel(
												event.target.value
											)
										}
										onKeyDown={ ( event ) => {
											if ( event.key === 'Enter' ) {
												event.preventDefault();
												save( type.key );
											}
										} }
										aria-label={ `Rename ${ type.label }` }
										maxLength={ 80 }
									/>
								) : (
									<div>
										<strong>{ type.label }</strong>
										<span>
											{ type.is_system
												? 'Built in'
												: 'Custom' }{ ' ' }
											·{ ' ' }
											{ active
												? 'Available'
												: 'Archived' }
										</span>
									</div>
								) }
							</div>
							<div className="pandat69-work-type-actions">
								{ editing ? (
									<>
										<button
											type="button"
											className="pandat69-button pandat69-compact-control"
											onClick={ () => save( type.key ) }
											disabled={
												isMutating ||
												! editingLabel.trim()
											}
										>
											Save
										</button>
										<button
											type="button"
											className="pandat69-button pandat69-compact-control"
											onClick={ () =>
												setEditingKey( '' )
											}
											disabled={ isMutating }
										>
											Cancel
										</button>
									</>
								) : (
									<>
										<button
											type="button"
											className="pandat69-button pandat69-compact-control"
											onClick={ () => {
												setEditingKey( type.key );
												setEditingLabel( type.label );
											} }
											disabled={ isMutating }
										>
											Rename
										</button>
										<button
											type="button"
											className="pandat69-button pandat69-compact-control"
											onClick={ () =>
												setActive( type, ! active )
											}
											disabled={ isMutating }
										>
											{ active ? 'Archive' : 'Restore' }
										</button>
									</>
								) }
							</div>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
};

export default WorkTypeManager;
