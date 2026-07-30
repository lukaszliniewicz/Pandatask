import React from 'react';
import { useConfig } from '../context/ConfigContext';
import Icon from './Icon';

const VIEWS = [
    { id: 'compact', label: 'Compact', icon: 'list-todo' },
    { id: 'list', label: 'List', icon: 'list' },
    { id: 'kanban', label: 'Kanban', icon: 'columns' },
    { id: 'calendar', label: 'Calendar', icon: 'calendar' },
    { id: 'gantt', label: 'Gantt', icon: 'gantt' },
];

const Header = ({ 
    onAddTask, 
    onManageCategories, 
    onFullscreen,
    isFullscreen,
    fullscreenToggleRef,
    currentView,
    onViewChange,
    toggleSidebar,
    isSidebarOpen
}) => {
    const { boardName } = useConfig();

    // Basic formatter for board name display
    const displayName = boardName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    const isUserBoard = boardName.startsWith('user_');
    const isGroupBoard = boardName.startsWith('group_');
    const showTitle = !isUserBoard && !isGroupBoard;

    return (
        <div className="pandat69-header">
            <div className="pandat69-header-left">
                {/* 
                   Hamburger Icon: Visible on both Desktop and Mobile now.
                   Controls the sidebar toggle via prop.
                */}
                <button 
                    type="button"
                    className="pandat69-icon-button pandat69-sidebar-toggle"
                    onClick={toggleSidebar}
                    title="Toggle Sidebar"
                    aria-label="Toggle project sidebar"
                    aria-expanded={isSidebarOpen}
                >
                    <Icon name="menu" />
                </button>

                <div className="pandat69-header-title">
                    {showTitle ? <h2>{displayName}</h2> : <h2>Task Board</h2>}
                </div>
            </div>

            <div className="pandat69-header-actions">
                <div className="pandat69-view-controls-container">
                    <span className="pandat69-view-label">View:</span>
                    {VIEWS.map(view => (
                        <button 
                            type="button"
                            key={view.id}
                            className={`pandat69-icon-button ${currentView === view.id ? 'active' : ''}`}
                            onClick={() => onViewChange(view.id)}
                            title={`${view.label} View`}
                            aria-label={`${view.label} view`}
                            aria-pressed={currentView === view.id}
                        >
                            <Icon name={view.icon} />
                        </button>
                    ))}
                </div>

                <div className="pandat69-header-buttons">
                    <button 
                        type="button"
                        className="pandat69-icon-button pandat69-add-task-btn" 
                        title="Add New Task" 
                        onClick={onAddTask}
                        aria-label="Add new task"
                    >
                        <Icon name="plus" />
                    </button>
                    <button 
                        type="button"
                        className="pandat69-icon-button pandat69-manage-categories-btn" 
                        title="Manage Categories"
                        onClick={onManageCategories}
                        aria-label="Manage categories"
                    >
                        <Icon name="tags" />
                    </button>
                    <button 
                        type="button"
                        ref={fullscreenToggleRef}
                        className="pandat69-icon-button pandat69-fullscreen-btn" 
                        title={isFullscreen ? 'Exit Full View' : 'Full View'}
                        onClick={onFullscreen}
                        aria-label={isFullscreen ? 'Exit full view' : 'Open full view'}
                        aria-pressed={isFullscreen}
                        data-pandatask-viewport-toggle
                    >
                        <Icon name={isFullscreen ? 'minimize' : 'maximize'} />
                    </button>
                </div>
            </div>
        </div>
    );
};

export default Header;
