import React, { useState } from 'react';
import { useReports } from '../hooks/useReports';
import { parseUtcDateTime } from '../utils';

const formatReportDate = (value) => {
    if (!value) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    return parseUtcDateTime(value).toLocaleString();
};

const ReportSection = ({ title, items, icon, metaPrefix, showOverdue }) => (
    <div className="pandat69-report-section">
        <h4>{title}</h4>
        {items.length > 0 ? (
            <ul className="pandat69-report-list">
                {items.map(task => (
                    <li key={task.id}>
                        <span className="pandat69-report-item-title">{task.name}</span>
                        <div className="pandat69-report-item-meta">
                            <span className={`dashicons dashicons-${icon}`}></span> {metaPrefix}: {formatReportDate(task.created_at || task.completed_at || task.deadline)}
                            {showOverdue && ` (${task.days_overdue} days overdue)`}
                        </div>
                        {task.assigned_user_names && (
                            <div className="pandat69-report-assigned">
                                <span className="dashicons dashicons-admin-users"></span> Assigned to: {task.assigned_user_names}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        ) : (
            <p>No items found.</p>
        )}
    </div>
);

const ReportView = () => {
    const [period, setPeriod] = useState('this_week');
    const [customRange, setCustomRange] = useState({ start: '', end: '' });
    
    const filters = { period };
    if (period === 'custom') {
        filters.start_date = customRange.start;
        filters.end_date = customRange.end;
    }

    const isCustomValid = period !== 'custom' || (customRange.start && customRange.end);
    
    const { data, isLoading, isError, error, refetch } = useReports(filters);

    const handleGenerate = () => {
        if (isCustomValid) refetch();
    };

    return (
        <div className="pandat69-tab-report">
            <div className="pandat69-report-controls">
                <div className="pandat69-report-field">
                    <label>Select Period:</label>
                    <select 
                        className="pandat69-select" 
                        value={period} 
                        onChange={(e) => setPeriod(e.target.value)}
                    >
                        <option value="this_week">This Week</option>
                        <option value="last_week">Last Week</option>
                        <option value="last_7_days">Last 7 Days</option>
                        <option value="this_month">This Month</option>
                        <option value="last_month">Last Month</option>
                        <option value="last_30_days">Last 30 Days</option>
                        <option value="custom">Custom Date Range</option>
                    </select>
                </div>
                
                {period === 'custom' && (
                    <div className="pandat69-report-custom-dates" style={{ display: 'flex', gap: '10px' }}>
                        <div className="pandat69-report-field">
                            <label>From:</label>
                            <input 
                                type="date" 
                                className="pandat69-input" 
                                value={customRange.start}
                                onChange={(e) => setCustomRange(prev => ({ ...prev, start: e.target.value }))}
                            />
                        </div>
                        <div className="pandat69-report-field">
                            <label>To:</label>
                            <input 
                                type="date" 
                                className="pandat69-input" 
                                value={customRange.end}
                                onChange={(e) => setCustomRange(prev => ({ ...prev, end: e.target.value }))}
                            />
                        </div>
                    </div>
                )}
                
                <div className="pandat69-report-field">
                    <button 
                        className="pandat69-button" 
                        onClick={handleGenerate}
                        disabled={!isCustomValid || isLoading}
                    >
                        {isLoading ? 'Generating...' : 'Generate Report'}
                    </button>
                </div>
            </div>

            <div className="pandat69-report-results">
                {isError && <div className="pandat69-error">Error: {error.message}</div>}
                
                {!isLoading && data && (
                    <>
                        <ReportSection title={`Tasks Added (${data.tasks_added.length})`} items={data.tasks_added} icon="plus-alt" metaPrefix="Added" />
                        <ReportSection title={`Tasks Completed (${data.tasks_completed.length})`} items={data.tasks_completed} icon="yes-alt" metaPrefix="Completed" />
                        <ReportSection title={`Missed Deadlines (${data.missed_deadlines.length})`} items={data.missed_deadlines} icon="calendar-alt" metaPrefix="Deadline" showOverdue={true} />
                        
                        <div className="pandat69-report-section">
                            <h4>Current Open Tasks Per Person</h4>
                            {data.tasks_per_person.length > 0 ? (
                                <ul className="pandat69-report-list">
                                    {data.tasks_per_person.map((person, idx) => (
                                        <li key={idx}><strong>{person.display_name}:</strong> {person.task_count} tasks</li>
                                    ))}
                                </ul>
                            ) : (
                                <p>No users have open tasks on this board.</p>
                            )}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
};

export default ReportView;
