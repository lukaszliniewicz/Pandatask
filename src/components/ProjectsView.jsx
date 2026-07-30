import React from 'react';
import { useProjects } from '../hooks/useProjects';
import { useProjectMutations } from '../hooks/useProjectMutations';
import { useTasks } from '../hooks/useTasks';
import TaskList from './TaskList';
import Icon from './Icon';

const ProjectsView = ({ onEditProject, onTaskAction, privateOnly = false }) => {
    const { data: projects, isLoading: isLoadingProjects } = useProjects(undefined, { privateOnly });
    const { deleteProject } = useProjectMutations();
    
    // Fetch tasks that don't belong to any project
    const { data: noProjectTasks, isLoading: isLoadingTasks } = useTasks({
        project: 'none',
        archived: false,
        status: 'pending_in-progress',
        onlyMyTasks: privateOnly,
    });

    const handleDelete = async (id) => {
        if (confirm('Are you sure you want to delete this project? Tasks will be unassigned.')) {
            try {
                await deleteProject.mutateAsync(id);
            } catch (error) {
                alert('Failed to delete project: ' + (error.message || 'Unknown error'));
            }
        }
    };

    if (isLoadingProjects) return <div className="pandat69-loading">Loading projects...</div>;

    return (
        <div className="pandat69-projects-view">
            <div className="pandat69-header-actions">
                <button 
                    type="button"
                    className="pandat69-button pandat69-add-project-btn"
                    onClick={() => onEditProject(null)}
                >
                    <Icon name="plus" /> Add Project
                </button>
            </div>

            <div className="pandat69-project-list-container-view">
                <ul className="pandat69-project-list-view">
                    {projects && projects.length > 0 ? projects.map(project => {
                        const activeTasks = ( project.tasks || [] ).filter(
                            ( task ) => task.status !== 'done'
                        );

                        return (
                        <li key={project.id} className="pandat69-project-list-item">
                            <div className="pandat69-project-item-header">
                                <div className="pandat69-project-item-header-main">
                                    <div className="pandat69-project-title-row">
                                        <h4>{project.name}</h4>
                                        {project.board_scope === 'group' && (
                                            <span className="pandat69-project-source-badge">
                                                <Icon name="users" size={14} />
                                                {project.board_display_name}
                                            </span>
                                        )}
                                        <span className={`pandat69-project-deadline-meta ${project.deadline ? '' : 'is-empty'}`}>
                                            <Icon name="calendar" size={13} />
                                            {project.deadline ? `Due ${project.deadline}` : 'No deadline'}
                                        </span>
                                    </div>
                                    <p>{project.description}</p>
                                </div>
                                {project.can_manage !== false && (
                                    <div className="pandat69-project-item-actions">
                                        <button
                                            type="button"
                                            className="pandat69-icon-button pandat69-edit-project-btn"
                                            title="Edit Project"
                                            aria-label={`Edit project ${project.name}`}
                                            onClick={() => onEditProject(project)}
                                        >
                                            <Icon name="pencil" />
                                        </button>
                                        <button
                                            type="button"
                                            className="pandat69-icon-button pandat69-delete-project-btn"
                                            title="Delete Project"
                                            aria-label={`Delete project ${project.name}`}
                                            onClick={() => handleDelete(project.id)}
                                        >
                                            <Icon name="trash" />
                                        </button>
                                    </div>
                                )}
                            </div>
                            <div className="pandat69-project-task-list-container">
                                <div className="pandat69-project-task-heading">
                                    <h5>Active tasks</h5>
                                    <span>{activeTasks.length}</span>
                                </div>
                                {activeTasks.length > 0 ? (
                                    <ul className="pandat69-project-task-list">
                                        {activeTasks.map(t => (
                                            <li key={t.id} className={`status-${t.status || 'pending'}`}>
                                                <span
                                                    className="pandat69-project-task-status"
                                                    title={t.status === 'in-progress' ? 'In progress' : 'Pending'}
                                                />
                                                {t.parent_task_id ? (
                                                    <Icon name="corner-down-right" size={14} />
                                                ) : (
                                                    <span className="pandat69-project-task-icon-spacer" />
                                                )}
                                                <a href="#" className="pandat69-project-task-link" onClick={(e) => { e.preventDefault(); onTaskAction('view', t); }}>
                                                    {t.name}
                                                </a>
                                                {t.deadline && (
                                                    <span className="pandat69-project-task-deadline">
                                                        <Icon name="calendar" size={13} />
                                                        {t.deadline}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="pandat69-project-empty-tasks">No active tasks in this project.</p>
                                )}
                            </div>
                        </li>
                    )}) : (
                        <li className="pandat69-no-projects">No projects found.</li>
                    )}
                </ul>
            </div>

            <div className="pandat69-tasks-without-project-container">
                <h4>
                    Tasks without a project
                </h4>
                {isLoadingTasks ? (
                    <div className="pandat69-loading">Loading tasks...</div>
                ) : (
                    <TaskList tasks={noProjectTasks} onTaskAction={onTaskAction} />
                )}
            </div>
        </div>
    );
};

export default ProjectsView;
