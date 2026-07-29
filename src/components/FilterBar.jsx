import React, { useState, useRef, useEffect } from 'react';
import { useConfig } from '../context/ConfigContext';
import { useProjects } from '../hooks/useProjects';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import Icon from './Icon';

const FilterBar = ({ filters, onFilterChange, hideProjectSelect = false, showSubtaskToggle, onToggleSubtasks, allSubtasksExpanded }) => {
    const { boardName } = useConfig();
    const isUserBoard = boardName?.startsWith('user_');
    const { data: projects } = useProjects(undefined, {
        privateOnly: isUserBoard && filters.onlyMyTasks,
    });
    const [search, setSearch] = useState(filters.search || '');
    const debouncedSearch = useDebouncedValue(search);

    useEffect(() => {
        if ((filters.search || '') !== debouncedSearch) {
            onFilterChange('search', debouncedSearch);
        }
    }, [debouncedSearch, filters.search, onFilterChange]);

    useEffect(() => {
        setSearch(filters.search || '');
    }, [filters.search]);
    
    // Helper for dropdowns
    const Dropdown = ({ icon, title, value, options, onChange }) => {
        const [open, setOpen] = useState(false);
        const ref = useRef(null);
        
        useEffect(() => {
            const handleClick = (e) => { if(ref.current && !ref.current.contains(e.target)) setOpen(false); };
            document.addEventListener('mousedown', handleClick);
            return () => document.removeEventListener('mousedown', handleClick);
        }, []);

        return (
            <div className="pandat69-icon-filter" ref={ref}>
                <button 
                    type="button"
                    className={`pandat69-icon-button ${value && value !== '' && value !== 'name_asc' ? 'active' : ''}`} 
                    onClick={() => setOpen(!open)}
                    title={title}
                    aria-label={title}
                    aria-expanded={open}
                    aria-haspopup="menu"
                >
                    <Icon name={icon} />
                </button>
                {open && (
                    <div className="pandat69-filter-dropdown" role="menu">
                        {options.map(opt => (
                            <button
                                type="button"
                                key={opt.value} 
                                className={`pandat69-filter-item ${value === opt.value ? 'selected' : ''}`}
                                onClick={() => { onChange(opt.value); setOpen(false); }}
                                role="menuitemradio"
                                aria-checked={value === opt.value}
                            >
                                {opt.label}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <div className="pandat69-filters">
            <div className="pandat69-filter-group-left">
                <input 
                    type="text" 
                    className="pandat69-input pandat69-search-input" 
                    placeholder="Search tasks..." 
                    aria-label="Search tasks"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>

            <div className="pandat69-filter-group-right">
                {/* Sorting Icon */}
                <Dropdown 
                    icon="arrow-down-up"
                    title="Sort Tasks"
                    value={filters.sort}
                    onChange={(val) => onFilterChange('sort', val)}
                    options={[
                        { value: 'name_asc', label: 'Name (A-Z)' },
                        { value: 'priority_desc', label: 'Priority (High)' },
                        { value: 'deadline_asc', label: 'Deadline (Soon)' },
                        { value: 'created_at_desc', label: 'Newest First' },
                    ]}
                />

                {/* Status Filter Icon */}
                <Dropdown 
                    icon="list-filter"
                    title="Filter by Status"
                    value={filters.status}
                    onChange={(val) => onFilterChange('status', val)}
                    options={[
                        { value: 'pending_in-progress', label: 'Active (Pending/In-Progress)' },
                        { value: 'pending', label: 'Pending' },
                        { value: 'in-progress', label: 'In Progress' },
                        { value: 'done', label: 'Done' },
                        { value: '', label: 'All Statuses' },
                    ]}
                />

                {!hideProjectSelect && (
                    <select 
                        className="pandat69-select pandat69-project-filter-select"
                        value={filters.project}
                        onChange={(e) => onFilterChange('project', e.target.value)}
                        style={{maxWidth: '150px'}}
                    >
                        <option value="all">All Projects</option>
                        <option value="none">Unassigned</option>
                        {projects?.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                )}

                {showSubtaskToggle && (
                    <div className="pandat69-icon-filter">
                        <button 
                            type="button"
                            className={`pandat69-icon-button ${allSubtasksExpanded ? 'active' : ''}`}
                            onClick={onToggleSubtasks}
                            title={allSubtasksExpanded ? "Collapse Subtasks" : "Expand Subtasks"}
                            aria-label={allSubtasksExpanded ? "Collapse subtasks" : "Expand subtasks"}
                            aria-pressed={allSubtasksExpanded}
                        >
                            <Icon name="list-tree" />
                        </button>
                    </div>
                )}

                <div className="pandat69-toggle-container">
                    <label className="pandat69-switch small">
                        <input 
                            type="checkbox" 
                            checked={filters.onlyMyTasks}
                            onChange={(e) => onFilterChange('onlyMyTasks', e.target.checked)}
                            aria-label={isUserBoard ? 'Show private-board items only' : 'Show my assigned tasks only'}
                        />
                        <span className="pandat69-slider pandat69-round"></span>
                    </label>
                    <span className="pandat69-toggle-label">{isUserBoard ? 'Private only' : 'Mine'}</span>
                </div>
            </div>
        </div>
    );
};

export default FilterBar;
