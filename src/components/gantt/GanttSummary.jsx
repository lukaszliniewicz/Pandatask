import React from 'react';
import Icon from '../Icon';

const GanttSummary = ({
    conflictCount,
    dayCount,
    excludedRowCount,
    scheduledCount,
    unscheduledCount,
    wasBounded
}) => (
    <div className="pandat69-gantt-summary" aria-live="polite">
        <span><strong>{scheduledCount}</strong> scheduled</span>
        <span><strong>{unscheduledCount}</strong> unscheduled</span>
        {conflictCount > 0 && (
            <span className="has-warning">
                <Icon name="circle-alert" size={15} />
                <strong>{conflictCount}</strong> schedule {conflictCount === 1 ? 'warning' : 'warnings'}
            </span>
        )}
        {wasBounded && (
            <span className="has-warning">
                <Icon name="circle-alert" size={15} />
                Timeline limited to {dayCount} days
                {excludedRowCount > 0
                    ? `; ${excludedRowCount} scheduled ${excludedRowCount === 1 ? 'task is' : 'tasks are'} outside this window`
                    : ''}
            </span>
        )}
        <span className="pandat69-gantt-semantics">
            Dependency arrows are explicit; subtask order is never assumed.
        </span>
    </div>
);

export default GanttSummary;
