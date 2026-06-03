import React, { useMemo } from 'react';
import { parseDate } from '../utils';

const TimelineCalendar = ({ tasks, startDate, endDate, onTaskClick }) => {
    const { days, taskRows, gridHeight } = useMemo(() => {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const dayList = [];
        let curr = new Date(start);
        while (curr <= end) {
            dayList.push(new Date(curr));
            curr.setDate(curr.getDate() + 1);
        }

        const totalDays = dayList.length;
        const dayWidth = 100 / totalDays;
        
        // Task distribution
        const rows = [];
        // Sort
        const sortedTasks = [...tasks].sort((a, b) => {
            const startA = a.start_date ? parseDate(a.start_date) : parseDate(a.deadline);
            const startB = b.start_date ? parseDate(b.start_date) : parseDate(b.deadline);
            if (!startA || !startB) return 0;
            if (startA.getTime() === startB.getTime()) {
                const endA = a.deadline ? parseDate(a.deadline) : startA;
                const endB = b.deadline ? parseDate(b.deadline) : startB;
                return (endB - endA); // Longer first
            }
            return startA - startB;
        });

        sortedTasks.forEach(task => {
            let tStart = task.start_date ? parseDate(task.start_date) : null;
            let tEnd = task.deadline ? parseDate(task.deadline) : null;
            if (!tStart && !tEnd) return;
            if (!tStart) tStart = tEnd;
            if (!tEnd) tEnd = tStart;

            if (tEnd < start || tStart > end) return;

            const visStart = tStart < start ? start : tStart;
            const visEnd = tEnd > end ? end : tEnd;

            // Difference in days
            const diffTimeStart = Math.abs(visStart - start);
            const startIndex = Math.floor(diffTimeStart / (1000 * 60 * 60 * 24)); 
            
            const diffTimeDur = Math.abs(visEnd - visStart);
            const duration = Math.floor(diffTimeDur / (1000 * 60 * 60 * 24)) + 1;

            const leftOffset = startIndex * dayWidth;
            const width = Math.max(duration * dayWidth, dayWidth * 0.1);

            const taskInfo = { task, startIndex, duration, leftOffset, width };

            // Find row
            let placed = false;
            for (let i = 0; i < rows.length; i++) {
                let overlap = false;
                for (const existing of rows[i]) {
                    const existEnd = existing.startIndex + existing.duration;
                    const thisEnd = startIndex + duration;
                    if (!(startIndex >= existEnd || thisEnd <= existing.startIndex)) {
                        overlap = true;
                        break;
                    }
                }
                if (!overlap) {
                    rows[i].push(taskInfo);
                    placed = true;
                    break;
                }
            }
            if (!placed) rows.push([taskInfo]);
        });

        const height = Math.max(400, 50 + (rows.length * 40));

        return { days: dayList, taskRows: rows, gridHeight: height };
    }, [tasks, startDate, endDate]);

    return (
        <div className="pandat69-calendar-view">
            <div className="pandat69-calendar-header">
                {days.map((day, i) => (
                    <div key={i} className="pandat69-calendar-day-column">
                        {day.toLocaleDateString(undefined, { month: 'short', day: 'numeric', weekday: 'short' })}
                    </div>
                ))}
            </div>
            <div className="pandat69-calendar-body">
                <div className="pandat69-calendar-grid" style={{ height: `${gridHeight}px`, position: 'relative' }}>
                    {days.map((day, i) => (
                        <div 
                            key={i} 
                            className="pandat69-calendar-day-cell"
                            style={{ left: `${i * (100/days.length)}%`, width: `${100/days.length}%`, height: '100%' }}
                        />
                    ))}
                    
                    {taskRows.map((row, rIndex) => (
                        row.map((tInfo, tIndex) => (
                            <div 
                                key={`${tInfo.task.id}-${rIndex}-${tIndex}`}
                                className={`pandat69-improved-task-bar pandat69-status-${tInfo.task.status}`}
                                style={{
                                    left: `${tInfo.leftOffset}%`,
                                    width: `${tInfo.width}%`,
                                    top: `${40 + (rIndex * 40)}px`
                                }}
                                title={tInfo.task.name}
                                onClick={(e) => { e.stopPropagation(); onTaskClick(tInfo.task); }}
                            >
                                <span className="pandat69-task-bar-text">
                                    {tInfo.task.is_recurring == 1 && <span className="dashicons dashicons-update"></span>}
                                    {tInfo.task.name}
                                </span>
                            </div>
                        ))
                    ))}
                </div>
            </div>
        </div>
    );
};

export default TimelineCalendar;
