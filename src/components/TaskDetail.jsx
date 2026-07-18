import React, { useState, useMemo } from 'react';
import { useTaskDetails } from '../hooks/useTaskDetails';
import { useTasks } from '../hooks/useTasks';
import { useTaskHistory } from '../hooks/useTaskHistory';
import { useCommentMutations } from '../hooks/useCommentMutations';
import MentionTextarea from './MentionTextarea';
import StatusBadge from './StatusBadge';
import { formatDisplayDate, parseDate, parseUtcDateTime } from '../utils';

const TaskDetail = ({ taskId, onEdit, onAddSubtask, onNavigate }) => {
    const { data: task, isLoading, isError } = useTaskDetails(taskId);

    const formatValue = (key, value) => {
        if (!value) return <em>empty</em>;
        if (key === 'status') return <span style={{textTransform:'capitalize', fontWeight:'bold'}}>{value.replace('-', ' ')}</span>;
        if (key === 'deadline' || key === 'start_date') return <strong>{value}</strong>;
        return <strong>{value}</strong>;
    };
    
    const { data: allTasks } = useTasks({ 
        status: '',
        archived: false 
    });

    const subtasks = useMemo(() => {
        if (!allTasks || !taskId) return [];
        return allTasks.filter(t => parseInt(t.parent_task_id) === parseInt(taskId));
    }, [allTasks, taskId]);

    const { data: history, isLoading: isLoadingHistory } = useTaskHistory(taskId);
    const { addComment, deleteComment, updateComment } = useCommentMutations(taskId);
    
    const [newComment, setNewComment] = useState('');
    const [editingCommentId, setEditingCommentId] = useState(null);
    const [editCommentText, setEditCommentText] = useState('');
    const [showHistory, setShowHistory] = useState(false);

    if (isLoading) return <div className="pandat69-loading">Loading details...</div>;
    if (isError || !task) return <div className="pandat69-error">Failed to load task details.</div>;

    const handleAddComment = async (e) => {
        e.preventDefault();
        if (!newComment.trim()) return;
        try {
            await addComment.mutateAsync(newComment);
            setNewComment('');
        } catch (error) {
            alert('Failed to add comment');
        }
    };

    const handleDeleteComment = async (commentId) => { if (!confirm('Are you sure?')) return; try { await deleteComment.mutateAsync(commentId); } catch (error) { alert('Failed to delete comment'); } };
    const startEditComment = (comment) => { setEditCommentText(comment.comment_text); setEditingCommentId(comment.id); };
    const handleUpdateComment = async (commentId) => { if (!editCommentText.trim()) return; try { await updateComment.mutateAsync({ commentId, commentText: editCommentText }); setEditingCommentId(null); setEditCommentText(''); } catch (error) { alert('Failed to update comment'); } };

    const renderDescription = () => {
        if (!task.description_rendered) return { __html: '<em>No description provided.</em>' };
        return { __html: task.description_rendered };
    };

    const handleNavigate = (id) => {
        if (onNavigate) {
            onNavigate(id);
        } else if (onEdit) {
            onEdit({ id });
        }
    };

    const renderCommentText = (text) => {
        if (!text) return '';
        const parts = text.split(/(@\[[^\]]+\]\([^)]+\))/g);
        return parts.map((part, index) => {
            const match = part.match(/@\[([^\]]+)\]\(([^)]+)\)/);
            if (match) {
                return <span key={index} className="pandat69-mention-display" style={{fontWeight: 'bold', color: '#384D68'}}>@{match[1]}</span>;
            }
            return part;
        });
    };

    // --- History Logic ---
    const getFieldLabel = (key) => {
        const labels = {
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
        return labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    };

    const renderHistoryItem = (entry) => {
        const user = <strong>{entry.user_name || 'System'}</strong>;
        
        // 1. Handle Aggregated Updates (JSON in new_value)
        if (entry.field_changed === 'task_updated_multiple') {
            let changes = {};
            try {
                changes = JSON.parse(entry.new_value);
            } catch (e) {
                return <span>{user} updated multiple fields.</span>;
            }

            return (
                <div className="history-complex-item">
                    <div>{user} updated the task:</div>
                    <ul className="history-sub-list">
                        {Object.keys(changes).map((field) => {
                            const changeData = changes[field];
                            const label = getFieldLabel(field);
                            
                            // Handle array changes (users added/removed)
                            if (changeData.values && Array.isArray(changeData.values)) {
                                return (
                                    <li key={field}>
                                        {label}: <strong>{changeData.values.join(', ')}</strong>
                                    </li>
                                );
                            }

                            // Handle simple field changes
                            const from = changeData.from || 'empty';
                            const to = changeData.to || 'empty';

                            if (field === 'description') {
                                return <li key={field}>Description updated</li>;
                            }

                            return (
                                <li key={field}>
                                    {label} changed from <em>{from}</em> to <strong>{to}</strong>
                                </li>
                            );
                        })}
                    </ul>
                    {entry.change_comment && (
                        <div className="history-comment">
                            <span className="dashicons dashicons-format-quote"></span> {entry.change_comment}
                        </div>
                    )}
                </div>
            );
        }

        // 2. Handle Creation
        if (entry.field_changed === 'task_created') {
            return <span>{user} created this task.</span>;
        }

        // 3. Handle Legacy/Single Updates
        let actionText = '';
        const label = getFieldLabel(entry.field_changed);
        
        if (entry.field_changed === 'description') {
            actionText = `updated the description`;
        } else {
            const oldVal = entry.old_value;
            const newVal = entry.new_value;
            
            actionText = (
                <span>
                    changed {label} from {formatValue(entry.field_changed, oldVal)} to {formatValue(entry.field_changed, newVal)}
                </span>
            );
        }

        return (
            <div>
                {user} {actionText}
                {entry.change_comment && <div className="history-comment">"{entry.change_comment}"</div>}
            </div>
        );
    };

    return (
        <div className="pandat69-task-detail-view">
            
            {/* Header: Breadcrumbs */}
            <div className="pandat69-detail-header-row">
                <div className="pandat69-breadcrumbs">
                    {task.parent_task_id && task.parent_task_name ? (
                        <>
                            <span className="dashicons dashicons-arrow-up-alt2"></span>
                            <span className="clickable" onClick={() => handleNavigate(task.parent_task_id)} style={{cursor:'pointer', textDecoration:'underline'}}>
                                Parent: {task.parent_task_name}
                            </span>
                            <span className="sep">/</span>
                        </>
                    ) : null}
                    
                    {task.project_name ? (
                        <>
                            <span className="dashicons dashicons-portfolio"></span> 
                            <strong>{task.project_name}</strong> 
                        </>
                    ) : (
                        <span className="no-project">No Project</span>
                    )}
                </div>
                <div className="pandat69-detail-id">#{task.id}</div>
            </div>

            {/* Title & Status */}
            <div className="pandat69-detail-title-row">
                <div className="pandat69-title-wrapper" style={{flexDirection: 'column', alignItems: 'flex-start', gap: '5px'}}>
                    <div style={{display:'flex', alignItems:'center', gap:'10px', width: '100%'}}>
                        <h2 style={{margin: 0}}>{task.name}</h2>
                        <button className="pandat69-icon-button-clean" onClick={() => onEdit(task)} title="Edit Details">
                            <span className="dashicons dashicons-edit"></span>
                        </button>
                    </div>
                    <div className="pandat69-status-wrapper" style={{marginTop: '5px'}}>
                        <StatusBadge task={task} mode="pill" />
                    </div>
                </div>
            </div>

            {/* Meta Grid */}
            <div className="pandat69-modern-meta-grid">
                <div className="pandat69-meta-box">
                    <label>Assigned To</label>
                    <div className="val avatars">
                        {task.assigned_users && task.assigned_users.length > 0 ? (
                            task.assigned_users.map(u => (
                                <img key={u.id} src={u.avatar} title={u.name} className="avatar-circle" alt={u.name}/>
                            ))
                        ) : 'Unassigned'}
                    </div>
                </div>
                <div className="pandat69-meta-box">
                    <label>Priority</label>
                    <div className="val">
                        <span className="dashicons dashicons-star-filled" style={{color: task.priority>7?'#e9b44c':'#ccc'}}></span>
                        {task.priority}
                    </div>
                </div>
                <div className="pandat69-meta-box">
                    <label>Deadline</label>
                    <div className={`val ${task.deadline && parseDate(task.deadline) < new Date() && task.status !== 'done' ? 'overdue' : ''}`}>
                        <span className="dashicons dashicons-calendar-alt"></span>
                        {task.deadline || 'None'}
                    </div>
                </div>
                {task.category_name && (
                    <div className="pandat69-meta-box">
                        <label>Category</label>
                        <div className="val">{task.category_name}</div>
                    </div>
                )}
            </div>

            {/* Subtasks Section */}
            <div className="pandat69-detail-subtasks">
                <div className="pandat69-section-header">
                    <h4><span className="dashicons dashicons-list-view"></span> Subtasks ({subtasks.length})</h4>
                    {onAddSubtask && (
                        <button className="pandat69-button" onClick={() => onAddSubtask(task.id)}>
                            + Add Subtask
                        </button>
                    )}
                </div>
                {subtasks.length > 0 ? (
                    <ul className="pandat69-detail-subtask-list">
                        {subtasks.map(sub => (
                            <li key={sub.id} onClick={() => handleNavigate(sub.id)}>
                                <span className={`subtask-name ${sub.status === 'done' ? 'done' : ''}`}>
                                    {sub.name}
                                </span>
                                <div className="subtask-meta">
                                    <StatusBadge task={sub} mode="dot" />
                                    {sub.assigned_users?.[0] && (
                                        <img src={sub.assigned_users[0].avatar} style={{width: 16, height: 16, borderRadius: '50%'}} alt=""/>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div style={{fontStyle:'italic', color:'#999', fontSize:'13px'}}>No subtasks.</div>
                )}
            </div>

            {/* Description */}
            <div className="pandat69-detail-description-box">
                <h4>Description</h4>
                <div className="pandat69-description-content" dangerouslySetInnerHTML={renderDescription()} />
                {task.attachment_url && (
                    <div style={{marginTop: '15px', padding: '10px', background: '#f0f0f0', borderRadius: '4px'}}>
                        <strong>Attachment: </strong> 
                        <a href={task.attachment_url} target="_blank" rel="noopener noreferrer">
                            {task.attachment_filename || 'View File'}
                        </a>
                    </div>
                )}
            </div>

            {/* Comments Section */}
            <div className="pandat69-view-modal-comments">
                <h4>Discussion</h4>
                <ul className="pandat69-comment-list">
                    {task.comments?.length > 0 ? (
                        task.comments.map(comment => (
                            <li key={comment.id} className="pandat69-comment-item">
                                <div className="pandat69-comment-avatar">
                                    <img src={comment.user_avatar_url} alt={comment.user_name} />
                                </div>
                                <div className="pandat69-comment-content">
                                    <div className="pandat69-comment-meta">
                                        <span className="pandat69-comment-author">{comment.user_name}</span>
                                        <span className="pandat69-comment-date">{comment.created_at_formatted}</span>
                                    </div>
                                    {editingCommentId === comment.id ? (
                                        <div className="pandat69-comment-edit-form">
                                            <MentionTextarea 
                                                value={editCommentText} 
                                                onChange={setEditCommentText} 
                                            />
                                            <div style={{marginTop: '10px'}}>
                                                <button className="pandat69-button" onClick={() => handleUpdateComment(comment.id)}>Save</button>
                                                <button className="pandat69-button" onClick={() => setEditingCommentId(null)} style={{marginLeft: '5px'}}>Cancel</button>
                                            </div>
                                        </div>
                                    ) : (
                                        <>
                                            <div className="pandat69-comment-text">{renderCommentText(comment.comment_text)}</div>
                                            {comment.can_manage && (
                                                <div className="pandat69-comment-actions">
                                                    <button type="button" className="pandat69-icon-button" onClick={() => startEditComment(comment)}><span className="dashicons dashicons-edit"></span></button>
                                                    <button type="button" className="pandat69-icon-button pandat69-button-danger" onClick={() => handleDeleteComment(comment.id)}><span className="dashicons dashicons-trash"></span></button>
                                                </div>
                                            )}
                                        </>
                                    )}
                                </div>
                            </li>
                        ))
                    ) : (
                        <li className="pandat69-no-comments">No comments yet.</li>
                    )}
                </ul>

                <form className="pandat69-add-comment-form" onSubmit={handleAddComment}>
                    <MentionTextarea 
                        className="pandat69-comment-textarea"
                        placeholder="Add a comment... (use @ to mention)"
                        value={newComment}
                        onChange={setNewComment}
                    />
                    <div className="pandat69-form-actions">
                        <button type="submit" className="pandat69-button pandat69-add-comment-btn" disabled={addComment.isPending}>
                            {addComment.isPending ? 'Sending...' : 'Post Comment'}
                        </button>
                    </div>
                </form>
            </div>

            {/* Collapsible History */}
            <div className="pandat69-history-section">
                <button 
                    type="button" 
                    className={`pandat69-history-toggle ${showHistory ? 'open' : ''}`}
                    onClick={() => setShowHistory(!showHistory)}
                >
                    <span><span className="dashicons dashicons-backup" style={{verticalAlign:'text-bottom'}}></span> Audit Log & History</span>
                    <span className="dashicons dashicons-arrow-down-alt2"></span>
                </button>
                
                {showHistory && (
                    <div className="pandat69-history-content">
                        <ul className="pandat69-history-list">
                            {isLoadingHistory ? (
                                <li>Loading history...</li>
                            ) : history && history.length > 0 ? (
                                history.map(entry => (
                                    <li key={entry.id}>
                                        <div className="history-change">{renderHistoryItem(entry)}</div>
                                        <div className="history-meta" style={{fontSize: '0.85em', color: '#999', marginTop: '4px'}}>
                                            {formatDisplayDate(parseUtcDateTime(entry.changed_at))} at {parseUtcDateTime(entry.changed_at).toLocaleTimeString()}
                                        </div>
                                    </li>
                                ))
                            ) : (
                                <li>No history recorded.</li>
                            )}
                        </ul>
                    </div>
                )}
            </div>
        </div>
    );
};

export default TaskDetail;
