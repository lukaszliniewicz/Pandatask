import React, { useEffect, useMemo, useState } from 'react';
import { useUserBoards } from '../hooks/useUserBoards';
import { useTaskLifecycleMutations } from '../hooks/useTaskLifecycle';
import Modal from './Modal';

const TaskMoveDialog = ({ task, isOpen, onClose, onMoved }) => {
    const { data: boards = [] } = useUserBoards();
    const { previewMove, moveTask } = useTaskLifecycleMutations();
    const [destination, setDestination] = useState('');
    const [mode, setMode] = useState('strict');
    const [plan, setPlan] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!isOpen) return;
        setDestination('');
        setMode('strict');
        setPlan(null);
        setError('');
    }, [isOpen, task?.id]);

    const destinations = useMemo(
        () => boards.filter(board => board.id !== task?.board_name),
        [boards, task?.board_name]
    );

    if (!task) return null;

    const data = { destination_board: destination, mode };
    const preview = async () => {
        setError('');
        setPlan(null);
        if (!destination) {
            setError('Choose a destination board.');
            return;
        }
        try {
            const response = await previewMove.mutateAsync({ taskId: task.id, data });
            setPlan(response.plan);
        } catch (err) {
            setError(err?.message || 'Could not preview the move.');
        }
    };

    const move = async () => {
        setError('');
        try {
            const response = await moveTask.mutateAsync({ taskId: task.id, data });
            onMoved?.(response.task, response.plan);
            onClose();
        } catch (err) {
            setError(err?.message || 'Could not move the task.');
        }
    };

    const incompatibilities = Object.entries(plan?.incompatibilities || {});

    return (
        <Modal isOpen={isOpen} onClose={onClose} title={`Move: ${task.name}`}>
            <div className="pandat69-form pandat69-move-task-dialog">
                <label className="pandat69-form-field">
                    Destination board
                    <select
                        className="pandat69-select"
                        value={destination}
                        onChange={event => {
                            setDestination(event.target.value);
                            setPlan(null);
                        }}
                    >
                        <option value="">Choose a board…</option>
                        {destinations.map(board => (
                            <option key={board.id} value={board.id}>{board.name}</option>
                        ))}
                    </select>
                </label>
                <label className="pandat69-checkbox-label">
                    <input
                        type="checkbox"
                        checked={mode === 'reset_incompatible'}
                        onChange={event => {
                            setMode(event.target.checked ? 'reset_incompatible' : 'strict');
                            setPlan(null);
                        }}
                    />{' '}
                    Safely clear/filter fields that do not exist on the destination
                </label>
                <p className="pandat69-field-hint">
                    Task identity, history, comments, attachments, work records and follow-up lineage are preserved.
                </p>

                {plan && (
                    <div className={`pandat69-move-preview ${plan.can_move ? 'is-valid' : 'has-conflicts'}`}>
                        <strong>{plan.can_move ? 'Ready to move' : 'Move needs attention'}</strong>
                        {incompatibilities.length > 0 && (
                            <ul>
                                {incompatibilities.map(([field, detail]) => (
                                    <li key={field}>
                                        <code>{field}</code>: {detail.reason || 'incompatible'}
                                        {detail.action ? ` · ${detail.action.replaceAll('_', ' ')}` : ''}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
                {error && <div className="pandat69-error" role="alert">{error}</div>}
                <div className="pandat69-form-actions">
                    <button type="button" className="pandat69-button" onClick={onClose}>Cancel</button>
                    <button
                        type="button"
                        className="pandat69-button"
                        onClick={preview}
                        disabled={previewMove.isPending || !destination}
                    >
                        {previewMove.isPending ? 'Checking…' : 'Preview move'}
                    </button>
                    <button
                        type="button"
                        className="pandat69-button pandat69-button-primary"
                        onClick={move}
                        disabled={!plan?.can_move || moveTask.isPending}
                    >
                        {moveTask.isPending ? 'Moving…' : 'Move task'}
                    </button>
                </div>
            </div>
        </Modal>
    );
};

export default TaskMoveDialog;
