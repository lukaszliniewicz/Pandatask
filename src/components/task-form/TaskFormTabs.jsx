import React from 'react';

const TABS = [
    { id: 'general', label: 'General Details' },
    { id: 'schedule', label: 'Schedule & Rules' },
    { id: 'people', label: 'People & Files' }
];

const TaskFormTabs = ({ activeTab, errors, fieldPrefix, onChange }) => (
    <div className="pandat69-form-tabs" role="tablist" aria-label="Task form sections">
        {TABS.map((tab) => {
            const hasError = (
                tab.id === 'general' && Boolean(errors.name || errors.task_type || errors.description)
            ) || (
                tab.id === 'schedule' && Boolean(errors.deadline_days_after_start)
            );

            return (
                <button
                    type="button"
                    key={tab.id}
                    id={`${fieldPrefix}-${tab.id}-tab`}
                    role="tab"
                    aria-selected={activeTab === tab.id}
                    aria-controls={`${fieldPrefix}-${tab.id}-panel`}
                    className={`pandat69-form-tab ${activeTab === tab.id ? 'active' : ''}`}
                    onClick={() => onChange(tab.id)}
                >
                    {tab.label}
                    {hasError && (
                        <>
                            <span className="pandat69-tab-error-dot" aria-hidden="true" />
                            <span className="pandat69-visually-hidden"> contains errors</span>
                        </>
                    )}
                </button>
            );
        })}
    </div>
);

export default TaskFormTabs;
