import React from 'react';
import { useTaskDetails } from '../../hooks/useTaskDetails';

const STATUS_LABELS = {
    pending: 'Pending',
    'in-progress': 'In Progress',
    done: 'Done',
};

const TaskDetailModalMeta = ({ taskId }) => {
    const { data: task } = useTaskDetails(taskId);

    if (!task) return null;

    const statusLabel = STATUS_LABELS[task.status] || task.status || 'Unknown';

    return (
        <div className="pandat69-task-modal-meta" aria-label={`Task ${task.id}, ${statusLabel}, ${task.project_name || 'No project'}`}>
            <span
                className={`pandat69-task-modal-status-dot status-${task.status}`}
                title={statusLabel}
            >
                <span className="pandat69-visually-hidden">{statusLabel}</span>
            </span>
            <span className="pandat69-task-modal-id">#{task.id}</span>
            <span className="pandat69-task-modal-project" title={task.project_name || 'No project'}>
                {task.project_name || 'No project'}
            </span>
        </div>
    );
};

export default TaskDetailModalMeta;
