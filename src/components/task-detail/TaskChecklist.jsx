import React, { useEffect, useRef, useState } from 'react';
import { useTaskChecklist } from '../../hooks/useTaskChecklist';
import { useTaskRecurrence } from '../../hooks/useTaskRecurrence';
import ChecklistCount from '../ChecklistCount';
import Icon from '../Icon';

const limitItemText = ( text ) => Array.from( text ).slice( 0, 500 ).join( '' );

const TaskChecklist = ( { task } ) => {
	const mutation = useTaskChecklist( task.id );
	const recurrence = useTaskRecurrence( task );
	const [ futureSaved, setFutureSaved ] = useState( false );
	const [ expanded, setExpanded ] = useState( false );
	const [ draft, setDraft ] = useState( '' );
	const [ editing, setEditing ] = useState( null );
	const [ editText, setEditText ] = useState( '' );
	const draftId = useRef( null );
	const inputRef = useRef( null );
	const editRef = useRef( null );
	const items = task.checklist || [];
	const canEdit = task.can_edit_checklist === true;
	const busy = mutation.isPending;
	const editingItemExists = items.some( ( item ) => item.id === editing );
	const headingId = `pandatask-checklist-${ task.id }`;

	useEffect( () => {
		if ( expanded ) {
			inputRef.current?.focus();
		}
	}, [ expanded ] );

	useEffect( () => {
		if ( editing !== null ) {
			editRef.current?.focus();
		}
	}, [ editing ] );

	const save = async ( nextItems, recurrenceScope ) => {
		setFutureSaved( false );
		try {
			await mutation.mutateAsync( {
				items: nextItems,
				expectedVersion: task.checklist_version || 0,
				recurrenceScope,
				expectedSeriesVersion: recurrenceScope
					? recurrence.data?.series.version
					: undefined,
			} );
			if ( recurrenceScope === 'future' ) {
				setFutureSaved( true );
			}
			return true;
		} catch {
			return false;
		}
	};

	const addItem = async ( event ) => {
		event.preventDefault();
		if ( busy || ! draft.trim() ) {
			return;
		}
		draftId.current ||= crypto.randomUUID();
		const nextItem = {
			id: draftId.current,
			text: draft.trim(),
			checked: false,
		};
		// Reuse the draft ID after an uncertain network response, preventing a
		// duplicate if the first write succeeded and the refresh found it.
		const nextItems = items.some( ( item ) => item.id === nextItem.id )
			? items.map( ( item ) =>
					item.id === nextItem.id
						? { ...item, text: nextItem.text }
						: item
			  )
			: [ ...items, nextItem ];
		if ( await save( nextItems ) ) {
			setDraft( '' );
			draftId.current = null;
			inputRef.current?.focus();
		}
	};

	const moveItem = ( index, offset ) => {
		const nextItems = [ ...items ];
		[ nextItems[ index ], nextItems[ index + offset ] ] = [
			nextItems[ index + offset ],
			nextItems[ index ],
		];
		return save( nextItems );
	};

	const editItem = async ( event ) => {
		event.preventDefault();
		if ( busy || ! editText.trim() ) {
			return;
		}
		const nextItems = editingItemExists
			? items.map( ( item ) =>
					item.id === editing
						? { ...item, text: editText.trim() }
						: item
			  )
			: [
					...items,
					{ id: editing, text: editText.trim(), checked: false },
			  ];
		if ( await save( nextItems ) ) {
			setEditing( null );
		}
	};

	const renderEditForm = () => (
		<form className="pandat69-checklist-entry" onSubmit={ editItem }>
			<label
				className="pandat69-visually-hidden"
				htmlFor={ `${ headingId }-edit` }
			>
				Checklist item text
			</label>
			<input
				ref={ editRef }
				id={ `${ headingId }-edit` }
				value={ editText }
				maxLength={ 1000 }
				required
				readOnly={ busy }
				onChange={ ( event ) =>
					setEditText( limitItemText( event.target.value ) )
				}
			/>
			<button
				type="submit"
				className="pandat69-button"
				disabled={
					busy ||
					! editText.trim() ||
					( ! editingItemExists && items.length >= 100 )
				}
			>
				{ editingItemExists ? 'Save' : 'Restore item' }
			</button>
			<button
				type="button"
				className="pandat69-button"
				disabled={ busy }
				onClick={ () => setEditing( null ) }
			>
				Cancel
			</button>
		</form>
	);

	if ( ! items.length && ! expanded && editing === null ) {
		return canEdit ? (
			<div className="pandat69-checklist-add">
				<button
					type="button"
					className="pandat69-button"
					onClick={ () => setExpanded( true ) }
				>
					<Icon name="list-plus" size={ 16 } /> Add checklist
				</button>
			</div>
		) : null;
	}

	return (
		<section
			className="pandat69-checklist"
			aria-labelledby={ headingId }
			aria-busy={ busy }
		>
			<div className="pandat69-checklist-heading">
				<h3 id={ headingId }>
					<Icon name="list-todo" /> Checklist
				</h3>
				<span aria-live="polite">
					<ChecklistCount task={ task } />
				</span>
			</div>
			{ items.length > 0 && (
				<ul className="pandat69-checklist-items">
					{ items.map( ( item, index ) => (
						<li
							key={ item.id }
							className={ item.checked ? 'is-checked' : '' }
						>
							<div className="pandat69-checklist-row">
								<input
									type="checkbox"
									checked={ item.checked }
									disabled={
										! canEdit || busy || editing !== null
									}
									aria-label={ item.text }
									onChange={ ( event ) =>
										save(
											items.map( ( candidate ) =>
												candidate.id === item.id
													? {
															...candidate,
															checked:
																event.target
																	.checked,
													  }
													: candidate
											)
										)
									}
								/>
								{ canEdit ? (
									<button
										type="button"
										className="pandat69-checklist-text"
										disabled={ busy || editing !== null }
										aria-label={ `Edit checklist item: ${ item.text }` }
										onClick={ () => {
											setEditing( item.id );
											setEditText( item.text );
											mutation.reset();
										} }
									>
										{ item.text }
									</button>
								) : (
									<span className="pandat69-checklist-text">
										{ item.text }
									</span>
								) }
								{ canEdit && (
									<div className="pandat69-checklist-actions">
										<button
											type="button"
											disabled={
												busy ||
												editing !== null ||
												index === 0
											}
											aria-label={ `Move up: ${ item.text }` }
											title="Move up"
											onClick={ () =>
												moveItem( index, -1 )
											}
										>
											<Icon name="arrow-up" size={ 15 } />
										</button>
										<button
											type="button"
											disabled={
												busy ||
												editing !== null ||
												index === items.length - 1
											}
											aria-label={ `Move down: ${ item.text }` }
											title="Move down"
											onClick={ () =>
												moveItem( index, 1 )
											}
										>
											<Icon
												name="arrow-up"
												size={ 15 }
												className="pandat69-checklist-down"
											/>
										</button>
										<button
											type="button"
											disabled={
												busy || editing !== null
											}
											aria-label={ `Delete checklist item: ${ item.text }` }
											title="Delete item"
											onClick={ () =>
												save(
													items.filter(
														( candidate ) =>
															candidate.id !==
															item.id
													)
												)
											}
										>
											<Icon name="trash" size={ 15 } />
										</button>
									</div>
								) }
							</div>
							{ editing === item.id && renderEditForm() }
						</li>
					) ) }
				</ul>
			) }
			{ canEdit && editing !== null && ! editingItemExists && (
				<div>
					<p className="pandat69-checklist-error" role="alert">
						This item was removed elsewhere. Restore it with your
						changes or cancel.
					</p>
					{ renderEditForm() }
				</div>
			) }
			{ canEdit && (
				<form className="pandat69-checklist-entry" onSubmit={ addItem }>
					<label
						className="pandat69-visually-hidden"
						htmlFor={ `${ headingId }-new` }
					>
						Add a checklist item
					</label>
					<input
						ref={ inputRef }
						id={ `${ headingId }-new` }
						value={ draft }
						placeholder="Add an item…"
						maxLength={ 1000 }
						required
						readOnly={ busy }
						disabled={ items.length >= 100 || editing !== null }
						onChange={ ( event ) =>
							setDraft( limitItemText( event.target.value ) )
						}
					/>
					<button
						type="submit"
						className="pandat69-button"
						disabled={
							busy ||
							! draft.trim() ||
							items.length >= 100 ||
							editing !== null
						}
					>
						<Icon name="plus" size={ 16 } /> Add
					</button>
				</form>
			) }
			{ items.length >= 100 && canEdit && (
				<p className="pandat69-empty-note">
					This checklist has reached its limit of 100 items.
				</p>
			) }
			{ task.recurrence_series_id && (
				<div className="pandat69-checklist-future">
					<p className="pandat69-field-hint">
						Checklist edits apply to this occurrence.
					</p>
					{ canEdit &&
						recurrence.data?.series.can_edit &&
						Number( recurrence.data.series.current_task_id ) ===
							Number( task.id ) && (
							<button
								type="button"
								className="pandat69-button"
								disabled={
									busy ||
									editing !== null ||
									Boolean( draft.trim() )
								}
								onClick={ () => save( items, 'future' ) }
							>
								Use these steps for future occurrences
							</button>
						) }
					{ futureSaved && (
						<p role="status">
							Future occurrences will start with these steps
							unchecked.
						</p>
					) }
				</div>
			) }
			{ mutation.isError && (
				<p className="pandat69-checklist-error" role="alert">
					{ mutation.error?.status === 409
						? 'The checklist or series changed elsewhere. The latest data has been loaded; review it and try again.'
						: mutation.error?.message ||
						  'The checklist could not be saved. Please try again.' }
				</p>
			) }
		</section>
	);
};

export default TaskChecklist;
