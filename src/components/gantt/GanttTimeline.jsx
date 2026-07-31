import React from 'react';
import { formatGanttDate } from '../../ganttModel.mjs';
import {
    GANTT_LABEL_WIDTH
} from './ganttViewConfig';
import GanttDependencies from './GanttDependencies';
import GanttRow from './GanttRow';

const GanttTimeline = ({
    canvasHeight,
    canvasWidth,
    collapsedIds,
    dependencyMarkerId,
    headerPeriods,
    onTaskAction,
    onToggleCollapsed,
    rowIndexes,
    rowsById,
    scheduledRows,
    scrollRef,
    timelineWidth,
    timelineWindow,
    todayOffset,
    visibleEdges,
    zoomConfig
}) => (
    <>
        <div
            className="pandat69-gantt-scroll"
            ref={scrollRef}
            role="grid"
            aria-label="Task schedule"
            aria-rowcount={scheduledRows.length + 1}
            tabIndex="0"
        >
            <div
                className="pandat69-gantt-canvas"
                style={{
                    width: `${canvasWidth}px`,
                    minHeight: `${canvasHeight}px`,
                    '--pandatask-gantt-label-width': `${GANTT_LABEL_WIDTH}px`,
                    '--pandatask-gantt-timeline-width': `${timelineWidth}px`,
                    '--pandatask-gantt-day-width': `${zoomConfig.dayWidth}px`
                }}
            >
                <div className="pandat69-gantt-header-row" role="row">
                    <div className="pandat69-gantt-corner" role="columnheader">Task</div>
                    <div className="pandat69-gantt-date-header" role="columnheader">
                        {headerPeriods.map((period) => (
                            <div
                                key={period.key}
                                className={period.isWeekend ? 'is-weekend' : ''}
                                style={{ width: `${period.days * zoomConfig.dayWidth}px` }}
                            >
                                {period.label}
                            </div>
                        ))}
                    </div>
                </div>

                {scheduledRows.map((row) => (
                    <GanttRow
                        key={row.id}
                        collapsedIds={collapsedIds}
                        onTaskAction={onTaskAction}
                        onToggleCollapsed={onToggleCollapsed}
                        row={row}
                        timelineWindow={timelineWindow}
                        todayOffset={todayOffset}
                        zoomConfig={zoomConfig}
                    />
                ))}

                <GanttDependencies
                    dependencyMarkerId={dependencyMarkerId}
                    rowIndexes={rowIndexes}
                    rowsById={rowsById}
                    scheduledRows={scheduledRows}
                    timelineStart={timelineWindow.start}
                    timelineWidth={timelineWidth}
                    visibleEdges={visibleEdges}
                    zoomConfig={zoomConfig}
                />
            </div>
        </div>

        <div className="pandat69-gantt-mobile-list">
            {scheduledRows.map((row) => (
                <button type="button" key={row.id} className="pandat69-gantt-mobile-card" onClick={() => onTaskAction('view', row.task)}>
                    <span>{row.task.name}</span>
                    <small>
                        {formatGanttDate(row.effectiveStart)} – {formatGanttDate(row.effectiveEnd)}
                        {row.scheduleKind === 'rollup-only' ? ' · subtask roll-up' : ''}
                    </small>
                </button>
            ))}
        </div>
    </>
);

export default GanttTimeline;
