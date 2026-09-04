import React from 'react';
import '../../assets/scss/components/_projects.scss';
import { useProjects } from '../hooks/useProjects';
import { useProjectMutations } from '../hooks/useProjectMutations';
import { useTasks } from '../hooks/useTasks';
import Icon from './Icon';
import TaskList from './TaskList';
import ProjectIndex from './projects/ProjectIndex';
import ProjectWorkspace from './projects/ProjectWorkspace';

/* eslint-disable no-alert -- Project deletion requires explicit confirmation. */

const UnassignedTasksView = ( { onBack, onTaskAction, privateOnly } ) => {
	const { data: tasks, isLoading, isError, error } = useTasks( {
		project: 'none',
		archived: false,
		status: 'pending_in-progress',
		onlyMyTasks: privateOnly,
	} );

	return (
		<section
			className="pandat69-project-unassigned"
			aria-labelledby="pandatask-unassigned-title"
		>
			<div className="pandat69-project-workspace-breadcrumb">
				<button type="button" onClick={ onBack }>
					<Icon name="chevron-left" size={ 15 } /> All projects
				</button>
			</div>
			<header className="pandat69-project-workspace-header">
				<div>
					<p className="pandat69-eyebrow">Board housekeeping</p>
					<h3 id="pandatask-unassigned-title">Unassigned work</h3>
					<p>
						Tasks without a project live here until they need a shared
						outcome and plan.
					</p>
				</div>
			</header>
			{ isLoading && (
				<div className="pandat69-loading">Loading unassigned work…</div>
			) }
			{ isError && (
				<div className="pandat69-error" role="alert">
					{ error?.message || 'Unassigned tasks could not be loaded.' }
				</div>
			) }
			{ ! isLoading && ! isError && (
				<TaskList
					tasks={ tasks || [] }
					onTaskAction={ onTaskAction }
					groupByProject={ false }
				/>
			) }
		</section>
	);
};

const ProjectsView = ( {
	onEditProject,
	onTaskAction,
	privateOnly,
	selectedProjectId,
	onSelectProject,
	currentProjectView,
	onProjectViewChange,
} ) => {
	const { data: projects, isLoading, isError, error } = useProjects(
		undefined,
		{ privateOnly }
	);
	const { deleteProject } = useProjectMutations();
	const hasSelectedProject =
		Number.isInteger( Number( selectedProjectId ) ) &&
		Number( selectedProjectId ) > 0;

	const handleDelete = async ( project ) => {
		if (
			deleteProject.isPending ||
			! window.confirm(
				`Delete “${ project.name }”? Its tasks will become unassigned.`
			)
		) {
			return;
		}
		try {
			await deleteProject.mutateAsync( project.id );
			if ( String( selectedProjectId ) === String( project.id ) ) {
				onSelectProject( 'all' );
			}
		} catch ( mutationError ) {
			window.alert(
				`Failed to delete project: ${
					mutationError?.message || 'Unknown error'
				}`
			);
		}
	};

	if ( selectedProjectId === 'none' ) {
		return (
			<UnassignedTasksView
				onBack={ () => onSelectProject( 'all' ) }
				onTaskAction={ onTaskAction }
				privateOnly={ privateOnly }
			/>
		);
	}

	if ( hasSelectedProject ) {
		return (
			<ProjectWorkspace
				projectId={ Number( selectedProjectId ) }
				currentView={ currentProjectView }
				onViewChange={ onProjectViewChange }
				onBack={ () => onSelectProject( 'all' ) }
				onEditProject={ onEditProject }
				onTaskAction={ onTaskAction }
			/>
		);
	}

	if ( isLoading ) {
		return <div className="pandat69-loading">Loading projects…</div>;
	}
	if ( isError ) {
		return (
			<div className="pandat69-error" role="alert">
				{ error?.message || 'Projects could not be loaded.' }
			</div>
		);
	}

	return (
		<ProjectIndex
			projects={ projects || [] }
			onAddProject={ () => onEditProject( null ) }
			onDeleteProject={ handleDelete }
			onEditProject={ onEditProject }
			onOpenProject={ onSelectProject }
			isDeleting={ deleteProject.isPending }
			onOpenUnassigned={ () => onSelectProject( 'none' ) }
		/>
	);
};

export default ProjectsView;
