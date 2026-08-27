import React, { useState } from 'react';
import { useTaskFollowUps } from '../../hooks/useTaskLifecycle';
import { useTaskStatusTransition } from '../../context/CompletionContext';

const TaskFollowUpDialog = React.lazy(() => import('../TaskFollowUpDialog'));

const CompletedTaskLifecycle = ({ task, onNavigate }) => {
  const { data: followUps = [] } = useTaskFollowUps(task.id);
  const { setStatus, isPending: isStatusPending } = useTaskStatusTransition();
  const [followUpOpen, setFollowUpOpen] = useState(false);

  return (
    <div className="pandat69-completed-task-lifecycle">
      <div className="pandat69-task-time-actions">
        <button type="button" className="pandat69-button" onClick={() => setFollowUpOpen(true)}>
          Create follow-up
        </button>
        <button
          type="button"
          className="pandat69-button"
          disabled={isStatusPending}
          onClick={() => setStatus(task, 'in-progress')}
        >
          Reopen…
        </button>
      </div>

      {followUps.length > 0 && (
        <details className="pandat69-task-work-details" open>
          <summary>{followUps.length} follow-up task{followUps.length === 1 ? '' : 's'}</summary>
          <ul>
            {followUps.map(followUp => (
              <li key={followUp.id}>
                <button type="button" className="pandat69-link-button" onClick={() => onNavigate?.(followUp.id)}>
                  #{followUp.id} · {followUp.name}
                </button>{' '}
                <small>{followUp.status}</small>
              </li>
            ))}
          </ul>
        </details>
      )}

      <React.Suspense fallback={null}>
        <TaskFollowUpDialog
          task={task}
          isOpen={followUpOpen}
          onClose={() => setFollowUpOpen(false)}
          onCreated={created => onNavigate?.(created?.id)}
        />
      </React.Suspense>
    </div>
  );
};

export default CompletedTaskLifecycle;
