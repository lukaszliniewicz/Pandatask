import React from 'react';
import { getGanttDependencyPath } from '../../gantt/geometry.mjs';
import { GANTT_ROW_HEIGHT } from './ganttViewConfig';

const GanttDependencies = ({
    dependencyMarkerId,
    rowIndexes,
    rowsById,
    scheduledRows,
    timelineStart,
    timelineWidth,
    visibleEdges,
    zoomConfig
}) => (
    <svg
        className="pandat69-gantt-dependencies"
        width={timelineWidth}
        height={scheduledRows.length * GANTT_ROW_HEIGHT}
        viewBox={`0 0 ${timelineWidth} ${scheduledRows.length * GANTT_ROW_HEIGHT}`}
        aria-hidden="true"
    >
        <defs>
            <marker id={dependencyMarkerId} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                <path d="M 0 0 L 10 5 L 0 10 z" />
            </marker>
        </defs>
        {visibleEdges.map((edge) => (
            <path
                key={edge.id}
                d={getGanttDependencyPath({
                    edge,
                    rowIndexes,
                    rowsById,
                    timelineStart,
                    timelineWidth,
                    dayWidth: zoomConfig.dayWidth,
                    rowHeight: GANTT_ROW_HEIGHT
                })}
                className={edge.hasConflict ? 'has-conflict' : ''}
                markerEnd={`url(#${dependencyMarkerId})`}
            />
        ))}
    </svg>
);

export default GanttDependencies;
