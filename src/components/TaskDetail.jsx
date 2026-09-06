import React, { useState } from 'react';
import { useTaskDetails } from '../hooks/useTaskDetails';
import TaskComments from './task-detail/TaskComments';
import TaskDetailDescription from './task-detail/TaskDetailDescription';
import TaskDetailHeader from './task-detail/TaskDetailHeader';
import TaskDetailMetadata from './task-detail/TaskDetailMetadata';
import TaskDetailSubtasks from './task-detail/TaskDetailSubtasks';
import TaskHistory from './task-detail/TaskHistory';
import TaskTimeCard from './work/TaskTimeCard';
import { useConfig } from '../context/ConfigContext';
import TaskMoveDialog from './TaskMoveDialog';
import TaskChecklist from './task-detail/TaskChecklist';
import TaskRecurrenceCard from './task-detail/TaskRecurrenceCard';

const TaskDetail = ({ taskId, onEdit, onAddSubtask, onNavigate, contextInModalHeader = false }) => {
    const { features } = useConfig();
    const { data: task, isLoading, isError } = useTaskDetails(taskId);
    const [isMoveOpen, setIsMoveOpen] = useState(false);

    if (isLoading)
        return (
            <div className="pandat69-loading" role="status">
                Loading details...
            </div>
        );
    if (isError || !task)
        return (
            <div className="pandat69-error" role="alert">
                Failed to load task details.
            </div>
        );

    const handleNavigate = id => {
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
                onMove={() => setIsMoveOpen(true)}
                contextInModalHeader={contextInModalHeader}
            />
            <TaskDetailMetadata task={task} />
            <TaskRecurrenceCard key={`series-${task.id}`} task={task} onNavigate={handleNavigate} />
            <TaskDetailSubtasks task={task} onAddSubtask={onAddSubtask} onNavigate={handleNavigate} />
            {features?.workLog !== false && <TaskTimeCard task={task} onNavigate={handleNavigate} />}
            <TaskDetailDescription task={task} />
            <TaskChecklist key={task.id} task={task} />
            <TaskComments task={task} />
            <TaskHistory taskId={task.id} />
            <TaskMoveDialog
                task={task}
                isOpen={isMoveOpen}
                onClose={() => setIsMoveOpen(false)}
            />
        </article>
    );
};

export default TaskDetail;
