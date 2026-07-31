import React, { useMemo } from 'react';
import { useTasks } from '../../hooks/useTasks';
import Icon from '../Icon';
import StatusBadge from '../StatusBadge';

const TaskDetailSubtasks = ({ task, onAddSubtask, onNavigate }) => {
    const { data: allTasks } = useTasks({ status: '', archived: false });
    const subtasks = useMemo(() => {
        if (!allTasks || !task.id) return [];
        return allTasks.filter(
            (candidate) => Number(candidate.parent_task_id) === Number(task.id)
        );
    }, [allTasks, task.id]);

    return (
        <section className="pandat69-detail-subtasks" aria-labelledby={`pandatask-subtasks-${task.id}`}>
            <div className="pandat69-section-header">
                <h3 id={`pandatask-subtasks-${task.id}`}><Icon name="list-tree" /> Subtasks ({subtasks.length})</h3>
                {onAddSubtask && (
                    <button type="button" className="pandat69-button" onClick={() => onAddSubtask(task.id, task.project_id, task.board_name)}>
                        <Icon name="list-plus" /> Add Subtask
                    </button>
                )}
            </div>
            {subtasks.length > 0 ? (
                <ul className="pandat69-detail-subtask-list">
                    {subtasks.map((subtask) => (
                        <li key={subtask.id}>
                            <button
                                type="button"
                                className={`subtask-name ${subtask.status === 'done' ? 'done' : ''}`}
                                onClick={() => onNavigate(subtask.id)}
                            >
                                {subtask.name}
                            </button>
                            <div className="subtask-meta">
                                <StatusBadge task={subtask} mode="dot" />
                                {subtask.assigned_users?.[0] && (
                                    <img
                                        src={subtask.assigned_users[0].avatar}
                                        width="16"
                                        height="16"
                                        className="pandat69-subtask-avatar"
                                        alt={`Assigned to ${subtask.assigned_users[0].name}`}
                                        loading="lazy"
                                        decoding="async"
                                    />
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="pandat69-empty-note">No subtasks.</p>
            )}
        </section>
    );
};

export default TaskDetailSubtasks;
