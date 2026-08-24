import React from 'react';
import { getBoardTabs } from '../../boardTabs.mjs';


const BoardTabNavigation = ({ currentTab, onChange, isUserBoard = false }) => {
    const tabs = getBoardTabs( isUserBoard );
    return (
    <nav className="pandat69-desktop-nav" aria-label="Board sections">
        <ul className="pandat69-tab-navigation" role="tablist">
            {tabs.map((tab) => (
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
};

export default BoardTabNavigation;
