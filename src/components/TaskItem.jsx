import React, { useState, useRef, useEffect } from 'react';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { parseDate } from '../utils';
import Icon from './Icon';

const TaskItem = ({ task, onAction }) => {
    const { updateTask } = useTaskMutations();
    const [showStatusDropdown, setShowStatusDropdown] = useState(false);
    const [showDescription, setShowDescription] = useState(false);
    const dropdownRef = useRef(null);

    useEffect(() => {
        if (!showStatusDropdown) return undefined;

        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setShowStatusDropdown(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [showStatusDropdown]);

    const handleStatusChange = async (newStatus) => {
        if (updateTask.isPending) return;
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
        <li className={itemClasses} data-task-id={task.id}>
            <div className="pandat69-task-item-details">
                {isSubtask && (
                    <div className="pandat69-subtask-indicator">
                        <Icon name="corner-down-right" />
                    </div>
                )}
                
                <div className="pandat69-task-item-name">
                    <button type="button" className="pandat69-view-task-link" onClick={(e) => handleAction('view', e)}>
                        {isRecurring && (
                            <span className="pandat69-recurring-label">
                                <Icon name="refresh" size={15} /> Recurring
                            </span>
                        )}
                        {task.name}
                    </button>
                </div>

                <div className="pandat69-task-item-meta">
                    <span style={{ position: 'relative' }} ref={dropdownRef}>
                        <button
                            type="button"
                            className={statusClass} 
                            data-status={task.status}
                            onClick={(e) => { e.stopPropagation(); setShowStatusDropdown(!showStatusDropdown); }}
                            aria-expanded={showStatusDropdown}
                            aria-haspopup="menu"
                        >
                            {task.status.replace('-', ' ')}
                        </button>
                        {showStatusDropdown && (
                            <div className="pandat69-status-dropdown" role="menu" aria-label="Change task status">
                                {['pending', 'in-progress', 'done'].map(status => (
                                    <button
                                        type="button"
                                        key={status}
                                        className={`pandat69-status-option pandat69-status-${status} ${task.status === status ? 'pandat69-current-status' : ''}`}
                                        onClick={(e) => { e.stopPropagation(); handleStatusChange(status); }}
                                        role="menuitemradio"
                                        aria-checked={task.status === status}
                                        disabled={updateTask.isPending}
                                    >
                                        {status.replace('-', ' ')}
                                    </button>
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
                                        <img src={user.avatar} className="pandat69-meta-user-avatar" alt={user.name} width="20" height="20" loading="lazy" decoding="async" />
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
                    <button type="button" className="pandat69-icon-button pandat69-edit-task-btn" title="Edit Task" aria-label={`Edit ${task.name}`} onClick={(e) => handleAction('edit', e)}>
                        <Icon name="pencil" />
                    </button>
                    <button type="button" className="pandat69-icon-button pandat69-delete-task-btn" title="Delete Task" aria-label={`Delete ${task.name}`} onClick={(e) => handleAction('delete', e)}>
                        <Icon name="trash" />
                    </button>
                    
                    {hasDescription && (
                        <button 
                            type="button" 
                            className="pandat69-icon-button pandat69-show-description-btn" 
                            title={showDescription ? "Hide Description" : "Show Description"} 
                            aria-label={`${showDescription ? 'Hide' : 'Show'} description for ${task.name}`}
                            aria-expanded={showDescription}
                            onClick={(e) => { e.stopPropagation(); setShowDescription(!showDescription); }}
                        >
                            <Icon name="align-left" />
                        </button>
                    )}

                    {isArchived ? (
                        <button type="button" className="pandat69-icon-button pandat69-unarchive-task-btn" title="Unarchive Task" aria-label={`Unarchive ${task.name}`} onClick={(e) => handleAction('unarchive', e)}>
                            <Icon name="undo" />
                        </button>
                    ) : (
                        <button type="button" className="pandat69-icon-button pandat69-archive-task-btn" title="Archive Task" aria-label={`Archive ${task.name}`} onClick={(e) => handleAction('archive', e)}>
                            <Icon name="archive" />
                        </button>
                    )}
                    
                    {!isSubtask && !isArchived && (
                        <button type="button" className="pandat69-icon-button pandat69-add-subtask-btn" title="Add Subtask" aria-label={`Add subtask to ${task.name}`} onClick={(e) => handleAction('add-subtask', e)}>
                            <Icon name="list-plus" />
                        </button>
                    )}

                    {!isArchived && task.deadline && (
                        <button type="button" className="pandat69-icon-button pandat69-gcal-export-btn" title="Export to Google Calendar" aria-label={`Export ${task.name} to Google Calendar`} onClick={(e) => handleAction('gcal-export', e)}>
                            <Icon name="calendar-plus" />
                        </button>
                    )}

                    <button type="button" className="pandat69-icon-button pandat69-show-comments-btn" title="View Details" aria-label={`View details for ${task.name}`} onClick={(e) => handleAction('view', e)}>
                        <Icon name="message" />
                    </button>
                </div>
            </div>
        </li>
    );
};

export default TaskItem;
