import React from 'react';
import Icon from '../Icon';

const ProjectIndex = ( {
	projects,
	onAddProject,
	onDeleteProject,
	onEditProject,
	onOpenProject,
	onOpenUnassigned,
	isDeleting,
} ) => (
	<section className="pandat69-project-index" aria-labelledby="pandatask-project-index-title">
		<header className="pandat69-project-index-header">
			<div>
				<p className="pandat69-eyebrow">Project workspaces</p>
				<h3 id="pandatask-project-index-title">Choose a project</h3>
				<p>
					Open a focused list, dependency flow, or timeline without mixing in unrelated board work.
				</p>
			</div>
			<button
				type="button"
				className="pandat69-button pandat69-add-project-btn"
				onClick={ onAddProject }
			>
				<Icon name="plus" /> Add project
			</button>
		</header>

		<ul className="pandat69-project-card-grid">
				{ projects.map( ( project ) => {
					const activeCount = ( project.tasks || [] ).length;
					return (
						<li key={ project.id } className="pandat69-project-card">
							<div className="pandat69-project-card-main">
								<div className="pandat69-project-card-meta">
									{ project.board_scope === 'group' && (
										<span>
											<Icon name="users" size={ 13 } />
											{ project.board_display_name }
										</span>
									) }
									<span className={ project.deadline ? '' : 'is-muted' }>
										<Icon name="calendar" size={ 13 } />
										{ project.deadline ? `Due ${ project.deadline }` : 'No deadline' }
									</span>
								</div>
								<button
									type="button"
									className="pandat69-project-card-link"
									onClick={ () => onOpenProject( project.id ) }
								>
									<span>{ project.name }</span>
									<Icon name="arrow-right" />
								</button>
								<p className="pandat69-project-card-description">
									{ project.description || 'No project overview yet.' }
								</p>
							</div>
							<footer className="pandat69-project-card-footer">
								<span>{ activeCount } active { activeCount === 1 ? 'task' : 'tasks' }</span>
								{ project.can_manage !== false && (
									<div className="pandat69-project-card-actions">
										<button
											type="button"
											className="pandat69-icon-button"
											onClick={ () => onEditProject( project ) }
											aria-label={ `Edit project ${ project.name }` }
										>
											<Icon name="pencil" size={ 16 } />
										</button>
										<button
											type="button"
											className="pandat69-icon-button"
											onClick={ () => onDeleteProject( project ) }
											disabled={ isDeleting }
											aria-label={ `Delete project ${ project.name }` }
										>
											<Icon name="trash" size={ 16 } />
										</button>
									</div>
								) }
							</footer>
						</li>
					);
				} ) }
			<li className="pandat69-project-card pandat69-project-card-unassigned">
				<div className="pandat69-project-card-main">
					<div className="pandat69-project-card-meta">
						<span>
							<Icon name="inbox" size={ 13 } /> Board housekeeping
						</span>
					</div>
					<button
						type="button"
						className="pandat69-project-card-link"
						onClick={ onOpenUnassigned }
					>
						<span>Unassigned work</span>
						<Icon name="arrow-right" />
					</button>
					<p className="pandat69-project-card-description">
						Tasks without a project, kept separate from focused project plans.
					</p>
				</div>
				<footer className="pandat69-project-card-footer">
					<span>Review and organise</span>
				</footer>
			</li>
		</ul>
	</section>
);

export default ProjectIndex;
