import React, { useState, useRef, useEffect } from 'react';
import { useTaskMutations } from '../hooks/useTaskMutations';

const STATUS_COLORS = {
    'pending': '#e9b44c',
    'in-progress': '#384D68',
    'done': '#3e8d63'
};

const STATUS_LABELS = {
    'pending': 'Pending',
    'in-progress': 'In Progress',
    'done': 'Done'
};

const StatusBadge = ({ task, mode = 'pill' }) => {
    // mode: 'pill' (text + color bg), 'dot' (color circle only)
    const { updateTask } = useTaskMutations();
    const [isOpen, setIsOpen] = useState(false);
    const wrapperRef = useRef(null);

    useEffect(() => {
        if (!isOpen) return undefined;

        const handleClickOutside = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [isOpen]);

    const handleStatusChange = async (newStatus) => {
        if (updateTask.isPending) return;
        setIsOpen(false);
        if (task.status !== newStatus) {
            try {
                await updateTask.mutateAsync({ id: task.id, data: { status: newStatus } });
            } catch (error) {
                console.error(error);
            }
        }
    };

    return (
        <div
            className="pandat69-status-badge-wrapper" 
            ref={wrapperRef} 
        >
            <button
                type="button"
                className={mode === 'dot'
                    ? 'pandat69-status-dot-interactive'
                    : `pandat69-status-pill status-${task.status} interactive`}
                title={STATUS_LABELS[task.status]}
                aria-label={`Change status. Current status: ${STATUS_LABELS[task.status]}`}
                aria-expanded={isOpen}
                aria-haspopup="menu"
                style={mode === 'dot' ? { backgroundColor: STATUS_COLORS[task.status] } : undefined}
                onClick={(event) => {
                    event.stopPropagation();
                    setIsOpen((open) => !open);
                }}
            >
                {mode === 'dot' ? (
                    <span className="pandat69-visually-hidden">{STATUS_LABELS[task.status]}</span>
                ) : STATUS_LABELS[task.status]}
            </button>

            {isOpen && (
                <div className="pandat69-status-dropdown-menu" role="menu" aria-label="Change status">
                    {Object.keys(STATUS_LABELS).map(key => (
                        <button
                            type="button"
                            key={key}
                            className={`pandat69-status-option ${task.status === key ? 'active' : ''}`}
                            onClick={(event) => {
                                event.stopPropagation();
                                handleStatusChange(key);
                            }}
                            role="menuitemradio"
                            aria-checked={task.status === key}
                            disabled={updateTask.isPending}
                        >
                            <span className="dot" style={{backgroundColor: STATUS_COLORS[key]}}></span>
                            {STATUS_LABELS[key]}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};

export default StatusBadge;
