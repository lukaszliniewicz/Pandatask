import React from 'react';
import { useConfig } from '../context/ConfigContext';

const VIEWS = [
    { id: 'compact', label: 'Compact', icon: 'dashicons-menu' },
    { id: 'list', label: 'List', icon: 'dashicons-list-view' },
    { id: 'kanban', label: 'Kanban', icon: 'dashicons-layout' },
    { id: 'calendar', label: 'Calendar', icon: 'dashicons-calendar-alt' },
];

const Header = ({ 
    onAddTask, 
    onManageCategories, 
    onFullscreen,
    currentView,
    onViewChange,
    toggleSidebar
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
                    className="pandat69-icon-button pandat69-sidebar-toggle"
                    onClick={toggleSidebar}
                    title="Toggle Sidebar"
                >
                    <span className="dashicons dashicons-menu"></span>
                </button>

                <div className="pandat69-header-title">
                    {showTitle ? <h2>{displayName}</h2> : <h2 style={{opacity: 0.5}}>Task Board</h2>}
                </div>
            </div>

            <div className="pandat69-header-actions">
                <div className="pandat69-view-controls-container">
                    <span className="pandat69-view-label">View:</span>
                    {VIEWS.map(view => (
                        <button 
                            key={view.id}
                            className={`pandat69-icon-button ${currentView === view.id ? 'active' : ''}`}
                            onClick={() => onViewChange(view.id)}
                            title={`${view.label} View`}
                        >
                            <span className={`dashicons ${view.icon}`}></span>
                        </button>
                    ))}
                </div>

                <div className="pandat69-header-buttons">
                    <button 
                        className="pandat69-icon-button pandat69-add-task-btn" 
                        title="Add New Task" 
                        onClick={onAddTask}
                    >
                        <span className="dashicons dashicons-plus"></span>
                    </button>
                    <button 
                        className="pandat69-icon-button pandat69-manage-categories-btn" 
                        title="Manage Categories"
                        onClick={onManageCategories}
                    >
                        <span className="dashicons dashicons-tag"></span>
                    </button>
                    <button 
                        className="pandat69-icon-button pandat69-fullscreen-btn" 
                        title="Fullscreen Mode" 
                        onClick={onFullscreen}
                    >
                        <span className="dashicons dashicons-fullscreen-alt"></span>
                    </button>
                </div>
            </div>
        </div>
    );
};

export default Header;
