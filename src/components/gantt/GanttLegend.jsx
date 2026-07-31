import React from 'react';

const GanttLegend = () => (
    <div className="pandat69-gantt-legend" aria-label="Schedule legend">
        <span><i className="status-pending" aria-hidden="true" /> Pending</span>
        <span><i className="status-in-progress" aria-hidden="true" /> In progress</span>
        <span><i className="status-done" aria-hidden="true" /> Done</span>
        <span><i className="is-rollup" aria-hidden="true" /> Parent/subtask roll-up</span>
        <span><i className="has-warning" aria-hidden="true" /> Schedule warning</span>
    </div>
);

export default GanttLegend;
