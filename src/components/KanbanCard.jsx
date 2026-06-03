import React from 'react';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { useConfig } from '../context/ConfigContext';
import { parseDate } from '../utils';

const KanbanCard = ({ task, onAction }) => {
    const { isStandalone } = useConfig();
    
    const { attributes, listeners, setNodeRef: setDragRef, transform, isDragging } = useDraggable({
        id: task.id,
        data: { type: 'Task', task }
    });

    const { setNodeRef: setDropRef, isOver } = useDroppable({
        id: task.id,
        data: { type: 'Task', task },
        disabled: isDragging
    });

    const setNodeRef = (node) => {
        setDragRef(node);
        setDropRef(node);
    };

    const style = {
        transform: CSS.Translate.toString(transform),
        opacity: isDragging ? 0.5 : 1,
        zIndex: isDragging ? 9999 : undefined,
        cursor: 'grab',
        outline: isOver && !isDragging ? '2px dashed #384D68' : 'none',
        boxShadow: isOver && !isDragging ? '0 0 10px rgba(56, 77, 104, 0.3)' : '',
    };

    const isRecurring = task.is_recurring == 1;
    const isSubtask = !!task.parent_task_id;
    
    // Overdue logic
    const today = new Date();
    today.setHours(0,0,0,0);
    const isOverdue = task.deadline && parseDate(task.deadline) < today && task.status !== 'done';

    let cardClasses = `pandat69-kanban-card pandat69-status-${task.status}`;
    if (isSubtask) cardClasses += ' pandat69-kanban-subtask';
    if (isOverdue) cardClasses += ' pandat69-overdue-task';

    // Subtask Info
    let subtaskInfo = null;
    if (isSubtask) {
        subtaskInfo = (
            <div className="pandat69-kanban-subtask-label">
                <span className="dashicons dashicons-subdirectory"></span> Subtask of: {task.parent_task_name || 'task'}
            </div>
        );
    }

    const handleButtonClick = (action, e) => {
        e.stopPropagation();
        // MouseDown can trigger drag even on button click sometimes, prevent default might help
        // but pointer-events: none on children of button is safer or just this stopPropagation.
        if (onAction) onAction(action, task);
    };

    return (
        <div 
            ref={setNodeRef} 
            style={style} 
            {...listeners} 
            {...attributes}
            className={cardClasses} 
            data-task-id={task.id}
        >
            {subtaskInfo}
            
            <div className="pandat69-kanban-card-title">
                {isRecurring && (
                    <span className="pandat69-recurring-label">
                        <span className="dashicons dashicons-update"></span>
                    </span>
                )}
                {task.name}
            </div>

            <div className="pandat69-kanban-card-meta">
                <span title="Priority">
                    <span className="dashicons dashicons-star-filled"></span> {task.priority}
                </span>
                {task.deadline && (
                    <span title="Deadline">
                        <span className="dashicons dashicons-calendar-alt"></span> {task.deadline}
                    </span>
                )}
                {task.attachment_type && (
                    <span title="Attachment">
                        <span className="dashicons dashicons-paperclip"></span>
                    </span>
                )}
                {task.board_display_name && (
                    <span className="pandat69-board-label" title={`Board: ${task.board_display_name}`}>
                        <span className="dashicons dashicons-category"></span> {task.board_display_name}
                    </span>
                )}
            </div>

            {task.assigned_users && task.assigned_users.length > 0 && (
                <div className="pandat69-kanban-card-users">
                    <div className="pandat69-meta-user-list">
                        {task.assigned_users.map(u => (
                            <img 
                                key={u.id} 
                                src={u.avatar} 
                                title={u.name} 
                                className="pandat69-meta-user-avatar" 
                                loading="lazy" 
                                alt={u.name} 
                            />
                        ))}
                    </div>
                </div>
            )}

            <div className="pandat69-kanban-card-footer">
                <button 
                    type="button" 
                    className="pandat69-icon-button pandat69-edit-task-btn" 
                    title="Edit"
                    onPointerDown={(e) => e.stopPropagation()} 
                    onClick={(e) => handleButtonClick('edit', e)}
                >
                    <span className="dashicons dashicons-edit"></span>
                </button>
                <button 
                    type="button" 
                    className="pandat69-icon-button pandat69-view-task-details-btn" 
                    title="Details"
                    onPointerDown={(e) => e.stopPropagation()} 
                    onClick={(e) => handleButtonClick('view', e)}
                >
                    <span className="dashicons dashicons-visibility"></span>
                </button>
            </div>
        </div>
    );
};

export default KanbanCard;
