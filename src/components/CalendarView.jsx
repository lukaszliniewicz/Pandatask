import React, { useState } from 'react';
import MonthCalendar from './MonthCalendar';
import Icon from './Icon';

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

    return (
        <div className="pandat69-view-container pandat69-calendar-view active">
            <div className="pandat69-date-selector">
                <button type="button" className="pandat69-button" onClick={handlePrev}><Icon name="chevron-left" /> Previous Month</button>
                <span className="pandat69-current-month-display-tasks">{displayDate}</span>
                <button type="button" className="pandat69-button" onClick={handleNext}>Next Month <Icon name="chevron-right" /></button>
            </div>
            <div className="pandat69-month-task-container-tasks">
                <MonthCalendar 
                    tasks={tasks || []}
                    currentDate={currentDate} 
                    onTaskClick={(task) => onTaskAction('view', task)} 
                />
            </div>
        </div>
    );
};

export default CalendarView;
