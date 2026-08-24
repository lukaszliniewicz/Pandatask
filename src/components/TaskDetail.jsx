import React from 'react';
import { useTaskDetails } from '../hooks/useTaskDetails';
import TaskComments from './task-detail/TaskComments';
import TaskDetailDescription from './task-detail/TaskDetailDescription';
import TaskDetailHeader from './task-detail/TaskDetailHeader';
import TaskDetailMetadata from './task-detail/TaskDetailMetadata';
import TaskDetailSubtasks from './task-detail/TaskDetailSubtasks';
import TaskHistory from './task-detail/TaskHistory';
import TaskTimeCard from './work/TaskTimeCard';

const TaskDetail = ({ taskId, onEdit, onAddSubtask, onNavigate, contextInModalHeader = false }) => {
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
            <TaskDetailHeader
                task={task}
                onNavigate={handleNavigate}
                onEdit={onEdit}
                contextInModalHeader={contextInModalHeader}
            />
            <TaskDetailMetadata task={task} />
            <TaskDetailSubtasks task={task} onAddSubtask={onAddSubtask} onNavigate={handleNavigate} />
            <TaskTimeCard task={task} />
            <TaskDetailDescription task={task} />
            <TaskComments task={task} />
            <TaskHistory taskId={task.id} />
        </article>
    );
};

export default TaskDetail;
