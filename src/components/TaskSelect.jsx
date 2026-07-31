import React, { useId, useMemo, useState, useEffect, useRef } from 'react';
import { useTasks } from '../hooks/useTasks';
import { getDatedPredecessorIds } from '../taskFormModel.mjs';
import Icon from './Icon';

const EMPTY_SELECTED_TASK_IDS = [];

const TaskSelect = ({
    selectedTaskIds = EMPTY_SELECTED_TASK_IDS,
    onChange,
    currentTaskId,
    excludeDone = false,
    mode = 'multiple',
    overrideBoardName,
    projectId,
    showDependencyBulkActions = false,
    inputLabel
}) => {
    const [search, setSearch] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const wrapperRef = useRef(null);
    const searchId = useId();
    
    const { data: tasks, isLoading } = useTasks({ status: '' }, overrideBoardName);

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

    const selectedTaskIdSet = useMemo(
        () => new Set(
            (Array.isArray(selectedTaskIds) ? selectedTaskIds : [selectedTaskIds])
                .filter(Boolean)
                .map((id) => Number(id))
        ),
        [selectedTaskIds]
    );

    const handleSearch = (e) => {
        setSearch(e.target.value);
        if (!isOpen) setIsOpen(true);
    };

    const toggleTask = (taskId) => {
        const id = parseInt(taskId, 10);
        
        if (mode === 'single') {
            onChange(id);
            setIsOpen(false); 
        } else {
            let newSelection;
            if (selectedTaskIdSet.has(id)) {
                newSelection = selectedTaskIds.filter(tid => tid !== id);
            } else {
                newSelection = [...selectedTaskIds, id];
            }
            onChange(newSelection);
        }
        setSearch('');
    };

    const handleRemoveSingle = () => {
        onChange(''); 
    };

    const filteredTasks = tasks ? tasks.filter(t => 
        (currentTaskId ? t.id != currentTaskId : true) && 
        t.name.toLowerCase().includes(search.toLowerCase()) &&
        t.archived != 1 &&
        (!excludeDone || t.status !== 'done')
    ) : [];

    const projectPredecessorIds = useMemo(
        () => getDatedPredecessorIds({
            tasks,
            currentTaskId,
            projectId,
            scope: 'project'
        }),
        [tasks, currentTaskId, projectId]
    );
    const boardPredecessorIds = useMemo(
        () => getDatedPredecessorIds({
            tasks,
            currentTaskId,
            scope: 'board'
        }),
        [tasks, currentTaskId]
    );
    const addPredecessors = (taskIds) => {
        const nextSelection = new Set(selectedTaskIdSet);
        taskIds.forEach((id) => nextSelection.add(id));
        onChange(Array.from(nextSelection));
    };
    const unselectedProjectCount = projectPredecessorIds.filter((id) => !selectedTaskIdSet.has(id)).length;
    const unselectedBoardCount = boardPredecessorIds.filter((id) => !selectedTaskIdSet.has(id)).length;

    let selectedTasksDisplay = [];
    if (mode === 'single') {
        if (selectedTaskIds) {
            const found = tasks?.find(t => parseInt(t.id, 10) === parseInt(selectedTaskIds, 10));
            if (found) selectedTasksDisplay = [found];
        }
    } else {
        selectedTasksDisplay = tasks 
            ? tasks.filter(t => selectedTaskIdSet.has(parseInt(t.id, 10)))
            : [];
    }

    return (
        <div className="pandat69-task-select-component" ref={wrapperRef} style={{ position: 'relative' }}>
            {selectedTasksDisplay.length > 0 && (
                <div className="pandat69-selected-users-container">
                    {selectedTasksDisplay.map(task => (
                        <span key={task.id} className="pandat69-selected-user">
                            {task.name}
                            <button
                                type="button"
                                className="pandat69-remove-user"
                                onClick={() => mode === 'single' ? handleRemoveSingle() : toggleTask(task.id)}
                                aria-label={`Remove ${task.name}`}
                                style={{ cursor: 'pointer', marginLeft: '5px' }}
                            >
                                <Icon name="x" size={14} />
                            </button>
                        </span>
                    ))}
                </div>
            )}

            {showDependencyBulkActions && mode === 'multiple' && (
                <div className="pandat69-task-select-bulk-actions" aria-label="Add task dependencies in bulk">
                    {projectId && (
                        <button
                            type="button"
                            className="pandat69-task-select-bulk-action"
                            onClick={() => addPredecessors(projectPredecessorIds)}
                            disabled={unselectedProjectCount === 0}
                        >
                            <Icon name="folder" size={14} />
                            Project&apos;s dated tasks
                            <span>{unselectedProjectCount}</span>
                        </button>
                    )}
                    <button
                        type="button"
                        className="pandat69-task-select-bulk-action"
                        onClick={() => addPredecessors(boardPredecessorIds)}
                        disabled={unselectedBoardCount === 0}
                    >
                        <Icon name="list-plus" size={14} />
                        Board&apos;s dated tasks
                        <span>{unselectedBoardCount}</span>
                    </button>
                </div>
            )}
            
            {(mode !== 'single' || selectedTasksDisplay.length === 0) && (
                <>
                <label className="pandat69-visually-hidden" htmlFor={searchId}>
                    {inputLabel || (mode === 'single' ? 'Search for a parent task' : 'Search for predecessor tasks')}
                </label>
                <input
                    id={searchId}
                    type="text" 
                    className="pandat69-input" 
                    placeholder={mode === 'single' ? "Select parent task..." : "Search tasks..."} 
                    value={search}
                    onChange={handleSearch} 
                    onFocus={() => setIsOpen(true)}
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded={isOpen}
                    aria-haspopup="listbox"
                    aria-controls={`${searchId}-suggestions`}
                />
                </>
            )}
            
            {isOpen && (
                <ul id={`${searchId}-suggestions`} className="pandat69-user-suggestions" aria-label="Task suggestions" style={{ display: 'block', position: 'absolute', zIndex: 1000, width: '100%', maxHeight: '200px', overflowY: 'auto', border: '1px solid #ddd', marginTop: '0', background: '#fff', boxShadow: '0 4px 6px rgba(0,0,0,0.1)' }}>
                    {isLoading ? (
                        <li className="pandat69-loading-small" style={{ padding: '8px' }}>Loading...</li>
                    ) : filteredTasks.length > 0 ? (
                        filteredTasks.map(task => {
                            let isSelected = false;
                            if (mode === 'single') {
                                isSelected = parseInt(selectedTaskIds, 10) === parseInt(task.id, 10);
                            } else {
                                isSelected = selectedTaskIdSet.has(parseInt(task.id, 10));
                            }

                            if (isSelected) return null;
                            
                            return (
                                <li
                                    key={task.id} 
                                    className="pandat69-user-suggestion-item"
                                >
                                    <button
                                        type="button"
                                        onClick={() => toggleTask(task.id)}
                                        style={{ padding: '8px', cursor: 'pointer', borderBottom: '1px solid #eee', width: '100%', textAlign: 'left' }}
                                    >
                                        #{task.id} - {task.name} <span style={{fontSize: '0.8em', color: '#888'}}>({task.status})</span>
                                    </button>
                                </li>
                            );
                        })
                    ) : (
                        <li style={{ padding: '8px', color: '#999' }}>No tasks found</li>
                    )}
                </ul>
            )}
        </div>
    );
};

export default TaskSelect;
