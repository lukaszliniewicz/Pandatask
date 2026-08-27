import '../../assets/scss/components/_inbox.scss';
import React, { useEffect, useMemo, useState } from 'react';
import {
    useInbox,
    useInboxDelegates,
    useInboxMutations,
    useSharedInboxes,
} from '../hooks/useInbox';
import UserSelect from './UserSelect';
import TaskMoveDialog from './TaskMoveDialog';

const InboxView = ({ onOpenTask }) => {
    const { data: sharedData } = useSharedInboxes();
    const shared = sharedData?.inboxes || [];
    const readableShared = shared.filter(item => item.can_read);
    const submitTargets = shared.filter(item => item.can_submit);
    const [ownerUserId, setOwnerUserId] = useState(null);
    const [captureOwnerUserId, setCaptureOwnerUserId] = useState(null);
    const [search, setSearch] = useState('');
    const { data: inboxData, isLoading, isError } = useInbox(ownerUserId, {
        search,
        status: 'all',
        limit: 200,
    });
    const { capture, setState, replaceDelegates } = useInboxMutations();
    const { data: delegateData } = useInboxDelegates();
    const [title, setTitle] = useState('');
    const [note, setNote] = useState('');
    const [sourceUrl, setSourceUrl] = useState('');
    const [error, setError] = useState('');
    const [movingTask, setMovingTask] = useState(null);
    const [delegateIds, setDelegateIds] = useState([]);
    const [delegateRoles, setDelegateRoles] = useState({});

    const isOwnInbox = ownerUserId === null;
    const tasks = inboxData?.tasks || [];

    useEffect(() => {
        const delegates = delegateData?.delegates || [];
        setDelegateIds(delegates.map(item => Number(item.user_id)));
        setDelegateRoles(
            Object.fromEntries(delegates.map(item => [Number(item.user_id), item.role]))
        );
    }, [delegateData]);

    useEffect(() => {
        setDelegateRoles(current => {
            const next = {};
            delegateIds.forEach(id => {
                next[id] = current[id] || 'contributor';
            });
            return next;
        });
    }, [delegateIds]);

    const selectedOwnerName = useMemo(() => {
        if (ownerUserId === null) return 'My Inbox';
        return readableShared.find(item => Number(item.owner_user_id) === Number(ownerUserId))?.owner_name || 'Shared Inbox';
    }, [ownerUserId, readableShared]);

    const submitCapture = async event => {
        event.preventDefault();
        setError('');
        if (!title.trim()) return;
        try {
            await capture.mutateAsync({
                ownerUserId: captureOwnerUserId || null,
                data: {
                    name: title.trim(),
                    description: note,
                    ...(sourceUrl.trim() ? { source_url: sourceUrl.trim() } : {}),
                    capture_source: 'pandatask_quick_add',
                },
            });
            setTitle('');
            setNote('');
            setSourceUrl('');
        } catch (err) {
            setError(err?.message || 'Could not capture the item.');
        }
    };

    const saveDelegates = async () => {
        setError('');
        try {
            await replaceDelegates.mutateAsync(
                delegateIds.map(userId => ({
                    user_id: Number(userId),
                    role: delegateRoles[Number(userId)] || 'contributor',
                }))
            );
        } catch (err) {
            setError(err?.message || 'Could not save Inbox delegation.');
        }
    };

    return (
        <section className="pandat69-inbox-view">
            <header className="pandat69-inbox-header">
                <div>
                    <h2>{selectedOwnerName}</h2>
                    <p className="pandat69-field-hint">
                        Capture first, classify later. Inbox items are normal tasks and keep their identity when moved to a board.
                    </p>
                </div>
                {readableShared.length > 0 && (
                    <label>
                        Inbox
                        <select
                            className="pandat69-select"
                            value={ownerUserId || ''}
                            onChange={event => setOwnerUserId(event.target.value ? Number(event.target.value) : null)}
                        >
                            <option value="">My Inbox</option>
                            {readableShared.map(item => (
                                <option key={item.owner_user_id} value={item.owner_user_id}>{item.owner_name}</option>
                            ))}
                        </select>
                    </label>
                )}
            </header>

            <form className="pandat69-inbox-capture" onSubmit={submitCapture}>
                <div className="pandat69-inbox-capture-primary">
                    <label className="pandat69-visually-hidden" htmlFor="pandat69-inbox-title">Quick capture title</label>
                    <input
                        id="pandat69-inbox-title"
                        className="pandat69-input"
                        value={title}
                        onChange={event => setTitle(event.target.value)}
                        placeholder="Capture something…"
                        required
                    />
                    {(submitTargets.length > 0) && (
                        <select
                            className="pandat69-select"
                            value={captureOwnerUserId || ''}
                            onChange={event => setCaptureOwnerUserId(event.target.value ? Number(event.target.value) : null)}
                            aria-label="Capture destination"
                        >
                            <option value="">My Inbox</option>
                            {submitTargets.map(item => (
                                <option key={item.owner_user_id} value={item.owner_user_id}>{item.owner_name}'s Inbox</option>
                            ))}
                        </select>
                    )}
                    <button className="pandat69-button pandat69-button-primary" type="submit" disabled={capture.isPending}>
                        {capture.isPending ? 'Capturing…' : 'Capture'}
                    </button>
                </div>
                <details>
                    <summary>Add note or source URL</summary>
                    <label className="pandat69-form-field">
                        Note
                        <textarea className="pandat69-textarea" rows="3" value={note} onChange={event => setNote(event.target.value)} />
                    </label>
                    <label className="pandat69-form-field">
                        Source URL
                        <input className="pandat69-input" type="url" value={sourceUrl} onChange={event => setSourceUrl(event.target.value)} />
                    </label>
                </details>
            </form>

            <div className="pandat69-inbox-toolbar">
                <label>
                    <span className="pandat69-visually-hidden">Search Inbox</span>
                    <input className="pandat69-input" value={search} onChange={event => setSearch(event.target.value)} placeholder="Search Inbox…" />
                </label>
                <span>{tasks.length} item{tasks.length === 1 ? '' : 's'}</span>
            </div>

            {error && <div className="pandat69-error" role="alert">{error}</div>}
            {isLoading && <div className="pandat69-loading" role="status">Loading Inbox…</div>}
            {isError && <div className="pandat69-error" role="alert">Could not load Inbox.</div>}
            {!isLoading && !isError && tasks.length === 0 && (
                <div className="pandat69-empty-state">Inbox zero. A rare and beautiful condition.</div>
            )}
            <div className="pandat69-inbox-list">
                {tasks.map(task => (
                    <article className={`pandat69-inbox-item ${task.inbox_state === 'reviewed' ? 'is-reviewed' : ''}`} key={task.id}>
                        <button type="button" className="pandat69-inbox-item-title" onClick={() => onOpenTask?.(task.id)}>
                            {task.name}
                        </button>
                        <div className="pandat69-inbox-item-meta">
                            <span>{task.inbox_state === 'reviewed' ? 'Reviewed' : 'Untriaged'}</span>
                            {task.capture_source && <span>{task.capture_source.replaceAll('_', ' ')}</span>}
                            {task.capture_url && <a href={task.capture_url} target="_blank" rel="noreferrer">Source</a>}
                        </div>
                        <div className="pandat69-inbox-item-actions">
                            <button
                                type="button"
                                className="pandat69-button"
                                disabled={setState.isPending}
                                onClick={() => setState.mutate({
                                    taskId: task.id,
                                    state: task.inbox_state === 'reviewed' ? 'untriaged' : 'reviewed',
                                })}
                            >
                                {task.inbox_state === 'reviewed' ? 'Mark untriaged' : 'Mark reviewed'}
                            </button>
                            <button type="button" className="pandat69-button pandat69-button-primary" onClick={() => setMovingTask(task)}>
                                Move to board…
                            </button>
                        </div>
                    </article>
                ))}
            </div>

            {isOwnInbox && (
                <details className="pandat69-inbox-delegation">
                    <summary>Inbox access</summary>
                    <p className="pandat69-field-hint">
                        Contributors may submit items without seeing your Inbox. Triagers may read and classify it, but can move items only to boards they can independently write to.
                    </p>
                    <UserSelect
                        selectedUserIds={delegateIds}
                        onChange={setDelegateIds}
                        inputLabel="Find people to delegate Inbox access to"
                    />
                    {delegateIds.map(userId => (
                        <label className="pandat69-inbox-delegate-role" key={userId}>
                            User #{userId}
                            <select
                                className="pandat69-select"
                                value={delegateRoles[userId] || 'contributor'}
                                onChange={event => setDelegateRoles(current => ({ ...current, [userId]: event.target.value }))}
                            >
                                <option value="contributor">Contributor · submit only</option>
                                <option value="triager">Triager · read and classify</option>
                            </select>
                        </label>
                    ))}
                    <button type="button" className="pandat69-button" onClick={saveDelegates} disabled={replaceDelegates.isPending}>
                        {replaceDelegates.isPending ? 'Saving…' : 'Save Inbox access'}
                    </button>
                </details>
            )}

            <TaskMoveDialog
                task={movingTask}
                isOpen={Boolean(movingTask)}
                onClose={() => setMovingTask(null)}
            />
        </section>
    );
};

export default InboxView;
