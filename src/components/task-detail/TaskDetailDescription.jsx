import React from 'react';
import RichTaskDescription from '../rich-text/RichTaskDescription';

const TaskDetailDescription = ({ task }) => (
    <section className="pandat69-detail-description-box" aria-labelledby={`pandatask-description-${task.id}`}>
        <h3 id={`pandatask-description-${task.id}`}>Description</h3>
        <RichTaskDescription
            className="pandat69-description-content"
            html={task.description_rendered || task.description}
            emptyHtml="<em>No description provided.</em>"
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
