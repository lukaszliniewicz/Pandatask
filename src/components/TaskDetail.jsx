import React from 'react';
import { useTaskDetails } from '../hooks/useTaskDetails';
import TaskComments from './task-detail/TaskComments';
import TaskDetailDescription from './task-detail/TaskDetailDescription';
import TaskDetailHeader from './task-detail/TaskDetailHeader';
import TaskDetailMetadata from './task-detail/TaskDetailMetadata';
import TaskDetailSubtasks from './task-detail/TaskDetailSubtasks';
import TaskHistory from './task-detail/TaskHistory';

const TaskDetail = ({ taskId, onEdit, onAddSubtask, onNavigate }) => {
    const { data: task, isLoading, isError } = useTaskDetails(taskId);

    if (isLoading) return <div className="pandat69-loading" role="status">Loading details...</div>;
    if (isError || !task) return <div className="pandat69-error" role="alert">Failed to load task details.</div>;

    const handleNavigate = (id) => {
        if (onNavigate) {
            onNavigate(id);
        } else if (onEdit) {
            onEdit({ id });
        }
    };

    return (
        <article className="pandat69-task-detail-view">
            <TaskDetailHeader task={task} onNavigate={handleNavigate} onEdit={onEdit} />
            <TaskDetailMetadata task={task} />
            <TaskDetailSubtasks task={task} onAddSubtask={onAddSubtask} onNavigate={handleNavigate} />
            <TaskDetailDescription task={task} />
            <TaskComments task={task} />
            <TaskHistory taskId={task.id} />
        </article>
    );
};

export default TaskDetail;
