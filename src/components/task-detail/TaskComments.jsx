import React, { useId, useState } from 'react';
import { useCommentMutations } from '../../hooks/useCommentMutations';
import Icon from '../Icon';
import MentionTextarea from '../MentionTextarea';
import { renderCommentText } from './taskDetailFormatters';

const TaskComments = ({ task }) => {
    const { addComment, deleteComment, updateComment } = useCommentMutations(task.id);
    const [newComment, setNewComment] = useState('');
    const [editingCommentId, setEditingCommentId] = useState(null);
    const [editCommentText, setEditCommentText] = useState('');
    const fieldPrefix = useId();

    const handleAddComment = async (event) => {
        event.preventDefault();
        if (!newComment.trim() || addComment.isPending) return;
        try {
            await addComment.mutateAsync(newComment);
            setNewComment('');
        } catch {
            alert('Failed to add comment');
        }
    };

    const handleDeleteComment = async (commentId) => {
        if (deleteComment.isPending || !window.confirm('Are you sure?')) return;
        try {
            await deleteComment.mutateAsync(commentId);
        } catch {
            alert('Failed to delete comment');
        }
    };

    const startEditComment = (comment) => {
        setEditCommentText(comment.comment_text);
        setEditingCommentId(comment.id);
    };

    const handleUpdateComment = async (commentId) => {
        if (!editCommentText.trim() || updateComment.isPending) return;
        try {
            await updateComment.mutateAsync({ commentId, commentText: editCommentText });
            setEditingCommentId(null);
            setEditCommentText('');
        } catch {
            alert('Failed to update comment');
        }
    };

    return (
        <section className="pandat69-view-modal-comments" aria-labelledby={`${fieldPrefix}-heading`}>
            <h3 id={`${fieldPrefix}-heading`}>Discussion</h3>
            <ul className="pandat69-comment-list">
                {task.comments?.length > 0 ? (
                    task.comments.map((comment) => (
                        <li key={comment.id} className="pandat69-comment-item">
                            <div className="pandat69-comment-avatar">
                                <img src={comment.user_avatar_url} alt="" width="40" height="40" loading="lazy" decoding="async" />
                            </div>
                            <div className="pandat69-comment-content">
                                <div className="pandat69-comment-meta">
                                    <span className="pandat69-comment-author">{comment.user_name}</span>
                                    <span className="pandat69-comment-date">{comment.created_at_formatted}</span>
                                </div>
                                {editingCommentId === comment.id ? (
                                    <div className="pandat69-comment-edit-form">
                                        <label className="pandat69-visually-hidden" htmlFor={`${fieldPrefix}-edit-${comment.id}`}>
                                            Edit comment by {comment.user_name}
                                        </label>
                                        <MentionTextarea
                                            id={`${fieldPrefix}-edit-${comment.id}`}
                                            ariaLabel={`Edit comment by ${comment.user_name}`}
                                            value={editCommentText}
                                            onChange={setEditCommentText}
                                        />
                                        <div className="pandat69-comment-edit-actions">
                                            <button type="button" className="pandat69-button" onClick={() => handleUpdateComment(comment.id)} disabled={updateComment.isPending}>Save</button>
                                            <button type="button" className="pandat69-button" onClick={() => setEditingCommentId(null)} disabled={updateComment.isPending}>Cancel</button>
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        <div className="pandat69-comment-text">{renderCommentText(comment.comment_text)}</div>
                                        {comment.can_manage && (
                                            <div className="pandat69-comment-actions">
                                                <button type="button" className="pandat69-icon-button" aria-label={`Edit comment by ${comment.user_name}`} onClick={() => startEditComment(comment)}><Icon name="pencil" /></button>
                                                <button type="button" className="pandat69-icon-button pandat69-button-danger" aria-label={`Delete comment by ${comment.user_name}`} onClick={() => handleDeleteComment(comment.id)} disabled={deleteComment.isPending}><Icon name="trash" /></button>
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
                <label htmlFor={`${fieldPrefix}-new`}>Add a comment</label>
                <MentionTextarea
                    id={`${fieldPrefix}-new`}
                    ariaLabel="Add a comment"
                    placeholder="Use @ to mention someone"
                    value={newComment}
                    onChange={setNewComment}
                />
                <div className="pandat69-form-actions">
                    <button type="submit" className="pandat69-button pandat69-add-comment-btn" disabled={addComment.isPending}>
                        {addComment.isPending ? 'Sending...' : 'Post Comment'}
                    </button>
                </div>
            </form>
        </section>
    );
};

export default TaskComments;
