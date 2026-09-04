import React, { useCallback, useMemo, useState } from 'react';
import { buildProjectTaskTree } from '../../projectTaskModel.mjs';
import { getProjectTaskGroups } from '../../projectWorkspaceModel.mjs';
import Icon from '../Icon';
import ProjectTaskTree from './ProjectTaskTree';

const STATUS_LABELS = {
	pending: 'Pending',
	'in-progress': 'In progress',
	done: 'Done',
};

const ProjectReferenceActions = ( {
	canManage,
	onRemove,
	onUpdate,
	references,
	task,
} ) => (
	<div className="pandat69-project-reference-actions">
		{ references.map( ( reference ) => {
			if ( [ 'included', 'related' ].includes( reference.relation_type ) ) {
				return (
					<span key={ reference.reference_key } className="pandat69-project-relation-control">
						{ canManage ? (
							<>
								<label className="pandat69-visually-hidden" htmlFor={ `relation-${ reference.reference_key }` }>
									Relationship for { task.name }
								</label>
								<select
									id={ `relation-${ reference.reference_key }` }
									value={ reference.relation_type }
									onChange={ ( event ) => onUpdate( reference, event.target.value ) }
								>
									<option value="included">Included</option>
									<option value="related">Related</option>
								</select>
							</>
						) : (
							<span className="pandat69-project-relation-badge">
								{ reference.relation_type === 'included' ? 'Included' : 'Related' }
							</span>
						) }
						{ canManage && (
							<button
								type="button"
								onClick={ () => onRemove( reference, task ) }
								aria-label={ `Remove ${ reference.relation_type } reference to ${ task.name }` }
							>
								<Icon name="x" size={ 13 } />
							</button>
						) }
					</span>
				);
			}

			return (
				<span key={ reference.reference_key } className="pandat69-project-relation-control">
					<span className="pandat69-project-relation-badge is-dependency">Dependency</span>
					{ canManage && ! reference.restricted && (
						<button
							type="button"
							onClick={ () => onRemove( reference, task ) }
							aria-label={ `Disconnect dependency for ${ task.name }` }
						>
							<Icon name="x" size={ 13 } />
						</button>
					) }
				</span>
			);
		} ) }
	</div>
);

const ExternalTaskList = ( {
	canManage,
	onRemove,
	onTaskAction,
	onUpdate,
	references,
	tasks,
} ) => (
	<ul className="pandat69-project-external-list">
		{ tasks.map( ( task ) => {
			const taskReferences = references.filter(
				( reference ) =>
					reference.task_key === task.workspace_key ||
					reference.predecessor_key === task.workspace_key
			);
			return (
				<li key={ task.workspace_key } className={ task.restricted ? 'is-restricted' : '' }>
					<div className="pandat69-project-external-main">
						<span className={ `pandat69-project-status status-${ task.restricted ? 'restricted' : task.status }` }>
							{ task.restricted ? 'Restricted' : STATUS_LABELS[ task.status ] || 'Pending' }
						</span>
						<div>
							{ task.restricted ? (
								<strong>{ task.name }</strong>
							) : (
								<button type="button" onClick={ () => onTaskAction( 'view', task ) }>
									{ task.name }
								</button>
							) }
							<span>
								{ task.restricted
									? 'Source details hidden by permissions'
									: task.project_name || task.board_display_name || 'External task' }
								{ task.deadline ? ` · Due ${ task.deadline }` : '' }
								{ task.is_blocked ? ' · Blocked' : '' }
							</span>
						</div>
					</div>
					<ProjectReferenceActions
						canManage={ canManage }
						onRemove={ onRemove }
						onUpdate={ onUpdate }
						references={ taskReferences }
						task={ task }
					/>
				</li>
			);
		} ) }
	</ul>
);

