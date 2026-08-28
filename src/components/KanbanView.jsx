import React from 'react';
import { DndContext, KeyboardSensor, useSensor, useSensors, PointerSensor, TouchSensor } from '@dnd-kit/core';
import KanbanColumn from './KanbanColumn';
import { useTaskStatusTransition } from '../context/CompletionContext';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { wouldCreateTaskCycle } from '../utils';

const KANBAN_COLUMNS = [
    { id: 'pending', title: 'Pending' },
    { id: 'in-progress', title: 'In Progress' },
    { id: 'done', title: 'Done' }
];
const VALID_STATUSES = new Set(KANBAN_COLUMNS.map((column) => column.id));

const KanbanView = ({ tasks, onTaskAction }) => {
    // Tasks might be null/undefined during loading
    const safeTasks = tasks || [];
    const { setStatus } = useTaskStatusTransition();
    const { updateTask } = useTaskMutations();

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: {
                distance: 8, // Require 8px movement before drag starts
            },
        }),
        useSensor(TouchSensor),
        useSensor(KeyboardSensor)
    );

    const handleDragEnd = (event) => {
        const { active, over } = event;

        if (!over) return;

        const taskId = active.id;
        const activeTask = safeTasks.find((task) => Number(task.id) === Number(taskId));
        if (!activeTask) return;

        if (over.data.current?.type === 'Task') {
            const targetTaskId = over.id;
            if (Number(taskId) === Number(targetTaskId)) return;
            if (Number(activeTask.parent_task_id) === Number(targetTaskId)) return;
            if (wouldCreateTaskCycle(safeTasks, taskId, targetTaskId)) return;

            updateTask.mutate({ 
                id: taskId, 
                data: { parent_task_id: targetTaskId }
            });
            return;
        }

        const newStatus = over.id;
        if (VALID_STATUSES.has(newStatus) && activeTask.status !== newStatus) {
            setStatus(activeTask, newStatus).catch((error) => console.error(error));
        }
    };

    return (
        <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
            <div className="pandat69-kanban-board">
                {KANBAN_COLUMNS.map(col => (
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
