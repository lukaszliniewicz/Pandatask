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
