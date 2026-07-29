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
                    {projects && projects.length > 0 ? projects.map(project => (
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
                            <div className="pandat69-project-item-body">
                                <div className="pandat69-detail-item">
                                    <strong>Deadline:</strong> <span>{project.deadline || 'Not set'}</span>
                                </div>
                                {/* Assigned Users Display could go here */}
                            </div>
                            <div className="pandat69-project-task-list-container">
                                <h5>Tasks</h5>
                                {project.tasks && project.tasks.length > 0 ? (
                                    <ul className="pandat69-project-task-list">
                                        {project.tasks.map(t => (
                                            <li key={t.id}>
                                                <a href="#" className="pandat69-project-task-link" onClick={(e) => { e.preventDefault(); onTaskAction('view', t); }}>
                                                    {t.name}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p>No tasks in this project.</p>
                                )}
                            </div>
                        </li>
                    )) : (
                        <li className="pandat69-no-projects">No projects found.</li>
                    )}
                </ul>
            </div>

            <div className="pandat69-tasks-without-project-container" style={{ marginTop: '30px' }}>
                <h4 style={{ marginBottom: '15px', paddingBottom: '10px', borderBottom: '1px solid #eee', color: '#384D68' }}>
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
