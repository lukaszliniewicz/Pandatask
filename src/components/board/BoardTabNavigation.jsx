import React from 'react';

const TABS = [
    { id: 'tasks', label: 'All Tasks' },
    { id: 'projects', label: 'Projects' },
    { id: 'overview', label: 'Overview' },
    { id: 'archive', label: 'Archive' },
    { id: 'report', label: 'Report' }
];

const BoardTabNavigation = ({ currentTab, onChange }) => (
    <nav className="pandat69-desktop-nav" aria-label="Board sections">
        <ul className="pandat69-tab-navigation" role="tablist">
            {TABS.map((tab) => (
                <li key={tab.id} role="presentation">
                    <button
                        type="button"
                        id={`pandatask-${tab.id}-tab`}
                        role="tab"
                        aria-selected={currentTab === tab.id}
                        aria-controls="pandatask-current-tabpanel"
                        className={`pandat69-tab-item ${currentTab === tab.id ? 'active' : ''}`}
                        onClick={() => onChange(tab.id)}
                    >
                        {tab.label}
                    </button>
                </li>
            ))}
        </ul>
    </nav>
);

export default BoardTabNavigation;
