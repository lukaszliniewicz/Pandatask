import React, { useState, useMemo } from 'react';
import MonthCalendar from './MonthCalendar';
import { generateFutureOccurrences } from '../utils';

const CalendarView = ({ tasks, onTaskAction }) => {
    const [currentDate, setCurrentDate] = useState(new Date());

    const displayDate = currentDate.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

    const handlePrev = () => {
        const newDate = new Date(currentDate);
        newDate.setMonth(newDate.getMonth() - 1);
        setCurrentDate(newDate);
    };

    const handleNext = () => {
        const newDate = new Date(currentDate);
        newDate.setMonth(newDate.getMonth() + 1);
        setCurrentDate(newDate);
    };

    // Merge actual tasks with virtual recurring instances
    const allTasks = useMemo(() => {
        if (!tasks) return [];
        
        const realTasks = tasks.filter(t => t.is_recurring != 1);
        const templates = tasks.filter(t => t.is_recurring == 1);

        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const viewStart = new Date(year, month, 1);
        const viewEnd = new Date(year, month + 1, 0);

        const virtual = generateFutureOccurrences(templates, viewStart, viewEnd);
        return [...realTasks, ...virtual];
    }, [tasks, currentDate]);

    return (
        <div className="pandat69-view-container pandat69-calendar-view active">
            <div className="pandat69-date-selector">
                <button className="pandat69-button" onClick={handlePrev}>◀ Previous Month</button>
                <span className="pandat69-current-month-display-tasks">{displayDate}</span>
                <button className="pandat69-button" onClick={handleNext}>Next Month ▶</button>
            </div>
            <div className="pandat69-month-task-container-tasks">
                <MonthCalendar 
                    tasks={allTasks} 
                    currentDate={currentDate} 
                    onTaskClick={(task) => onTaskAction('view', task)} 
                />
            </div>
        </div>
    );
};

export default CalendarView;
