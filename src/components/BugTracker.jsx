import React, { useState } from 'react';
import { useTasks } from '../hooks/useTasks';
import TaskList from './TaskList';
import Modal from './Modal';
import TaskForm from './TaskForm';
import TaskDetail from './TaskDetail';

const BugTracker = ({ boardName, defaultAssigneeId }) => {
    const [isFormOpen, setIsFormOpen] = useState(false);
    const [selectedTaskId, setSelectedTaskId] = useState(null);
    
    const { data: tasks, isLoading, isError, error } = useTasks({ 
        task_type_filter: 'bug',
        sort: 'deadline_asc' 
    });

    const handleTaskAction = (action, task) => {
        if (action === 'view') {
            setSelectedTaskId(task.id);
        }
    };

    return (
        <div className="pandat69-bug-tracker-container">
            <div className="pandat69-bug-tracker-header">
                <h3>Existing Issues</h3>
                <button 
                    className="pandat69-button pandat69-toggle-bug-form-btn"
                    onClick={() => setIsFormOpen(true)}
                >
                    Submit a New Issue
                </button>
            </div>
            
            <p className="pandat69-description">Click on a report to view its details and add comments.</p>
            
            <div className="pandat69-bug-list-container">
                {isLoading && <div className="pandat69-loading">Loading reports...</div>}
                {isError && <div className="pandat69-error">Error: {error?.message}</div>}
                {!isLoading && !isError && (
                    <div className="pandat69-bug-list">
                         <TaskList tasks={tasks} onTaskAction={handleTaskAction} />
                    </div>
                )}
            </div>

            <Modal
                isOpen={isFormOpen}
                onClose={() => setIsFormOpen(false)}
                title="Submit a New Issue"
            >
                <TaskForm 
                    onClose={() => setIsFormOpen(false)}
                    defaultTaskType="bug"
                    defaultValues={{
                        assigned_persons: defaultAssigneeId ? [parseInt(defaultAssigneeId, 10)] : []
                    }}
                />
            </Modal>

            <Modal
                isOpen={!!selectedTaskId}
                onClose={() => setSelectedTaskId(null)}
                title="Issue Details"
            >
                {selectedTaskId && <TaskDetail taskId={selectedTaskId} />}
            </Modal>
        </div>
    );
};

export default BugTracker;
