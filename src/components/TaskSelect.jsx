import React, { useState, useEffect, useRef } from 'react';
import { useTasks } from '../hooks/useTasks';

const TaskSelect = ({ selectedTaskIds = [], onChange, currentTaskId, mode = 'multiple', overrideBoardName }) => {
    const [search, setSearch] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const wrapperRef = useRef(null);
    
    const { data: tasks, isLoading } = useTasks({ status: '' }, overrideBoardName);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

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
            if (selectedTaskIds.includes(id)) {
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
        t.archived != 1
    ) : [];

    let selectedTasksDisplay = [];
    if (mode === 'single') {
        if (selectedTaskIds) {
            const found = tasks?.find(t => parseInt(t.id, 10) === parseInt(selectedTaskIds, 10));
            if (found) selectedTasksDisplay = [found];
        }
    } else {
        selectedTasksDisplay = tasks 
            ? tasks.filter(t => selectedTaskIds.includes(parseInt(t.id, 10)))
            : [];
    }

    return (
        <div className="pandat69-task-select-component" ref={wrapperRef} style={{ position: 'relative' }}>
            <div className="pandat69-selected-users-container">
                {selectedTasksDisplay.map(task => (
                    <span key={task.id} className="pandat69-selected-user">
                        {task.name} 
                        <span 
                            className="pandat69-remove-user" 
                            onClick={() => mode === 'single' ? handleRemoveSingle() : toggleTask(task.id)}
                            style={{ cursor: 'pointer', marginLeft: '5px' }}
                        >
                            &times;
                        </span>
                    </span>
                ))}
            </div>
            
            {(mode !== 'single' || selectedTasksDisplay.length === 0) && (
                <input 
                    type="text" 
                    className="pandat69-input" 
                    placeholder={mode === 'single' ? "Select parent task..." : "Search tasks..."} 
                    value={search}
                    onChange={handleSearch} 
                    onFocus={() => setIsOpen(true)}
                />
            )}
            
            {isOpen && (
                <ul className="pandat69-user-suggestions" style={{ display: 'block', position: 'absolute', zIndex: 1000, width: '100%', maxHeight: '200px', overflowY: 'auto', border: '1px solid #ddd', marginTop: '0', background: '#fff', boxShadow: '0 4px 6px rgba(0,0,0,0.1)' }}>
                    {isLoading ? (
                        <li className="pandat69-loading-small" style={{ padding: '8px' }}>Loading...</li>
                    ) : filteredTasks.length > 0 ? (
                        filteredTasks.map(task => {
                            let isSelected = false;
                            if (mode === 'single') {
                                isSelected = parseInt(selectedTaskIds, 10) === parseInt(task.id, 10);
                            } else {
                                isSelected = selectedTaskIds.includes(parseInt(task.id, 10));
                            }

                            if (isSelected) return null;
                            
                            return (
                                <li 
                                    key={task.id} 
                                    className="pandat69-user-suggestion-item"
                                    onClick={() => toggleTask(task.id)}
                                    style={{ padding: '8px', cursor: 'pointer', borderBottom: '1px solid #eee' }}
                                >
                                    #{task.id} - {task.name} <span style={{fontSize: '0.8em', color: '#888'}}>({task.status})</span>
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
