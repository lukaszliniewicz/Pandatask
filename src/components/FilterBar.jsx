import React, { useId, useState, useRef, useEffect } from 'react';
import { useConfig } from '../context/ConfigContext';
import { useProjects } from '../hooks/useProjects';
import Icon from './Icon';

const SORT_OPTIONS = [
    { value: 'name_asc', label: 'Name (A-Z)' },
    { value: 'project_name_asc', label: 'Project (A-Z)' },
    { value: 'priority_desc', label: 'Priority (High)' },
    { value: 'deadline_asc', label: 'Deadline (Soon)' },
    { value: 'created_at_desc', label: 'Newest First' },
];

const STATUS_OPTIONS = [
    { value: 'pending_in-progress', label: 'Active (Pending/In-Progress)' },
    { value: 'pending', label: 'Pending' },
    { value: 'in-progress', label: 'In Progress' },
    { value: 'done', label: 'Done' },
    { value: '', label: 'All Statuses' },
];

const Dropdown = ({ icon, title, value, options, onChange }) => {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return undefined;

        const handleClick = (event) => {
            if (ref.current && !ref.current.contains(event.target)) {
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', handleClick);
        return () => document.removeEventListener('pointerdown', handleClick);
    }, [open]);

    return (
        <div className="pandat69-icon-filter" ref={ref}>
            <button
                type="button"
                className={`pandat69-icon-button ${value && value !== '' && value !== 'name_asc' ? 'active' : ''}`}
                onClick={() => setOpen((isOpen) => !isOpen)}
                title={title}
                aria-label={title}
                aria-expanded={open}
                aria-haspopup="menu"
            >
                <Icon name={icon} />
            </button>
            {open && (
                <div className="pandat69-filter-dropdown" role="menu">
                    {options.map((option) => (
                        <button
                            type="button"
                            key={option.value}
                            className={`pandat69-filter-item ${value === option.value ? 'selected' : ''}`}
                            onClick={() => {
                                onChange(option.value);
                                setOpen(false);
                            }}
                            role="menuitemradio"
                            aria-checked={value === option.value}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};

const FilterBar = ({
    filters,
    onFilterChange,
    hideProjectSelect = false,
    showSubtaskToggle,
    onToggleSubtasks,
    allSubtasksExpanded,
    showProjectGrouping,
    groupByProject,
    onToggleProjectGrouping,
}) => {
    const { boardName } = useConfig();
    const isUserBoard = boardName?.startsWith('user_');
    const { data: projects } = useProjects(undefined, {
        privateOnly: isUserBoard && filters.onlyMyTasks,
    });
    const projectFilterId = useId();
    return (
        <div className="pandat69-filters">
            <div className="pandat69-filter-group-left">
                <input 
                    type="text" 
                    className="pandat69-input pandat69-search-input" 
                    placeholder="Search tasks..." 
                    aria-label="Search tasks"
                    value={filters.search || ''}
                    onChange={(e) => onFilterChange('search', e.target.value)}
                />
            </div>

            <div className="pandat69-filter-group-right">
                {/* Sorting Icon */}
                <Dropdown 
                    icon="arrow-down-up"
                    title="Sort Tasks"
                    value={filters.sort}
                    onChange={(val) => onFilterChange('sort', val)}
                    options={SORT_OPTIONS}
                />

                {/* Status Filter Icon */}
                <Dropdown 
                    icon="list-filter"
                    title="Filter by Status"
                    value={filters.status}
                    onChange={(val) => onFilterChange('status', val)}
                    options={STATUS_OPTIONS}
                />

                {!hideProjectSelect && (
                    <>
                    <label className="pandat69-visually-hidden" htmlFor={projectFilterId}>Filter tasks by project</label>
                    <select 
                        id={projectFilterId}
                        className="pandat69-select pandat69-project-filter-select"
                        value={filters.project}
                        onChange={(e) => onFilterChange('project', e.target.value)}
                        style={{maxWidth: '150px'}}
                    >
                        <option value="all">All Projects</option>
                        <option value="none">Unassigned</option>
                        {projects?.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                    </>
                )}

                {showProjectGrouping && (
                    <div className="pandat69-icon-filter">
                        <button
                            type="button"
                            className={`pandat69-icon-button ${groupByProject ? 'active' : ''}`}
                            onClick={onToggleProjectGrouping}
                            title={groupByProject ? 'Show a flat task list' : 'Group tasks by project'}
                            aria-label={groupByProject ? 'Disable project grouping' : 'Group tasks by project'}
                            aria-pressed={groupByProject}
                        >
                            <Icon name="layers" />
                        </button>
                    </div>
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
