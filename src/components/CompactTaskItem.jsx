import React, { useState, useRef, useEffect } from 'react';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import StatusBadge from './StatusBadge';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { parseDate } from '../utils';
import Icon from './Icon';

const CompactTaskItem = ({ task, depth, hasChildren, isExpanded, onToggleExpand, onAction }) => {
    const { updateTask } = useTaskMutations();
    const [showMenu, setShowMenu] = useState(false);
    const menuRef = useRef(null);

    useEffect(() => {
        if (!showMenu) return undefined;

        const handleClick = (e) => {
            if (menuRef.current && !menuRef.current.contains(e.target)) setShowMenu(false);
        };
        document.addEventListener('mousedown', handleClick);
        return () => document.removeEventListener('mousedown', handleClick);
    }, [showMenu]);

    const { attributes, listeners, setNodeRef: setDragRef, transform, isDragging } = useDraggable({
        id: task.id,
        data: { type: 'Task', task }
    });

    const { setNodeRef: setDropRef, isOver } = useDroppable({
        id: task.id,
        data: { type: 'Task', task },
        disabled: isDragging
    });

    const setNodeRef = (node) => { setDragRef(node); setDropRef(node); };

    const style = {
        transform: CSS.Translate.toString(transform),
        opacity: isDragging ? 0.5 : 1,
        zIndex: isDragging ? 9999 : undefined,
    };

    const isArchived = task.archived == 1;
    const isSubtask = !!task.parent_task_id && task.parent_task_id !== "0";
    const itemClass = `pandat69-compact-item ${isOver && !isDragging ? 'pandat69-dnd-over' : ''} ${isArchived ? 'pandat69-archived-row' : ''}`;

    const handleArchiveToggle = async () => {
        if (updateTask.isPending) return;
        try {
            await updateTask.mutateAsync({ id: task.id, data: { archived: isArchived ? 0 : 1 } });
            setShowMenu(false);
        } catch { alert('Action failed'); }
    };

    return (
        <li ref={setNodeRef} style={style} className={itemClass}>
            <div
                className="pandat69-drag-handle"
                title="Drag onto another task to make this a subtask"
                aria-label="Drag task to change its parent"
                {...listeners}
                {...attributes}
            >
                <Icon name="grip" size={17} />
            </div>

            <div className="pandat69-compact-status-col">
                <StatusBadge task={task} mode="dot" />
            </div>

            <div className="pandat69-compact-name-cell">
                
                {/* Expand Toggle or Spacer */}
                <div className="pandat69-compact-expander">
                    {hasChildren ? (
                        <button 
                            type="button"
                            className={`pandat69-expand-btn ${isExpanded ? 'expanded' : ''}`} 
                            onClick={(e) => { e.stopPropagation(); onToggleExpand(); }}
                            aria-label={isExpanded ? 'Collapse subtasks' : 'Expand subtasks'}
                            aria-expanded={isExpanded}
                            style={{ 
                                background: 'none', 
                                border: 'none', 
                                cursor: 'pointer', 
                                padding: 0, 
                                display: 'flex', 
                                alignItems: 'center' 
                            }}
                        >
                            <Icon name={isExpanded ? 'chevron-down' : 'chevron-right'} size={16} />
                        </button>
                    ) : (
                        <span className="pandat69-expand-spacer" style={{width:'20px', display:'inline-block'}}></span>
                    )}
                </div>

                <button type="button" className="pandat69-compact-title" onClick={() => onAction('view', task)}>
                    {isSubtask && (
                        <Icon name="corner-down-right" size={15} />
                    )}
                    <span style={{ textDecoration: isArchived ? 'line-through' : 'none', color: isArchived ? '#999' : 'inherit' }}>
                        {task.name}
                    </span>
                </button>
            </div>

            <div className="pandat69-compact-meta">
                {task.deadline && (
                    <span title="Deadline" className={parseDate(task.deadline) < new Date() && task.status !== 'done' ? 'pandat69-meta-overdue' : ''}>
                        <Icon name="calendar" size={15} /> {task.deadline}
                    </span>
                )}
                {task.priority > 7 && (
                    <span title="High Priority" className="pandat69-meta-high-priority">
                        <Icon name="star" size={15} /> {task.priority}
                    </span>
                )}
            </div>

            <div className="pandat69-compact-avatar">
                {task.assigned_users && task.assigned_users[0] && (
                    <img 
                        src={task.assigned_users[0].avatar} 
                        alt={task.assigned_users[0].name} 
                        title={task.assigned_users[0].name}
                        width="24"
                        height="24"
                        loading="lazy"
                        decoding="async"
                    />
                )}
            </div>

            <div className="pandat69-kebab-menu-container" ref={menuRef}>
                <button type="button" className="pandat69-kebab-btn" aria-expanded={showMenu} aria-label="Task actions" onClick={(e) => { e.stopPropagation(); setShowMenu(!showMenu); }}>
                    <Icon name="more" />
                </button>
                {showMenu && (
                    <div className="pandat69-kebab-dropdown">
                        <button type="button" onClick={() => { onAction('edit', task); setShowMenu(false); }}><Icon name="pencil" /> Edit</button>
                        <button type="button" onClick={() => { onAction('view', task); setShowMenu(false); }}><Icon name="eye" /> View Details</button>
                        
                        <div style={{borderTop:'1px solid #eee', margin:'5px 0'}}></div>

                        <button type="button" onClick={() => { onAction('add-subtask', task); setShowMenu(false); }}>
                            <Icon name="list-plus" /> Add Subtask
                        </button>

                        {task.deadline && (
                            <button type="button" onClick={() => { onAction('gcal-export', task); setShowMenu(false); }}>
                                <Icon name="calendar-plus" /> Add to Calendar
                            </button>
                        )}

                        <div style={{borderTop:'1px solid #eee', margin:'5px 0'}}></div>

                        <button type="button" onClick={handleArchiveToggle} disabled={updateTask.isPending}>
                            <Icon name={isArchived ? 'undo' : 'archive'} /> {isArchived ? 'Unarchive' : 'Archive'}
                        </button>
                        
                        <button type="button" className="danger" onClick={() => { onAction('delete', task); setShowMenu(false); }}><Icon name="trash" /> Delete</button>
                    </div>
                )}
            </div>
        </li>
    );
};

export default CompactTaskItem;
