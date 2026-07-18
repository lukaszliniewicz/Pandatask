import React, { useState, useEffect, useMemo, useRef } from 'react';
import { DndContext, KeyboardSensor, useSensor, useSensors, PointerSensor, TouchSensor } from '@dnd-kit/core';
import CompactTaskItem from './CompactTaskItem';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { useConfig } from '../context/ConfigContext';
import { wouldCreateTaskCycle } from '../utils';

const CompactListView = ({ tasks, onTaskAction, allSubtasksExpanded }) => {
    const { updateTask } = useTaskMutations();
    const { boardName, currentUser } = useConfig();
    const safeTasks = useMemo(() => tasks || [], [tasks]);
    
    const isUserBoard = boardName.startsWith('user_');

    // Set for Expanded IDs
    const [expandedIds, setExpandedIds] = useState(new Set());

    const parentIdsRef = useRef([]);
    parentIdsRef.current = Array.from(new Set(safeTasks.map((task) => Number(task.parent_task_id)).filter(Boolean)));

    useEffect(() => {
        if (allSubtasksExpanded) {
            setExpandedIds(new Set(parentIdsRef.current));
        } else {
            setExpandedIds(new Set());
        }
    }, [allSubtasksExpanded]);

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

    // Build Hierarchy
    const hierarchyRoots = useMemo(() => {
        const hierarchy = [];
        const taskMap = new Map(safeTasks.map(t => [t.id, { ...t, children: [] }]));
        
        safeTasks.forEach(task => {
            if (task.parent_task_id && taskMap.has(task.parent_task_id)) {
                taskMap.get(task.parent_task_id).children.push(taskMap.get(task.id));
            } else {
                hierarchy.push(taskMap.get(task.id));
            }
        });
        return hierarchy;
    }, [safeTasks]);

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

        if (isUserBoard) {
            const grouped = {};
            const ADDED_BY_ME_KEY = "Added by me";

            hierarchyRoots.forEach(root => {
                let groupName;
                const currentUserId = currentUser ? parseInt(currentUser.id, 10) : 0;
                const isAssigned = root.assigned_user_ids && root.assigned_user_ids.includes(String(currentUserId));
                const isCreator = root.creator_id === currentUserId;

                if (isAssigned) {
                    groupName = root.board_display_name || 'My Tasks';
                } else if (isCreator) {
                    groupName = ADDED_BY_ME_KEY;
                } else {
                    groupName = root.board_display_name || 'Other';
                }

                if (!grouped[groupName]) grouped[groupName] = [];
                grouped[groupName].push(root);
            });

            const sortedKeys = Object.keys(grouped).sort((a, b) => {
                if (a === ADDED_BY_ME_KEY) return 1;
                if (b === ADDED_BY_ME_KEY) return -1;
                return a.localeCompare(b);
            });

            return sortedKeys.map(groupName => {
                const flatGroup = flatten(grouped[groupName]);
                return (
                    <React.Fragment key={groupName}>
                        <li className="pandat69-compact-group-heading">{groupName}</li>
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
            });
        } else {
            const flatList = flatten(hierarchyRoots);
            return flatList.map(task => (
                <CompactTaskItem 
                    key={task.id} 
                    task={task} 
                    depth={task.depth}
                    hasChildren={task.hasChildren}
                    isExpanded={task.isExpanded}
                    onToggleExpand={() => toggleExpand(task.id)}
                    onAction={onTaskAction} 
                />
            ));
        }
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
