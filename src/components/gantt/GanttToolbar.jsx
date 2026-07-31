import React from 'react';
import Icon from '../Icon';
import { GANTT_ZOOM_LEVELS } from './ganttViewConfig';

const GanttToolbar = ({
    collapsedIds,
    onCollapseToggle,
    onCompletedChange,
    onScroll,
    onToday,
    onZoomChange,
    showCompleted,
    todayIsVisible,
    zoom
}) => (
    <div className="pandat69-gantt-toolbar">
        <div className="pandat69-gantt-toolbar-group pandat69-gantt-navigation">
            <button type="button" className="pandat69-gantt-toolbar-button" onClick={() => onScroll(-1)} aria-label="Earlier dates" title="Earlier dates">
                <Icon name="chevron-left" />
            </button>
            <button
                type="button"
                className="pandat69-gantt-toolbar-button is-text"
                onClick={onToday}
                disabled={!todayIsVisible}
                title={todayIsVisible ? 'Scroll to today' : 'Today is outside the bounded timeline window'}
            >
                Today
            </button>
            <button type="button" className="pandat69-gantt-toolbar-button" onClick={() => onScroll(1)} aria-label="Later dates" title="Later dates">
                <Icon name="chevron-right" />
            </button>
        </div>

        <div className="pandat69-gantt-zoom" role="group" aria-label="Timeline scale">
            {Object.entries(GANTT_ZOOM_LEVELS).map(([id, config]) => (
                <button
                    type="button"
                    key={id}
                    className={zoom === id ? 'active' : ''}
                    onClick={() => onZoomChange(id)}
                    aria-pressed={zoom === id}
                >
                    {config.label}
                </button>
            ))}
        </div>

        <div className="pandat69-gantt-toolbar-group pandat69-gantt-options">
            <button type="button" className="pandat69-gantt-toolbar-button is-text" onClick={onCollapseToggle}>
                {collapsedIds.size ? 'Expand all' : 'Collapse all'}
            </button>
            <label className="pandat69-gantt-completed-toggle">
                <input type="checkbox" checked={showCompleted} onChange={(event) => onCompletedChange(event.target.checked)} />
                Show completed
            </label>
        </div>
    </div>
);

export default GanttToolbar;
