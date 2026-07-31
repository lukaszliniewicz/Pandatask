import React, { useEffect, useId, useRef, useState } from 'react';
import GanttLegend from './gantt/GanttLegend';
import GanttSummary from './gantt/GanttSummary';
import GanttTimeline from './gantt/GanttTimeline';
import GanttToolbar from './gantt/GanttToolbar';
import GanttUnscheduled from './gantt/GanttUnscheduled';
import {
    GANTT_HEADER_HEIGHT,
    GANTT_LABEL_WIDTH,
    GANTT_ROW_HEIGHT
} from './gantt/ganttViewConfig';
import { useGanttViewModel } from './gantt/useGanttViewModel';

const GanttView = ({ tasks, onTaskAction }) => {
    const [zoom, setZoom] = useState('month');
    const [showCompleted, setShowCompleted] = useState(false);
    const [collapsedIds, setCollapsedIds] = useState(new Set());
    const scrollRef = useRef(null);
    const didInitialScroll = useRef(false);
    const dependencyMarkerId = `pandat69-gantt-arrow-${useId().replace(/[^a-z0-9_-]/gi, '')}`;
    const view = useGanttViewModel(tasks, showCompleted, collapsedIds, zoom);

    useEffect(() => {
        if (
            didInitialScroll.current ||
            !scrollRef.current ||
            !view.scheduledRows.length
        ) {
            return;
        }

        didInitialScroll.current = true;
        scrollRef.current.scrollLeft = Math.max(
            0,
            view.initialFocusOffset -
                (scrollRef.current.clientWidth - GANTT_LABEL_WIDTH) / 2
        );
        scrollRef.current.scrollTop = Math.max(
            0,
            GANTT_HEADER_HEIGHT +
                view.initialFocusRowIndex * GANTT_ROW_HEIGHT -
                scrollRef.current.clientHeight / 2
        );
    }, [
        view.initialFocusOffset,
        view.initialFocusRowIndex,
        view.scheduledRows.length
    ]);

    const toggleCollapsed = (id) => {
        setCollapsedIds((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const scrollTimeline = (direction) => {
        scrollRef.current?.scrollBy({
            left: direction * scrollRef.current.clientWidth * 0.7,
            behavior: 'smooth'
        });
    };

    const scrollToToday = () => {
        if (!scrollRef.current || !view.timelineWindow.todayIsVisible) return;
        scrollRef.current.scrollTo({
            left: Math.max(
                0,
                view.todayOffset -
                    (scrollRef.current.clientWidth - GANTT_LABEL_WIDTH) / 2
            ),
            behavior: 'smooth'
        });
    };

    const toggleAllRows = () => {
        if (collapsedIds.size) {
            setCollapsedIds(new Set());
            return;
        }
        const collapsibleIds = new Set();
        for (const row of view.model.rows) {
            if (row.children.length) collapsibleIds.add(row.id);
        }
        setCollapsedIds(collapsibleIds);
    };

    return (
        <div className="pandat69-gantt-view">
            <GanttToolbar
                collapsedIds={collapsedIds}
                onCollapseToggle={toggleAllRows}
                onCompletedChange={setShowCompleted}
                onScroll={scrollTimeline}
                onToday={scrollToToday}
                onZoomChange={setZoom}
                showCompleted={showCompleted}
                todayIsVisible={view.timelineWindow.todayIsVisible}
                zoom={zoom}
            />
            <GanttSummary
                conflictCount={view.conflictCount}
                dayCount={view.timelineWindow.dayCount}
                excludedRowCount={view.timelineWindow.excludedRowCount}
                scheduledCount={view.allScheduledRows.length}
                unscheduledCount={view.unscheduledRows.length}
                wasBounded={view.timelineWindow.wasBounded}
            />

            {view.scheduledRows.length ? (
                <GanttTimeline
                    canvasHeight={view.canvasHeight}
                    canvasWidth={view.canvasWidth}
                    collapsedIds={collapsedIds}
                    dependencyMarkerId={dependencyMarkerId}
                    headerPeriods={view.headerPeriods}
                    onTaskAction={onTaskAction}
                    onToggleCollapsed={toggleCollapsed}
                    rowIndexes={view.rowIndexes}
                    rowsById={view.rowsById}
                    scheduledRows={view.scheduledRows}
                    scrollRef={scrollRef}
                    timelineWidth={view.timelineWidth}
                    timelineWindow={view.timelineWindow}
                    todayOffset={view.todayOffset}
                    visibleEdges={view.visibleEdges}
                    zoomConfig={view.zoomConfig}
                />
            ) : (
                <div className="pandat69-gantt-empty">No scheduled tasks match the current filters.</div>
            )}

            <GanttUnscheduled rows={view.unscheduledRows} onTaskAction={onTaskAction} />
            <GanttLegend />
        </div>
    );
};

export default GanttView;
