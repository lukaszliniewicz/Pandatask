import React, { useId, useState } from 'react';
import { useReports } from '../hooks/useReports';
import { useBoardWorkReport } from '../hooks/useWorkLog';
import { parseUtcDateTime } from '../utils';
import Icon from './Icon';

const formatReportDate = (value) => {
    if (!value) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    return parseUtcDateTime(value).toLocaleString();
};

const formatDuration = (seconds) => {
    const totalMinutes = Math.round(Number(seconds || 0) / 60);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return hours ? `${hours}h${minutes ? ` ${minutes}m` : ''}` : `${minutes}m`;
};

const CollapsibleReportSection = ({ title, children, defaultExpanded = false }) => {
    const [expanded, setExpanded] = useState(defaultExpanded);
    const contentId = useId();

    return (
        <section className={`pandat69-report-section ${expanded ? 'is-expanded' : 'is-collapsed'}`}>
            <h4>
                <button
                    type="button"
                    className="pandat69-report-section-toggle"
                    onClick={() => setExpanded((open) => !open)}
                    aria-expanded={expanded}
                    aria-controls={contentId}
                >
                    <span>{title}</span>
                    <Icon name={expanded ? 'chevron-down' : 'chevron-right'} />
                </button>
            </h4>
            {expanded && (
                <div id={contentId} className="pandat69-report-section-content">
                    {children}
                </div>
            )}
        </section>
    );
};

const ReportSection = ({ title, items, icon, metaPrefix, showOverdue, defaultExpanded }) => (
    <CollapsibleReportSection title={title} defaultExpanded={defaultExpanded}>
        {items.length > 0 ? (
            <ul className="pandat69-report-list">
                {items.map(task => (
                    <li key={task.occurrence_id ? `occurrence-${task.occurrence_id}` : task.id}>
                        <span className="pandat69-report-item-title">{task.name}</span>
                        <div className="pandat69-report-item-meta">
                            <Icon name={icon} size={15} /> {metaPrefix}: {formatReportDate(task.created_at || task.completed_at || task.deadline)}
                            {showOverdue && ` (${task.days_overdue} days overdue)`}
                        </div>
                        {task.assigned_user_names && (
                            <div className="pandat69-report-assigned">
                                <Icon name="users" size={15} /> Assigned to: {task.assigned_user_names}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        ) : (
            <p className="pandat69-report-empty">No items found.</p>
        )}
    </CollapsibleReportSection>
);

const ReportView = () => {
    const [period, setPeriod] = useState('this_week');
    const [customRange, setCustomRange] = useState({ start: '', end: '' });
    const fieldPrefix = useId();
    
    const filters = { period };
    if (period === 'custom') {
        filters.start_date = customRange.start;
        filters.end_date = customRange.end;
    }

    const isCustomValid = period !== 'custom' || (customRange.start && customRange.end);
    
    const { data, isLoading, isError, error, refetch } = useReports(filters);
    const { data: workReport } = useBoardWorkReport(filters);

    const handleGenerate = () => {
        if (isCustomValid) refetch();
    };

    return (
        <div className="pandat69-tab-report">
            <div className="pandat69-report-controls">
                <div className="pandat69-report-field">
                    <label htmlFor={`${fieldPrefix}-period`}>Select Period:</label>
                    <select 
                        id={`${fieldPrefix}-period`}
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
                    <div className="pandat69-report-custom-dates">
                        <div className="pandat69-report-field">
                            <label htmlFor={`${fieldPrefix}-start`}>From:</label>
                            <input 
                                id={`${fieldPrefix}-start`}
                                type="date" 
                                className="pandat69-input" 
                                value={customRange.start}
                                onChange={(e) => setCustomRange(prev => ({ ...prev, start: e.target.value }))}
                            />
                        </div>
                        <div className="pandat69-report-field">
                            <label htmlFor={`${fieldPrefix}-end`}>To:</label>
                            <input 
                                id={`${fieldPrefix}-end`}
                                type="date" 
                                className="pandat69-input" 
                                value={customRange.end}
                                onChange={(e) => setCustomRange(prev => ({ ...prev, end: e.target.value }))}
                            />
                        </div>
                    </div>
                )}
                
                <div className="pandat69-report-actions">
                    <button 
                        type="button"
                        className="pandat69-button pandat69-generate-report-btn"
                        onClick={handleGenerate}
                        disabled={!isCustomValid || isLoading}
                    >
                        <Icon name="bar-chart" />
                        {isLoading ? 'Generating...' : 'Generate Report'}
                    </button>
                </div>
            </div>

            <div className="pandat69-report-results">
                {isError && <div className="pandat69-error" role="alert">Error: {error.message}</div>}
                
                {!isLoading && data && (
                    <>
                        <ReportSection title={`Tasks Added (${data.tasks_added.length})`} items={data.tasks_added} icon="circle-plus" metaPrefix="Added" defaultExpanded />
                        <ReportSection title={`Tasks Completed (${data.tasks_completed.length})`} items={data.tasks_completed} icon="circle-check" metaPrefix="Completed" />
                        <ReportSection title={`Missed Deadlines (${data.missed_deadlines.length})`} items={data.missed_deadlines} icon="calendar" metaPrefix="Deadline" showOverdue={true} />

                        {workReport && (
                            <CollapsibleReportSection title={`Work recorded (${formatDuration(workReport.total_seconds)})`}>
                                <p className="pandat69-report-item-meta">
                                    Allocated work in this report period. Completed assignee-occurrences still awaiting a time resolution: <strong>{workReport.unresolved_occurrences || 0}</strong>.
                                </p>
                                {workReport.breakdown?.length > 0 ? (
                                    <ul className="pandat69-report-list">
                                        {workReport.breakdown.map((row, index) => (
                                            <li key={`${row.activity_type || row.kind || 'other'}-${row.capacity || 'none'}-${index}`}>
                                                <strong>{row.activity_type || (row.kind === 'residual' ? 'Unitemised' : 'Other')}:</strong> {formatDuration(row.duration_seconds)}
                                                {row.capacity ? ` · ${row.capacity}` : ''}
                                            </li>
                                        ))}
                                    </ul>
                                ) : <p className="pandat69-report-empty">No work allocated to this board in the selected period.</p>}
                            </CollapsibleReportSection>
                        )}
                        
                        <CollapsibleReportSection title="Current Open Tasks Per Person">
                            {data.tasks_per_person.length > 0 ? (
                                <ul className="pandat69-report-list">
                                    {data.tasks_per_person.map((person) => (
                                        <li key={person.id || person.user_id || person.display_name}><strong>{person.display_name}:</strong> {person.task_count} tasks</li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="pandat69-report-empty">No users have open tasks on this board.</p>
                            )}
                        </CollapsibleReportSection>
                    </>
                )}
            </div>
        </div>
    );
};

export default ReportView;
