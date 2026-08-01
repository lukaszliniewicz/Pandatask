import React, { useState, useMemo } from 'react';
import { DndContext, KeyboardSensor, useSensor, useSensors, PointerSensor, TouchSensor } from '@dnd-kit/core';
import CompactTaskItem from './CompactTaskItem';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { useConfig } from '../context/ConfigContext';
import {
    buildTaskListHierarchy,
    countTaskTree,
    groupTaskRoots,
} from '../taskListModel.mjs';
import { wouldCreateTaskCycle } from '../utils';

const CompactListView = ({ tasks, onTaskAction, allSubtasksExpanded, groupByProject = true }) => {
    const { updateTask } = useTaskMutations();
    const { boardName, currentUser } = useConfig();
    const safeTasks = useMemo(() => tasks || [], [tasks]);
    
    const isUserBoard = boardName.startsWith('user_');

    const parentIds = useMemo(
        () => Array.from(
            new Set(
                safeTasks.flatMap((task) => {
                    const parentId = Number(task.parent_task_id);
                    return parentId ? [parentId] : [];
                })
            )
        ),
        [safeTasks]
    );
    const [expandedIds, setExpandedIds] = useState(
        () => allSubtasksExpanded ? new Set(parentIds) : new Set()
    );

    const toggleExpand = (taskId) => {
        const newSet = new Set(expandedIds);
        if (newSet.has(taskId)) {
            newSet.delete(taskId);
        } else {
            newSet.add(taskId);
        }
        setExpandedIds(newSet);
    };

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(TouchSensor),
        useSensor(KeyboardSensor)
    );

    const hierarchyRoots = useMemo(
        () => buildTaskListHierarchy(safeTasks),
        [safeTasks]
    );
    const groupedContexts = useMemo(
        () => groupTaskRoots(hierarchyRoots, {
            isUserBoard,
            currentUserId: currentUser?.id,
            groupByProject,
        }),
        [currentUser?.id, groupByProject, hierarchyRoots, isUserBoard]
    );

    // Flatten visible list based on expansion
    const flatten = (items, depth = 0) => {
        let result = [];
        items.forEach(t => {
            const hasChildren = t.children && t.children.length > 0;
            const isExpanded = expandedIds.has(t.id);
            
            result.push({ ...t, depth, hasChildren, isExpanded });
            
            if (hasChildren && isExpanded) {
                result = result.concat(flatten(t.children, depth + 1));
            }
        });
        return result;
    };

    const renderContent = () => {
        if (!hierarchyRoots || hierarchyRoots.length === 0) {
            return (
                <li className="pandat69-no-tasks" style={{padding: '20px', textAlign:'center', color: '#999'}}>
                    No tasks found.
                </li>
            );
        }

        return groupedContexts.map((context) => (
            <React.Fragment key={context.key}>
                {context.label && (
                    <li className="pandat69-task-context-heading">
                        {context.label}
                    </li>
                )}
                {context.projects.map((project) => {
                    const flatGroup = flatten(project.tasks);
                    return (
                        <React.Fragment key={`${context.key}-${project.key}`}>
                            {project.label && (
                                <li className="pandat69-task-project-heading">
                                    <span>{project.label}</span>
                                    <span className="pandat69-task-group-count">
                                        {countTaskTree(project.tasks)}
                                    </span>
                                </li>
                            )}
                            {flatGroup.map(task => (
                                <CompactTaskItem
                                    key={task.id}
                                    task={task}
                                    depth={task.depth}
                                    hasChildren={task.hasChildren}
                                    isExpanded={task.isExpanded}
                                    onToggleExpand={() => toggleExpand(task.id)}
                                    onAction={onTaskAction}
                                />
                            ))}
                        </React.Fragment>
                    );
                })}
            </React.Fragment>
        ));
    };

    const handleDragEnd = (event) => {
        const { active, over } = event;
        if (!over) return;

        const taskId = active.id;
        const activeTask = safeTasks.find((task) => Number(task.id) === Number(taskId));
        if (!activeTask) return;
        
        if (over.data.current?.type === 'Task' && taskId !== over.id) {
            const targetTaskId = over.id;
            if (Number(activeTask.parent_task_id) === Number(targetTaskId)) return;
            if (wouldCreateTaskCycle(safeTasks, taskId, targetTaskId)) return;

            updateTask.mutate({ 
                id: taskId, 
                data: { parent_task_id: targetTaskId }
            });
        }
    };

    return (
        <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
            <div className="pandat69-compact-list-container">
                <ul className="pandat69-compact-list">
                    {renderContent()}
                </ul>
            </div>
        </DndContext>
    );
};

export default CompactListView;
