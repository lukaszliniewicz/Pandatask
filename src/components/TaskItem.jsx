import React, { useState, useRef, useEffect } from 'react';
import { useConfig } from '../context/ConfigContext';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { parseDate } from '../utils';

const TaskItem = ({ task, onAction }) => {
    const { isStandalone } = useConfig();
    const { updateTask } = useTaskMutations();
    const [showStatusDropdown, setShowStatusDropdown] = useState(false);
    const [showDescription, setShowDescription] = useState(false);
    const dropdownRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setShowStatusDropdown(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const handleStatusChange = async (newStatus) => {
        setShowStatusDropdown(false);
        if (task.status !== newStatus) {
            try {
                await updateTask.mutateAsync({ id: task.id, data: { status: newStatus } });
            } catch (error) {
                console.error(error);
                alert('Failed to update status');
            }
        }
    };

    const isArchived = task.archived == 1;
    const isSubtask = !!task.parent_task_id;
    const isRecurring = task.is_recurring == 1;
    
    // Status classes
    const statusClass = `pandat69-task-status pandat69-status-${task.status}`;
    
    // Overdue check
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const isOverdue = !isArchived && 
                      (task.status === 'pending' || task.status === 'in-progress') &&
                      task.deadline && 
                      parseDate(task.deadline) < today;

    const itemClasses = `pandat69-task-item ${isArchived ? 'pandat69-archived-task' : ''} ${isSubtask ? 'pandat69-subtask' : ''} ${isOverdue ? 'pandat69-overdue-task' : ''}`;

    const handleAction = (action, e) => {
        e.preventDefault();
        e.stopPropagation();
        if (onAction) onAction(action, task);
    };

    const hasDescription = !!(task.description || task.description_rendered);

    return (
        <li className={itemClasses} data-task-id={task.id} onClick={(e) => handleAction('view', e)}>
            <div className="pandat69-task-item-details">
                {isSubtask && (
                    <div className="pandat69-subtask-indicator">
                        <span className="dashicons dashicons-subdirectory"></span>
                    </div>
                )}
                
                <div className="pandat69-task-item-name">
                    <a href="#" className="pandat69-view-task-link" onClick={(e) => handleAction('view', e)}>
                        {isRecurring && (
                            <span className="pandat69-recurring-label">
                                <span className="dashicons dashicons-update"></span> Recurring
                            </span>
                        )}
                        {task.name}
                    </a>
                </div>

                <div className="pandat69-task-item-meta">
                    <span style={{ position: 'relative' }} ref={dropdownRef}>
                        <span 
                            className={statusClass} 
                            data-status={task.status}
                            onClick={(e) => { e.stopPropagation(); setShowStatusDropdown(!showStatusDropdown); }}
                            style={{ cursor: 'pointer' }}
                        >
                            {task.status.replace('-', ' ')}
                        </span>
                        {showStatusDropdown && (
                            <div className="pandat69-status-dropdown">
                                {['pending', 'in-progress', 'done'].map(status => (
                                    <div 
                                        key={status}
                                        className={`pandat69-status-option pandat69-status-${status} ${task.status === status ? 'pandat69-current-status' : ''}`}
                                        onClick={(e) => { e.stopPropagation(); handleStatusChange(status); }}
                                    >
                                        {status.replace('-', ' ')}
                                    </div>
                                ))}
                            </div>
                        )}
                    </span>
                    <span><strong>Priority:</strong> {task.priority}</span>
                    {task.deadline && <span><strong>Deadline:</strong> {task.deadline}</span>}
                    {task.category_name && <span><strong>Category:</strong> {task.category_name}</span>}
                    {task.project_name && (
                        <span className="pandat69-project-label">
                            <strong>Project:</strong> {task.project_name}
                        </span>
                    )}
                    
                    {/* Assigned Users */}
                    {task.assigned_users && task.assigned_users.length > 0 && (
                        <span>
                            <strong>Assigned to:</strong>{' '}
                            <span className="pandat69-meta-user-list">
                                {task.assigned_users.map(user => (
                                    <span key={user.id} className="pandat69-meta-user" title={user.name}>
                                        <img src={user.avatar} className="pandat69-meta-user-avatar" alt={user.name} loading="lazy" />
                                        {user.name}
                                    </span>
                                ))}
                            </span>
                        </span>
                    )}
                </div>

                {showDescription && hasDescription && (
                    <div className="pandat69-task-description" style={{ marginTop: '15px', padding: '15px', background: '#f5f7fa', borderRadius: '4px', border: '1px solid #e0e5eb' }}>
                        <h4>Description</h4>
                        <div dangerouslySetInnerHTML={{ __html: task.description_rendered || task.description }} />
                    </div>
                )}
            </div>
            
            <div className="pandat69-task-item-footer">
                <div className="pandat69-footer-left">
                    <button type="button" className="pandat69-icon-button pandat69-edit-task-btn" title="Edit Task" onClick={(e) => handleAction('edit', e)}>
                        <span className="dashicons dashicons-edit"></span>
                    </button>
                    <button type="button" className="pandat69-icon-button pandat69-delete-task-btn" title="Delete Task" onClick={(e) => handleAction('delete', e)}>
                        <span className="dashicons dashicons-trash"></span>
                    </button>
                    
                    {hasDescription && (
                        <button 
                            type="button" 
                            className="pandat69-icon-button pandat69-show-description-btn" 
                            title={showDescription ? "Hide Description" : "Show Description"} 
                            onClick={(e) => { e.stopPropagation(); setShowDescription(!showDescription); }}
                        >
                            <span className="dashicons dashicons-editor-alignleft"></span>
                        </button>
                    )}

                    {isArchived ? (
                        <button type="button" className="pandat69-icon-button pandat69-unarchive-task-btn" title="Unarchive Task" onClick={(e) => handleAction('unarchive', e)}>
                            <span className="dashicons dashicons-undo"></span>
                        </button>
                    ) : (
                        <button type="button" className="pandat69-icon-button pandat69-archive-task-btn" title="Archive Task" onClick={(e) => handleAction('archive', e)}>
                            <span className="dashicons dashicons-archive"></span>
                        </button>
                    )}
                    
                    {!isSubtask && !isArchived && (
                        <button type="button" className="pandat69-icon-button pandat69-add-subtask-btn" title="Add Subtask" onClick={(e) => handleAction('add-subtask', e)}>
                            <span className="dashicons dashicons-plus-alt2"></span>
                        </button>
                    )}

                    {!isArchived && task.deadline && (
                        <button type="button" className="pandat69-icon-button pandat69-gcal-export-btn" title="Export to Google Calendar" onClick={(e) => handleAction('gcal-export', e)}>
                            <span className="dashicons dashicons-calendar-alt"></span>
                        </button>
                    )}

                    <button type="button" className="pandat69-icon-button pandat69-show-comments-btn" title="View Details" onClick={(e) => handleAction('view', e)}>
                        <span className="dashicons dashicons-admin-comments"></span>
                    </button>
                </div>
            </div>
        </li>
    );
};

export default TaskItem;
