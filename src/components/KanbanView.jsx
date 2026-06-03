import React from 'react';
import { DndContext, useSensor, useSensors, PointerSensor, TouchSensor } from '@dnd-kit/core';
import KanbanColumn from './KanbanColumn';
import { useTaskMutations } from '../hooks/useTaskMutations';

const KanbanView = ({ tasks, onTaskAction }) => {
    // Tasks might be null/undefined during loading
    const safeTasks = tasks || [];
    const { updateTask } = useTaskMutations();

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: {
                distance: 8, // Require 8px movement before drag starts
            },
        }),
        useSensor(TouchSensor)
    );

    const handleDragEnd = (event) => {
        const { active, over } = event;

        if (!over) return;

        const taskId = active.id;
        const activeTask = safeTasks.find(t => t.id === taskId);
        if (!activeTask) return;

        if (over.data.current?.type === 'Task') {
            const targetTaskId = over.id;
            if (taskId === targetTaskId) return; 
            if (activeTask.parent_task_id === targetTaskId) return;

            updateTask.mutate({ 
                id: taskId, 
                data: { parent_task_id: targetTaskId }
            });
            return;
        }

        const newStatus = over.id;
        const validStatuses = ['pending', 'in-progress', 'done'];
        
        if (validStatuses.includes(newStatus) && activeTask.status !== newStatus) {
            updateTask.mutate({ id: taskId, data: { status: newStatus } });
        }
    };

    const columns = [
        { id: 'pending', title: 'Pending' },
        { id: 'in-progress', title: 'In Progress' },
        { id: 'done', title: 'Done' }
    ];

    return (
        <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
            <div className="pandat69-kanban-board">
                {columns.map(col => (
                    <KanbanColumn 
                        key={col.id} 
                        status={col.id} 
                        title={col.title} 
                        tasks={safeTasks.filter(t => t.status === col.id)} 
                        onTaskAction={onTaskAction}
                    />
                ))}
            </div>
        </DndContext>
    );
};

export default KanbanView;
