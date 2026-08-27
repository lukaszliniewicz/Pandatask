import React, { useEffect, useState } from 'react';
import Modal from './Modal';
import { useTaskMutations } from '../hooks/useTaskMutations';

const ReopenTaskDialog = ({ task, targetStatus = 'in-progress', onClose }) => {
  const { reopenTask } = useTaskMutations();
  const [reason, setReason] = useState('');
  const [status, setStatus] = useState(targetStatus);
  const [error, setError] = useState('');

  useEffect(() => {
    setStatus(targetStatus);
    setReason('');
    setError('');
  }, [task?.id, targetStatus]);

  if (!task) return null;

  const submit = async event => {
    event.preventDefault();
    setError('');
    if (!reason.trim()) {
      setError('Explain why this completed task needs to be reopened.');
      return;
    }
    try {
      await reopenTask.mutateAsync({id: task.id, status, reason: reason.trim()});
      onClose();
    } catch (err) {
      setError(err?.message || 'Failed to reopen task.');
    }
  };

  return (
    <Modal isOpen onClose={onClose} title={`Reopen: ${task.name}`}>
      <form className="pandat69-form" onSubmit={submit}>
        <p className="pandat69-field-hint">
          Reopen only when the original task was not truly complete or needs corrective rework.
          Later related requests should be recorded as follow-up work or a follow-up task instead.
        </p>
        <label className="pandat69-form-field">
          Reopen as
          <select className="pandat69-select" value={status} onChange={event => setStatus(event.target.value)}>
            <option value="in-progress">In progress</option>
            <option value="pending">Pending</option>
          </select>
        </label>
        <label className="pandat69-form-field">
          Reason
          <textarea
            className="pandat69-textarea"
            rows="4"
            value={reason}
            onChange={event => setReason(event.target.value)}
            required
            placeholder="What was incomplete or what corrective rework is needed?"
          />
        </label>
        {error && <div className="pandat69-error" role="alert">{error}</div>}
        <div className="pandat69-form-actions">
          <button type="button" className="pandat69-button" onClick={onClose}>Cancel</button>
          <button type="submit" className="pandat69-button pandat69-button-primary" disabled={reopenTask.isPending}>
            {reopenTask.isPending ? 'Reopening…' : 'Reopen task'}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default ReopenTaskDialog;
