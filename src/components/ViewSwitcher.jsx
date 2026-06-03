import React from 'react';

const TABS = [
    { id: 'tasks', label: 'All Tasks' },
    { id: 'projects', label: 'Projects' },
    { id: 'overview', label: 'Overview' },
    { id: 'archive', label: 'Archive' },
    { id: 'report', label: 'Report' },
];

const VIEWS = [
    { id: 'compact', label: 'Compact', icon: 'dashicons-menu' },
    { id: 'list', label: 'List', icon: 'dashicons-list-view' },
    { id: 'kanban', label: 'Kanban', icon: 'dashicons-layout' },
    { id: 'calendar', label: 'Calendar', icon: 'dashicons-calendar-alt' },
];

const ViewSwitcher = ({ currentTab, onTabChange, currentView, onViewChange }) => {
    return (
        <div className="pandat69-navigation-header">
            <ul className="pandat69-tab-navigation">
                {TABS.map(tab => (
                    <li 
                        key={tab.id}
                        className={`pandat69-tab-item ${currentTab === tab.id ? 'active' : ''}`}
                        onClick={() => onTabChange(tab.id)}
                    >
                        {tab.label}
                    </li>
                ))}
            </ul>
            
            {currentTab === 'tasks' && (
                <div className="pandat69-view-switcher">
                    {VIEWS.map(view => (
                        <button 
                            key={view.id}
                            className={`pandat69-view-btn ${currentView === view.id ? 'active' : ''}`}
                            onClick={() => onViewChange(view.id)}
                            title={`${view.label} View`}
                        >
                            <span className={`dashicons ${view.icon}`}></span> {view.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};

export default ViewSwitcher;
