import React, { useState } from 'react';
import TimelineCalendar from './TimelineCalendar';
import MonthCalendar from './MonthCalendar';
import { getMonday, formatDisplayDate, generateFutureOccurrences } from '../utils';
import { useTasks } from '../hooks/useTasks';

const OverviewView = ({ onTaskAction }) => {
    const [period, setPeriod] = useState('week'); // 'week' or 'month'
    const [currentDate, setCurrentDate] = useState(new Date());
    
    // We fetch all non-archived tasks for overview.
    // In a real app with backend pagination, we'd fetch by date range.
    // Here we replicate legacy behavior: fetch all, filter client side.
    const { data: tasks, isLoading } = useTasks({ archived: false });

    // Calculate dates
    const currentWeekStart = getMonday(currentDate);
    const currentWeekEnd = new Date(currentWeekStart);
    currentWeekEnd.setDate(currentWeekEnd.getDate() + 6);

    const displayString = period === 'week' 
        ? `${formatDisplayDate(currentWeekStart)} - ${formatDisplayDate(currentWeekEnd)}`
        : currentDate.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

    const handlePrev = () => {
        const newDate = new Date(currentDate);
        if (period === 'week') {
            newDate.setDate(newDate.getDate() - 7);
        } else {
            newDate.setMonth(newDate.getMonth() - 1);
        }
        setCurrentDate(newDate);
    };

    const handleNext = () => {
        const newDate = new Date(currentDate);
        if (period === 'week') {
            newDate.setDate(newDate.getDate() + 7);
        } else {
            newDate.setMonth(newDate.getMonth() + 1);
        }
        setCurrentDate(newDate);
    };

    const getVisibleTasks = () => {
        if (!tasks) return [];
        
        const realTasks = tasks.filter(t => t.is_recurring != 1);
        const templates = tasks.filter(t => t.is_recurring == 1);
        
        let start, end;
        if (period === 'week') {
            start = currentWeekStart;
            end = currentWeekEnd;
        } else {
            start = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
            end = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0);
        }

        const virtual = generateFutureOccurrences(templates, start, end);
        return [...realTasks, ...virtual];
    };

    if (isLoading) return <div className="pandat69-loading">Loading overview...</div>;

    const visibleTasks = getVisibleTasks();

    return (
        <div className="pandat69-tab-overview">
            <div className="pandat69-overview-controls" style={{ marginBottom: '15px' }}>
                <label style={{ marginRight: '15px' }}>
                    <input 
                        type="radio" 
                        name="overview_period" 
                        checked={period === 'week'} 
                        onChange={() => setPeriod('week')} 
                    /> Week
                </label>
                <label>
                    <input 
                        type="radio" 
                        name="overview_period" 
                        checked={period === 'month'} 
                        onChange={() => setPeriod('month')} 
                    /> Month
                </label>
            </div>

            <div className="pandat69-date-selector">
                <button className="pandat69-button" onClick={handlePrev}>◀ Previous</button>
                <span className="pandat69-current-week-display" style={{ fontWeight: 'bold' }}>{displayString}</span>
                <button className="pandat69-button" onClick={handleNext}>Next ▶</button>
            </div>

            <div className="pandat69-overview-content" style={{ marginTop: '20px' }}>
                {period === 'week' ? (
                    <TimelineCalendar 
                        tasks={visibleTasks} 
                        startDate={currentWeekStart} 
                        endDate={currentWeekEnd} 
                        onTaskClick={(task) => onTaskAction('view', task)}
                    />
                ) : (
                    <MonthCalendar 
                        tasks={visibleTasks} 
                        currentDate={currentDate} 
                        onTaskClick={(task) => onTaskAction('view', task)}
                    />
                )}
            </div>
        </div>
    );
};

export default OverviewView;
