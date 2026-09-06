import React from 'react';
import Icon from '../Icon';

const FIELD_LABELS = {
    name: 'Task Name',
    description: 'Description',
    status: 'Status',
    deadline: 'Deadline',
    priority: 'Priority',
    start_date: 'Start Date',
    category_id: 'Category',
    project_id: 'Project',
    parent_task_id: 'Parent Task',
    assigned_persons: 'Assignee',
    assignee_added: 'Assignee Added',
    assignee_removed: 'Assignee Removed',
    supervisor_added: 'Supervisor Added',
    supervisor_removed: 'Supervisor Removed',
    dependency_added: 'Dependency Added',
    dependency_removed: 'Dependency Removed',
    is_recurring: 'Recurrence',
    archived: 'Archived Status'
};

const formatValue = (key, value) => {
    if (!value) return <em>empty</em>;
    if (key === 'status') {
        return <strong style={{ textTransform: 'capitalize' }}>{String(value).replace('-', ' ')}</strong>;
    }
    return <strong>{value}</strong>;
};

const getFieldLabel = (key) => (
    FIELD_LABELS[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
);

export const renderCommentText = (text) => {
    if (!text) return '';

    let offset = 0;
    return text.split(/(@\[[^\]]+\]\([^)]+\))/g).map((part) => {
        const key = `${offset}-${part}`;
        offset += part.length;
        const match = part.match(/@\[([^\]]+)\]\(([^)]+)\)/);
        if (match) {
            return (
                <strong key={key} className="pandat69-mention-display">
                    @{match[1]}
                </strong>
            );
        }
        return <React.Fragment key={key}>{part}</React.Fragment>;
    });
};

export const renderHistoryItem = (entry) => {
    const user = <strong>{entry.user_name || 'System'}</strong>;

    const recurrenceEvents = {
        recurrence_series_created: 'started a recurring series.',
        recurrence_defaults_updated: 'updated this occurrence and the defaults for future occurrences.',
        recurrence_checklist_updated: 'updated the checklist steps for future occurrences.',
        recurrence_skipped: 'skipped this occurrence and archived it, preserving its work history.',
        recurrence_stopped: 'stopped future occurrences.',
        recurrence_successor_created: 'created the next occurrence as a separate task.',
        recurrence_occurrence_created: 'created this occurrence from its recurring series.',
    };
    if (recurrenceEvents[entry.field_changed]) return <span>{user} {recurrenceEvents[entry.field_changed]}</span>;

    if (entry.field_changed === 'checklist_updated' || entry.field_changed === 'checklist_reset') {
        const isReset = entry.field_changed === 'checklist_reset';
        const readItems = value => {
            try {
                const items = JSON.parse(value || '[]');
                return Array.isArray(items) ? items.filter(item => item && typeof item.text === 'string') : [];
            } catch {
                return [];
            }
        };
        const previous = readItems(entry.old_value);
        const next = readItems(entry.new_value);
        const renderItems = items => items.length ? (
            <ul className="pandat69-checklist-history-items">
                {items.map((item, index) => (
                    <li key={item.id || index}>
                        <span aria-label={item.checked ? 'Checked' : 'Unchecked'}>{item.checked ? '☑' : '☐'}</span>{' '}
                        {item.text}
                    </li>
                ))}
            </ul>
        ) : <p>No items.</p>;

        return (
            <div>
                <div>{user} {isReset ? 'reset the checklist for the next occurrence.' : 'updated the checklist.'}</div>
                <details>
                    <summary>{isReset ? 'Previous occurrence checklist' : 'View checklist changes'}</summary>
                    {!isReset && <strong>Before</strong>}
                    {renderItems(previous)}
                    {!isReset && <><strong>After</strong>{renderItems(next)}</>}
                </details>
                {entry.change_comment && <div className="history-comment">{entry.change_comment}</div>}
            </div>
        );
    }

    if (entry.field_changed === 'task_updated_multiple') {
        let changes = {};
        try {
            changes = JSON.parse(entry.new_value);
        } catch {
            return <span>{user} updated multiple fields.</span>;
        }

        return (
            <div className="history-complex-item">
                <div>{user} updated the task:</div>
                <ul className="history-sub-list">
                    {Object.keys(changes).map((field) => {
                        const changeData = changes[field];
                        const label = getFieldLabel(field);

                        if (changeData.values && Array.isArray(changeData.values)) {
                            return (
                                <li key={field}>
                                    {label}: <strong>{changeData.values.join(', ')}</strong>
                                </li>
                            );
                        }

                        if (field === 'description') {
                            return <li key={field}>Description updated</li>;
                        }

                        return (
                            <li key={field}>
                                {label} changed from <em>{changeData.from || 'empty'}</em> to{' '}
                                <strong>{changeData.to || 'empty'}</strong>
                            </li>
                        );
                    })}
                </ul>
                {entry.change_comment && (
                    <div className="history-comment">
                        <Icon name="quote" size={15} /> {entry.change_comment}
                    </div>
                )}
            </div>
        );
    }

    if (entry.field_changed === 'task_created') {
        return <span>{user} created this task.</span>;
    }

    const label = getFieldLabel(entry.field_changed);
    const action = entry.field_changed === 'description'
        ? 'updated the description'
        : (
            <span>
                changed {label} from {formatValue(entry.field_changed, entry.old_value)} to{' '}
                {formatValue(entry.field_changed, entry.new_value)}
            </span>
        );

    return (
        <div>
            {user} {action}
            {entry.change_comment && <div className="history-comment">{entry.change_comment}</div>}
        </div>
    );
};
