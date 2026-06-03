import React from 'react';
import { useDroppable } from '@dnd-kit/core';
import KanbanCard from './KanbanCard';

const KanbanColumn = ({ status, title, tasks, onTaskAction }) => {
    const { setNodeRef, isOver } = useDroppable({
        id: status,
    });

    const style = {
        backgroundColor: isOver ? 'rgba(0, 0, 0, 0.05)' : undefined,
    };

    return (
        <div className="pandat69-kanban-column" data-status={status}>
            <h3 className="pandat69-kanban-column-title">{title}</h3>
            <div 
                className="pandat69-kanban-column-content" 
                ref={setNodeRef}
                style={style}
            >
                {tasks.map(task => (
                    <KanbanCard key={task.id} task={task} onAction={onTaskAction} />
                ))}
            </div>
        </div>
    );
};

export default KanbanColumn;
