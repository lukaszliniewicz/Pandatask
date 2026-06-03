import React, { useState, useRef, useEffect } from 'react';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import StatusBadge from './StatusBadge';
import { useTaskMutations } from '../hooks/useTaskMutations';

const CompactTaskItem = ({ task, depth, hasChildren, isExpanded, onToggleExpand, onAction }) => {
    const { updateTask } = useTaskMutations();
    const [showMenu, setShowMenu] = useState(false);
    const menuRef = useRef(null);

    useEffect(() => {
        const handleClick = (e) => {
            if (menuRef.current && !menuRef.current.contains(e.target)) setShowMenu(false);
        };
        document.addEventListener('mousedown', handleClick);
        return () => document.removeEventListener('mousedown', handleClick);
    }, []);

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
        try {
            await updateTask.mutateAsync({ id: task.id, data: { archived: isArchived ? 0 : 1 } });
            setShowMenu(false);
        } catch(e) { alert('Action failed'); }
    };

    const handleQuickStatus = async (status) => {
        try {
            await updateTask.mutateAsync({ id: task.id, data: { status } });
            setShowMenu(false);
        } catch(e) { alert('Action failed'); }
    };

    return (
        <li ref={setNodeRef} style={style} className={itemClass}>
            <div className="pandat69-drag-handle" {...listeners} {...attributes}>
                <span className="dashicons dashicons-menu" style={{fontSize:'14px'}}></span>
            </div>

            <div className="pandat69-compact-status-col">
                <StatusBadge task={task} mode="dot" />
            </div>

            <div className="pandat69-compact-name-cell">
                
                {/* Expand Toggle or Spacer */}
                <div className="pandat69-compact-expander">
                    {hasChildren ? (
                        <button 
                            className={`pandat69-expand-btn ${isExpanded ? 'expanded' : ''}`} 
                            onClick={(e) => { e.stopPropagation(); onToggleExpand(); }}
                            style={{ 
                                background: 'none', 
                                border: 'none', 
                                cursor: 'pointer', 
                                padding: 0, 
                                display: 'flex', 
                                alignItems: 'center' 
                            }}
                        >
                            <span className={`dashicons ${isExpanded ? 'dashicons-arrow-down-alt2' : 'dashicons-arrow-right-alt2'}`} style={{ color: '#555' }}></span>
                        </button>
                    ) : (
                        <span className="pandat69-expand-spacer" style={{width:'20px', display:'inline-block'}}></span>
                    )}
                </div>

                <div className="pandat69-compact-title" onClick={() => onAction('view', task)}>
                    {isSubtask && (
                        <span className="dashicons dashicons-arrow-right-alt2" style={{color: '#aaa', marginRight: '5px', fontSize: '14px', lineHeight: '1.5'}}></span>
                    )}
                    <span style={{ textDecoration: isArchived ? 'line-through' : 'none', color: isArchived ? '#999' : 'inherit' }}>
                        {task.name}
                    </span>
                </div>
            </div>

            <div className="pandat69-compact-meta">
                {task.deadline && (
                    <span title="Deadline" className={new Date(task.deadline) < new Date() && task.status !== 'done' ? 'pandat69-meta-overdue' : ''}>
                        <span className="dashicons dashicons-calendar-alt"></span> {task.deadline}
                    </span>
                )}
                {task.priority > 7 && (
                    <span title="High Priority" className="pandat69-meta-high-priority">
                        <span className="dashicons dashicons-star-filled"></span> {task.priority}
                    </span>
                )}
            </div>

            <div className="pandat69-compact-avatar">
                {task.assigned_users && task.assigned_users[0] && (
                    <img 
                        src={task.assigned_users[0].avatar} 
                        alt={task.assigned_users[0].name} 
                        title={task.assigned_users[0].name}
                    />
                )}
            </div>

            <div className="pandat69-kebab-menu-container" ref={menuRef}>
                <button className="pandat69-kebab-btn" onClick={(e) => { e.stopPropagation(); setShowMenu(!showMenu); }}>
                    <span className="dashicons dashicons-ellipsis"></span>
                </button>
                {showMenu && (
                    <div className="pandat69-kebab-dropdown">
                        <button onClick={() => { onAction('edit', task); setShowMenu(false); }}><span className="dashicons dashicons-edit"></span> Edit</button>
                        <button onClick={() => { onAction('view', task); setShowMenu(false); }}><span className="dashicons dashicons-visibility"></span> View Details</button>
                        
                        <div style={{borderTop:'1px solid #eee', margin:'5px 0'}}></div>

                        <button onClick={() => { onAction('add-subtask', task); setShowMenu(false); }}>
                            <span className="dashicons dashicons-plus-alt2"></span> Add Subtask
                        </button>

                        {task.deadline && (
                            <button onClick={() => { onAction('gcal-export', task); setShowMenu(false); }}>
                                <span className="dashicons dashicons-calendar-alt"></span> Add to Calendar
                            </button>
                        )}

                        <div style={{borderTop:'1px solid #eee', margin:'5px 0'}}></div>

                        <button onClick={handleArchiveToggle}>
                            <span className="dashicons dashicons-archive"></span> {isArchived ? 'Unarchive' : 'Archive'}
                        </button>
                        
                        <button className="danger" onClick={() => { onAction('delete', task); setShowMenu(false); }}><span className="dashicons dashicons-trash"></span> Delete</button>
                    </div>
                )}
            </div>
        </li>
    );
};

export default CompactTaskItem;
