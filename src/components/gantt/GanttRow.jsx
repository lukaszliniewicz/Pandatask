import React from 'react';
import { getGanttBarGeometry } from '../../gantt/geometry.mjs';
import { compactGanttDate, getGanttStatusLabel } from './ganttViewConfig';
import Icon from '../Icon';

const GanttRow = ({
    collapsedIds,
    onTaskAction,
    onToggleCollapsed,
    row,
    timelineWindow,
    todayOffset,
    zoomConfig
}) => {
    const geometry = getGanttBarGeometry(row, timelineWindow, zoomConfig.dayWidth);
    const isCollapsed = collapsedIds.has(row.id);
    const dateLabel = `${compactGanttDate.format(row.effectiveStart)} – ${compactGanttDate.format(row.effectiveEnd)}`;
    const warnings = row.warnings.map((warning) => warning.label).join(' ');

    return (
        <div className={`pandat69-gantt-row ${row.task.is_gantt_context ? 'is-context' : ''}`} role="row">
            <div className="pandat69-gantt-task-cell" role="rowheader">
                <div className="pandat69-gantt-task-main" style={{ paddingLeft: `${Math.min(row.depth * 18, 90)}px` }}>
                    {row.children.length ? (
                        <button
                            type="button"
                            className="pandat69-gantt-expand"
                            onClick={() => onToggleCollapsed(row.id)}
                            aria-label={isCollapsed ? 'Expand subtasks' : 'Collapse subtasks'}
                            aria-expanded={!isCollapsed}
                        >
                            <Icon name={isCollapsed ? 'chevron-right' : 'chevron-down'} size={15} />
                        </button>
                    ) : (
                        <span className="pandat69-gantt-expand-spacer" />
                    )}
                    <span className={`pandat69-gantt-status status-${row.task.status}`} aria-hidden="true" />
                    <button type="button" className="pandat69-gantt-task-name" onClick={() => onTaskAction('view', row.task)} title={row.task.name}>
                        {row.task.name}
                        <span className="pandat69-visually-hidden">, {getGanttStatusLabel(row.task.status)}</span>
                    </button>
                    {row.warnings.length > 0 && (
                        <span className="pandat69-gantt-warning" title={warnings} aria-label={warnings}>
                            <Icon name="circle-alert" size={15} />
                        </span>
                    )}
                </div>
                <div className="pandat69-gantt-task-meta">
                    {row.task.project_name || row.task.board_display_name || 'No project'}
                    {row.scheduleKind.includes('summary') || row.scheduleKind === 'rollup-only' ? ' · roll-up' : ''}
                    {row.task.is_blocked ? ' · blocked' : ''}
                </div>
            </div>
            <div className="pandat69-gantt-timeline-cell" role="gridcell" aria-label={dateLabel}>
                {timelineWindow.todayIsVisible && (
                    <div className="pandat69-gantt-today-line" style={{ left: `${todayOffset}px` }} aria-hidden="true" />
                )}
                <div
                    className={`pandat69-gantt-bar status-${row.task.status} kind-${row.scheduleKind} ${row.warnings.length ? 'has-warning' : ''}`}
                    style={{ left: `${geometry.left}px`, width: `${geometry.width}px` }}
                    title={`${row.task.name}: ${dateLabel}`}
                    aria-hidden="true"
                >
                    {row.children.length > 0 && geometry.hasVisibleOwnRange && (
                        <span
                            className="pandat69-gantt-own-range"
                            style={{ left: `${geometry.ownLeft}px`, width: `${geometry.ownWidth}px` }}
                        />
                    )}
                    <span>{row.task.name}</span>
                </div>
            </div>
        </div>
    );
};

export default GanttRow;
