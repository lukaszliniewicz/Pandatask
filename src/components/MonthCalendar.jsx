import React, { useMemo } from 'react';
import { formatDate, parseDate } from '../utils';

const MonthCalendar = ({ tasks, currentDate, onTaskClick }) => {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    const { days, taskSpans, maxTasksPerDay } = useMemo(() => {
        const firstDayOfMonth = new Date(year, month, 1);
        // Get the first day of the calendar grid (Sunday)
        const firstDayOfGrid = new Date(firstDayOfMonth);
        firstDayOfGrid.setDate(firstDayOfGrid.getDate() - firstDayOfGrid.getDay());
        
        // Grid covers 42 days (6 weeks)
        const lastDayOfGrid = new Date(firstDayOfGrid);
        lastDayOfGrid.setDate(lastDayOfGrid.getDate() + 41);

        const daysArr = [];
        const dateToPosition = new Map();
        let curr = new Date(firstDayOfGrid);
        let pos = 0;

        while (curr <= lastDayOfGrid) {
            const dateStr = formatDate(curr);
            daysArr.push({
                date: new Date(curr),
                dateStr,
                dayNumber: curr.getDate(),
                isCurrentMonth: curr.getMonth() === month,
                isToday: new Date().toDateString() === curr.toDateString(),
                index: pos
            });
            dateToPosition.set(dateStr, pos);
            curr.setDate(curr.getDate() + 1);
            pos++;
        }

        // Calculate Task Spans
        const spans = [];
        // Sort tasks: start date, then length (desc)
        const visibleTasks = tasks.filter(t => t.start_date || t.deadline);
        visibleTasks.sort((a, b) => {
            const startA = a.start_date ? parseDate(a.start_date) : parseDate(a.deadline);
            const startB = b.start_date ? parseDate(b.start_date) : parseDate(b.deadline);
            if (startA.getTime() !== startB.getTime()) return startA - startB;
            
            const endA = a.deadline ? parseDate(a.deadline) : startA;
            const endB = b.deadline ? parseDate(b.deadline) : startB;
            return (endB - startB) - (endA - startA); // Longer first
        });

        const rowOccupancy = new Map(); // "row-pos" -> taskId

        visibleTasks.forEach(task => {
            let start = task.start_date ? parseDate(task.start_date) : null;
            let end = task.deadline ? parseDate(task.deadline) : null;
            if (!start && !end) return;
            if (!start) start = end;
            if (!end) end = start;

            // Clamp to grid
            if (end < firstDayOfGrid || start > lastDayOfGrid) return;
            const visibleStart = start < firstDayOfGrid ? firstDayOfGrid : start;
            const visibleEnd = end > lastDayOfGrid ? lastDayOfGrid : end;

            const startPos = dateToPosition.get(formatDate(visibleStart));
            const endPos = dateToPosition.get(formatDate(visibleEnd));

            if (startPos === undefined || endPos === undefined) return;

            let row = 0;
            let rowFound = false;
            while (!rowFound) {
                rowFound = true;
                for (let p = startPos; p <= endPos; p++) {
                    if (rowOccupancy.has(`${row}-${p}`)) {
                        rowFound = false;
                        break;
                    }
                }
                if (!rowFound) row++;
            }

            for (let p = startPos; p <= endPos; p++) {
                rowOccupancy.set(`${row}-${p}`, task.id);
            }

            spans.push({ task, startPos, endPos, row });
        });

        // Calculate max rows per day for cell height
        const dayCounts = new Map();
        spans.forEach(span => {
            for (let p = span.startPos; p <= span.endPos; p++) {
                dayCounts.set(p, Math.max(dayCounts.get(p) || 0, span.row + 1));
            }
        });
        const maxRows = Math.max(...Array.from(dayCounts.values()), 0);

        return { days: daysArr, taskSpans: spans, maxTasksPerDay: maxRows };
    }, [tasks, year, month]);

    const cellHeight = Math.max(120, 80 + (maxTasksPerDay * 25));

    return (
        <div className="pandat69-month-calendar-view">
            <div className="pandat69-month-calendar-header">
                {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => (
                    <div key={d} className="pandat69-month-day-header">{d}</div>
                ))}
            </div>
            <div className="pandat69-month-calendar-body">
                <div className="pandat69-month-grid-container">
                    {days.map((day, idx) => {
                        const weekIndex = Math.floor(idx / 7);
                        const dayIndex = idx % 7;
                        
                        // Find tasks intersecting this day
                        const cellTasks = taskSpans.filter(s => s.startPos <= idx && s.endPos >= idx);

                        return (
                            <div 
                                key={day.dateStr}
                                className={`pandat69-month-day-cell ${!day.isCurrentMonth ? 'pandat69-other-month' : ''} ${day.isToday ? 'pandat69-today' : ''}`}
                                style={{
                                    gridRow: weekIndex + 1,
                                    gridColumn: dayIndex + 1,
                                    minHeight: `${cellHeight}px`
                                }}
                            >
                                <div className="pandat69-month-day-number">{day.dayNumber}</div>
                                {cellTasks.map(span => {
                                    const isStart = span.startPos === idx;
                                    const isEnd = span.endPos === idx;
                                    
                                    const style = {
                                        marginTop: `${35 + (span.row * 25)}px`,
                                        marginLeft: isStart ? '0' : '-10px',
                                        marginRight: isEnd ? '0' : '-10px',
                                        borderRadius: `${isStart ? '4px' : '0'} ${isEnd ? '4px' : '0'} ${isEnd ? '4px' : '0'} ${isStart ? '4px' : '0'}`
                                    };

                                    return (
                                        <div 
                                            key={`${span.task.id}-${idx}`}
                                            className={`pandat69-month-task-bar pandat69-status-${span.task.status}`}
                                            style={style}
                                            title={span.task.name}
                                            onClick={(e) => { e.stopPropagation(); onTaskClick(span.task); }}
                                        >
                                            {isStart && (
                                                <span className="pandat69-task-name">
                                                    {span.task.is_recurring == 1 && <span className="dashicons dashicons-update"></span>}
                                                    {span.task.name}
                                                </span>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
};

export default MonthCalendar;
