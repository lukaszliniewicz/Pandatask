import React from 'react';
import Icon from '../Icon';
import ChecklistCount from '../ChecklistCount';

const ProjectTaskNode = ( {
	depth = 0,
	expandedTaskIds,
	node,
	onTaskAction,
	onToggle,
} ) => {
	const { task, children } = node;
	const hasChildren = children.length > 0;
	const isExpanded = hasChildren && expandedTaskIds.has( node.key );
	const statusLabel = task.status === 'in-progress' ? 'In progress' : 'Pending';

	return (
		<li className={ `pandat69-project-task-node status-${ task.status || 'pending' }` }>
			<div
				className="pandat69-project-task-row"
				style={ { '--pandatask-project-task-indent': `${ depth * 22 }px` } }
			>
				<span
					className="pandat69-project-task-status"
					title={ statusLabel }
				>
					<span className="pandat69-visually-hidden">{ statusLabel }</span>
				</span>
				{ hasChildren ? (
					<button
						type="button"
						className="pandat69-project-task-toggle"
						onClick={ () => onToggle( node.key ) }
						aria-expanded={ isExpanded }
						aria-label={ `${ isExpanded ? 'Collapse' : 'Expand' } ${ children.length } subtasks for ${ task.name }` }
						title={ `${ isExpanded ? 'Collapse' : 'Expand' } ${ children.length } subtasks` }
					>
						<Icon name={ isExpanded ? 'chevron-down' : 'chevron-right' } size={ 15 } />
					</button>
				) : (
					<span className="pandat69-project-task-icon-spacer" aria-hidden="true">
						{ depth > 0 && <Icon name="corner-down-right" size={ 14 } /> }
					</span>
				) }
				<button
					type="button"
					className="pandat69-project-task-link"
					onClick={ () => onTaskAction( 'view', task ) }
				>
					{ task.name }
				</button>
				<ChecklistCount task={ task } />
				{ task.deadline && (
					<span className="pandat69-project-task-deadline">
						<Icon name="calendar" size={ 13 } />
						{ task.deadline }
					</span>
				) }
			</div>
			{ isExpanded && (
				<ul className="pandat69-project-task-children">
					{ children.map( ( child ) => (
						<ProjectTaskNode
							key={ child.key }
							depth={ depth + 1 }
							expandedTaskIds={ expandedTaskIds }
							node={ child }
							onTaskAction={ onTaskAction }
							onToggle={ onToggle }
						/>
					) ) }
				</ul>
			) }
		</li>
	);
};

const ProjectTaskTree = ( {
	expandedTaskIds,
	nodes,
	onTaskAction,
	onToggle,
} ) => (
	<ul className="pandat69-project-task-list">
		{ nodes.map( ( node ) => (
			<ProjectTaskNode
				key={ node.key }
				expandedTaskIds={ expandedTaskIds }
				node={ node }
				onTaskAction={ onTaskAction }
				onToggle={ onToggle }
			/>
		) ) }
	</ul>
);

export default ProjectTaskTree;
