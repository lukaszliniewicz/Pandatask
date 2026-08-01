import React from 'react';
import Icon from '../Icon';
import StatusBadge from '../StatusBadge';

const TaskDetailHeader = ({ task, onNavigate, onEdit, contextInModalHeader = false }) => (
    <>
        {(!contextInModalHeader || (task.parent_task_id && task.parent_task_name)) && (
            <div className="pandat69-detail-header-row">
                <nav className="pandat69-breadcrumbs" aria-label="Task context">
                {task.parent_task_id && task.parent_task_name ? (
                    <>
                        <Icon name="arrow-up" size={15} />
                        <button
                            type="button"
                            className="pandat69-breadcrumb-button"
                            onClick={() => onNavigate(task.parent_task_id)}
                        >
                            Parent: {task.parent_task_name}
                        </button>
                        {!contextInModalHeader && <span className="sep" aria-hidden="true">/</span>}
                    </>
                ) : null}

                {!contextInModalHeader && (task.project_name ? (
                    <>
                        <Icon name="folder" size={15} />
                        <strong>{task.project_name}</strong>
                    </>
                ) : (
                    <span className="no-project">No Project</span>
                ))}
                </nav>
                {!contextInModalHeader && <div className="pandat69-detail-id">#{task.id}</div>}
            </div>
        )}

        <div className="pandat69-detail-title-row">
            <div className="pandat69-title-wrapper pandat69-detail-title-stack">
                <div className="pandat69-detail-title-line">
                    <h2>{task.name}</h2>
                    {onEdit && (
                        <button
                            type="button"
                            className="pandat69-icon-button-clean"
                            onClick={() => onEdit(task)}
                            title="Edit Details"
                            aria-label={`Edit ${task.name}`}
                        >
                            <Icon name="pencil" />
                        </button>
                    )}
                </div>
                {!contextInModalHeader && (
                    <div className="pandat69-status-wrapper">
                        <StatusBadge task={task} mode="pill" />
                    </div>
                )}
            </div>
        </div>
    </>
);

export default TaskDetailHeader;