const ProjectWorkspaceList = ( {
	canManage,
	onTaskAction,
	referenceMutations,
	references,
	tasks,
} ) => {
	const [ expandedTaskIds, setExpandedTaskIds ] = useState( new Set() );
	const [ mutationError, setMutationError ] = useState( '' );
	const groups = useMemo( () => getProjectTaskGroups( tasks ), [ tasks ] );
	const nativeTaskTree = useMemo(
		() =>
			buildProjectTaskTree(
				groups.native.map( ( task ) => ( {
					...task,
					id: task.workspace_key,
					canonical_task_id: task.task_id,
					parent_task_id: task.parent_workspace_key,
				} ) )
			),
		[ groups.native ]
	);
	const completedNative = groups.native.filter( ( task ) => task.status === 'done' );

	const toggleTask = useCallback( ( taskId ) => {
		setExpandedTaskIds( ( current ) => {
			const next = new Set( current );
			if ( next.has( taskId ) ) {
				next.delete( taskId );
			} else {
				next.add( taskId );
			}
			return next;
		} );
	}, [] );

	const removeReference = async ( reference, task ) => {
		const label =
			reference.relation_type === 'dependency'
				? 'disconnect this dependency'
				: `remove this ${ reference.relation_type } reference`;
		if ( ! window.confirm( `Are you sure you want to ${ label }? The canonical task will not be changed.` ) ) {
			return;
		}
		try {
			setMutationError( '' );
			await referenceMutations.removeReference.mutateAsync(
				reference.reference_key
			);
		} catch ( error ) {
			setMutationError(
				error?.message || `The reference to ${ task.name } could not be removed.`
			);
		}
	};

	const updateReference = async ( reference, relationType ) => {
		try {
			setMutationError( '' );
			await referenceMutations.updateReference.mutateAsync( {
				referenceKey: reference.reference_key,
				relationType,
			} );
		} catch ( error ) {
			setMutationError( error?.message || 'The relationship could not be updated.' );
		}
	};

	return (
		<div className="pandat69-project-workspace-list">
			{ mutationError && <div className="pandat69-error" role="alert">{ mutationError }</div> }
			<section className="pandat69-project-native-section" aria-labelledby="pandatask-native-tasks-title">
				<header>
					<h4 id="pandatask-native-tasks-title">Project tasks</h4>
					<span>{ nativeTaskTree.total } open</span>
				</header>
				{ nativeTaskTree.total ? (
					<ProjectTaskTree
						expandedTaskIds={ expandedTaskIds }
						nodes={ nativeTaskTree.roots }
						onTaskAction={ onTaskAction }
						onToggle={ toggleTask }
					/>
				) : (
					<p className="pandat69-project-empty-tasks">No open tasks in this project.</p>
				) }
				{ completedNative.length > 0 && (
					<details className="pandat69-project-completed-tasks">
						<summary>{ completedNative.length } completed { completedNative.length === 1 ? 'task' : 'tasks' }</summary>
						<ul>
							{ completedNative.map( ( task ) => (
								<li key={ task.workspace_key }>
									<button type="button" onClick={ () => onTaskAction( 'view', task ) }>
										<Icon name="circle-check" size={ 15 } /> { task.name }
									</button>
								</li>
							) ) }
						</ul>
					</details>
				) }
			</section>

			{ groups.external.length > 0 && (
				<section className="pandat69-project-external-section" aria-labelledby="pandatask-external-tasks-title">
					<header>
						<div>
							<p className="pandat69-eyebrow">Shared context</p>
							<h4 id="pandatask-external-tasks-title">External tasks</h4>
						</div>
						<span>{ groups.external.length }</span>
					</header>
					<ExternalTaskList
						canManage={ canManage }
						onRemove={ removeReference }
						onTaskAction={ onTaskAction }
						onUpdate={ updateReference }
						references={ references }
						tasks={ groups.external }
					/>
				</section>
			) }

			{ groups.related.length > 0 && (
				<details className="pandat69-project-related-section">
					<summary>
						Related context <span>{ groups.related.length }</span>
					</summary>
					<p>These links stay out of the action flow and timeline.</p>
					<ExternalTaskList
						canManage={ canManage }
						onRemove={ removeReference }
						onTaskAction={ onTaskAction }
						onUpdate={ updateReference }
						references={ references }
						tasks={ groups.related }
					/>
				</details>
			) }
		</div>
	);
};

export default ProjectWorkspaceList;
