import React, { useEffect, useState } from 'react';
import { useTaskLifecycleMutations } from '../hooks/useTaskLifecycle';
import Modal from './Modal';

const TaskFollowUpDialog = ({ task, isOpen, onClose, onCreated }) => {
    const { createFollowUp } = useTaskLifecycleMutations();
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [trigger, setTrigger] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        if (!isOpen || !task) return;
        setName(`Follow up: ${task.name}`);
        setDescription('');
        setTrigger('');
        setError('');
    }, [isOpen, task?.id]);

    if (!task) return null;

    const submit = async event => {
        event.preventDefault();
        setError('');
        try {
            const created = await createFollowUp.mutateAsync({
                taskId: task.id,
                data: {
                    name: name.trim(),
                    description,
                    trigger,
                },
            });
            onCreated?.(created);
            onClose();
        } catch (err) {
            setError(err?.message || 'Could not create the follow-up task.');
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} title="Create follow-up task">
            <form className="pandat69-form" onSubmit={submit}>
                <p className="pandat69-field-hint">
                    The source task remains completed. This new task records a meaningful later deliverable with explicit causal lineage.
                </p>
                <label className="pandat69-form-field">
                    Task name
                    <input className="pandat69-input" value={name} onChange={event => setName(event.target.value)} required />
                </label>
                <label className="pandat69-form-field">
                    Description
                    <textarea className="pandat69-textarea" rows="4" value={description} onChange={event => setDescription(event.target.value)} />
                </label>
                <label className="pandat69-form-field">
                    Trigger / provenance
                    <textarea
                        className="pandat69-textarea"
                        rows="3"
                        value={trigger}
                        onChange={event => setTrigger(event.target.value)}
                        placeholder="Requester, date, email/message URL, or short explanation"
                    />
                </label>
                {error && <div className="pandat69-error" role="alert">{error}</div>}
                <div className="pandat69-form-actions">
                    <button type="button" className="pandat69-button" onClick={onClose}>Cancel</button>
                    <button type="submit" className="pandat69-button pandat69-button-primary" disabled={createFollowUp.isPending}>
                        {createFollowUp.isPending ? 'Creating…' : 'Create follow-up'}
                    </button>
                </div>
            </form>
        </Modal>
    );
};

export default TaskFollowUpDialog;
