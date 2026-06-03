import React, { useState, useRef, useEffect } from 'react';
import { useTaskMutations } from '../hooks/useTaskMutations';

const StatusBadge = ({ task, mode = 'pill' }) => {
    // mode: 'pill' (text + color bg), 'dot' (color circle only)
    const { updateTask } = useTaskMutations();
    const [isOpen, setIsOpen] = useState(false);
    const wrapperRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const handleStatusChange = async (newStatus) => {
        setIsOpen(false);
        if (task.status !== newStatus) {
            try {
                await updateTask.mutateAsync({ id: task.id, data: { status: newStatus } });
            } catch (error) {
                console.error(error);
            }
        }
    };

    const statusColors = {
        'pending': '#e9b44c',
        'in-progress': '#384D68',
        'done': '#3e8d63'
    };

    const statusLabels = {
        'pending': 'Pending',
        'in-progress': 'In Progress',
        'done': 'Done'
    };

    return (
        <div 
            className="pandat69-status-badge-wrapper" 
            ref={wrapperRef} 
            onClick={(e) => { e.stopPropagation(); setIsOpen(!isOpen); }}
        >
            {mode === 'dot' ? (
                <div 
                    className="pandat69-status-dot-interactive"
                    title={statusLabels[task.status]}
                    style={{ backgroundColor: statusColors[task.status] }}
                ></div>
            ) : (
                <div className={`pandat69-status-pill status-${task.status} interactive`}>
                    {statusLabels[task.status]}
                </div>
            )}

            {isOpen && (
                <div className="pandat69-status-dropdown-menu">
                    {Object.keys(statusLabels).map(key => (
                        <div 
                            key={key}
                            className={`pandat69-status-option ${task.status === key ? 'active' : ''}`}
                            onClick={(e) => { e.stopPropagation(); handleStatusChange(key); }}
                        >
                            <span className="dot" style={{backgroundColor: statusColors[key]}}></span>
                            {statusLabels[key]}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};

export default StatusBadge;
