import React from 'react';

const TaskDetailDescription = ({ task }) => (
    <section className="pandat69-detail-description-box" aria-labelledby={`pandatask-description-${task.id}`}>
        <h3 id={`pandatask-description-${task.id}`}>Description</h3>
        <div
            className="pandat69-description-content"
            dangerouslySetInnerHTML={{
                __html: task.description_rendered || '<em>No description provided.</em>'
            }}
        />
        {task.attachment_url && (
            <div className="pandat69-detail-attachment">
                <strong>Attachment: </strong>
                <a href={task.attachment_url} target="_blank" rel="noopener noreferrer">
                    {task.attachment_filename || 'View File'}
                </a>
                {task.attachment_public_source_retained && (
                    <p className="description pandat69-attachment-privacy-note">
                        This download is protected, but its Media Library source may still be publicly reachable.
                    </p>
                )}
            </div>
        )}
    </section>
);

export default TaskDetailDescription;
