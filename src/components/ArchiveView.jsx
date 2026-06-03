import React from 'react';
import TaskList from './TaskList';
import { useTasks } from '../hooks/useTasks';

const ArchiveView = ({ onTaskAction }) => {
    const { data: tasks, isLoading, isError, error } = useTasks({ archived: true });

    if (isLoading) return <div className="pandat69-loading">Loading archived tasks...</div>;
    if (isError) return <div className="pandat69-error">Error: {error.message}</div>;

    return (
        <div className="pandat69-archive-container">
            <div className="pandat69-controls">
                <p className="pandat69-archive-info">Archived tasks are stored here. They can be restored or permanently deleted.</p>
            </div>
            <TaskList tasks={tasks} onTaskAction={onTaskAction} />
        </div>
    );
};

export default ArchiveView;
