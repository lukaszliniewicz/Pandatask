import React from 'react';
import Icon from '../Icon';
import { parseDate } from '../../utils';

const MetadataItem = ({ label, children, valueClassName = '' }) => (
    <div className="pandat69-meta-box">
        <dt>{label}</dt>
        <dd className={`val ${valueClassName}`}>{children}</dd>
    </div>
);

const TaskDetailMetadata = ({ task }) => (
    <dl className="pandat69-modern-meta-grid">
        <MetadataItem label="Assigned To" valueClassName="avatars">
            {task.assigned_users?.length > 0
                ? task.assigned_users.map((user) => (
                    <img
                        key={user.id}
                        src={user.avatar}
                        title={user.name}
                        className="avatar-circle"
                        alt={user.name}
                        width="24"
                        height="24"
                        loading="lazy"
                        decoding="async"
                    />
                ))
                : 'Unassigned'}
        </MetadataItem>
        <MetadataItem label="Priority">
            <Icon name="star" style={{ color: task.priority > 7 ? '#e9b44c' : '#767676' }} />
            {task.priority}
        </MetadataItem>
        <MetadataItem
            label="Deadline"
            valueClassName={task.deadline && parseDate(task.deadline) < new Date() && task.status !== 'done' ? 'overdue' : ''}
        >
            <Icon name="calendar" />
            {task.deadline || 'None'}
        </MetadataItem>
        {task.category_name && (
            <MetadataItem label="Category">{task.category_name}</MetadataItem>
        )}
    </dl>
);

export default TaskDetailMetadata;
