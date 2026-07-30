import React from 'react';
import Icon from './Icon';

const TABS = [
    { id: 'tasks', label: 'All Tasks' },
    { id: 'projects', label: 'Projects' },
    { id: 'overview', label: 'Overview' },
    { id: 'archive', label: 'Archive' },
    { id: 'report', label: 'Report' },
];

const VIEWS = [
    { id: 'compact', label: 'Compact', icon: 'list-todo' },
    { id: 'list', label: 'List', icon: 'list' },
    { id: 'kanban', label: 'Kanban', icon: 'columns' },
    { id: 'calendar', label: 'Calendar', icon: 'calendar' },
    { id: 'gantt', label: 'Gantt', icon: 'gantt' },
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
                            type="button"
                            key={view.id}
                            className={`pandat69-view-btn ${currentView === view.id ? 'active' : ''}`}
                            onClick={() => onViewChange(view.id)}
                            title={`${view.label} View`}
                            aria-pressed={currentView === view.id}
                        >
                            <Icon name={view.icon} /> {view.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};

export default ViewSwitcher;
