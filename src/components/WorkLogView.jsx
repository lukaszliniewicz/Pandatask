import React, { useMemo, useState } from 'react';
import { useWorkEntries, useWorkMutations, useWorkReport } from '../hooks/useWorkLog';
import WorkEntryForm from './work/WorkEntryForm';
import WorkSuggestionsPanel from './work/WorkSuggestionsPanel';
import { workAllocationTargetLabel } from '../workLogModel.mjs';

const formatDuration = (seconds) => {
    const totalMinutes = Math.round(Number(seconds || 0) / 60);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return hours ? `${hours}h${minutes ? ` ${minutes}m` : ''}` : `${minutes}m`;
};

const startOfWindow = () => {
    const date = new Date();
    date.setDate(date.getDate() - 29);
    return date.toISOString().slice(0, 10);
};


const WorkLogView = () => {
    const [startDate, setStartDate] = useState(startOfWindow());
    const [endDate, setEndDate] = useState(() => new Date().toISOString().slice(0, 10));
    const [editingEntryId, setEditingEntryId] = useState(null);
    const filters = useMemo(() => ({ start_date: startDate, end_date: endDate, limit: 500 }), [startDate, endDate]);
    const { data: entries = [], isLoading } = useWorkEntries(filters);
    const { data: report } = useWorkReport({ start_date: startDate, end_date: endDate });
    const { deleteEntry, updateEntry } = useWorkMutations();

    const exportCsv = () => {
        const rows = [
            ['Date', 'Activity', 'Title', 'Minutes', 'Capacity', 'Allocated targets'],
            ...entries.map((entry) => [
                entry.work_date,
                entry.activity_type || entry.kind,
                entry.title,
                Math.round(Number(entry.duration_seconds || 0) / 60),
                entry.capacity || '',
                (entry.allocations || []).map((allocation) => workAllocationTargetLabel(allocation)).join(' | '),
            ]),
        ];
        const csv = rows.map((row) => row.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `pandatask-work-${startDate}-${endDate}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    };

    const renderBreakdown = (title, rows = []) => rows.length > 0 && (
        <div className="pandat69-work-breakdown-group">
            <strong>{title}</strong>
            <div className="pandat69-work-breakdown">
                {rows.map((row, index) => (
                    <span key={`${row.id || row.name || row.activity_type || row.kind || 'other'}-${index}`}>
                        {row.name || row.activity_type || (row.kind === 'residual' ? 'Unitemised' : 'Other') || 'Unspecified'}: <strong>{formatDuration(row.duration_seconds)}</strong>
                        {row.capacity ? ` · ${row.capacity}` : ''}
                    </span>
                ))}
            </div>
        </div>
    );

    return (
        <section className="pandat69-work-log">
            <div className="pandat69-work-log-header">
                <div>
                    <h2>Work Log</h2>
                    <p>Record actual work independently of task workflow. Entries can be standalone or allocated to a task or board.</p>
                </div>
                <div className="pandat69-work-range">
                    <label>From <input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} /></label>
                    <label>To <input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} /></label>
                    <button type="button" className="pandat69-button" onClick={exportCsv} disabled={!entries.length}>CSV</button>
                    <button type="button" className="pandat69-button" onClick={() => window.print()}>Print</button>
                </div>
            </div>

            <WorkSuggestionsPanel filters={{ start_date: startDate, end_date: endDate }} />

            <div className="pandat69-work-summary-grid">
                <div className="pandat69-work-summary-card"><strong>{formatDuration(report?.total_seconds)}</strong><span>Total recorded</span></div>
                <div className="pandat69-work-summary-card"><strong>{formatDuration(report?.task_linked_seconds)}</strong><span>Task-linked</span></div>
                <div className="pandat69-work-summary-card"><strong>{formatDuration(report?.board_only_seconds)}</strong><span>Board-only / ad-hoc</span></div>
                <div className="pandat69-work-summary-card"><strong>{formatDuration(report?.unallocated_seconds)}</strong><span>Unallocated personal</span></div>
                <div className="pandat69-work-summary-card"><strong>{formatDuration(report?.residual_seconds)}</strong><span>Unitemised residual</span></div>
                <div className="pandat69-work-summary-card"><strong>{report?.unresolved_occurrences || 0}</strong><span>Completed · time unresolved</span></div>
            </div>

            <p className="pandat69-field-hint">Total recorded work counts each entry once. Task, board and residual figures classify that total and are not added on top of it.</p>
            {renderBreakdown('By activity', report?.activity_breakdown || report?.breakdown || [])}
            {renderBreakdown('By task', report?.task_breakdown || [])}
            {renderBreakdown('By board', report?.board_breakdown || [])}
            {renderBreakdown('By project', report?.project_breakdown || [])}
            {renderBreakdown('By category', report?.category_breakdown || [])}
            {renderBreakdown('By capacity', report?.capacity_breakdown || [])}

            <div className="pandat69-work-layout">
                <div className="pandat69-work-entry-panel">
                    <h3>Log work</h3>
                    <WorkEntryForm />
                </div>
                <div className="pandat69-work-history-panel">
                    <h3>Entries</h3>
                    {isLoading ? <div className="pandat69-loading">Loading work…</div> : entries.length === 0 ? (
                        <p>No work recorded in this period.</p>
                    ) : (
                        <ol className="pandat69-work-entry-list">
                            {entries.map((entry) => (
                                <li key={entry.id}>
                                    {editingEntryId === entry.id ? (
                                        <div className="pandat69-work-entry-editor">
                                            <WorkEntryForm
                                                key={`edit-${entry.id}`}
                                                initialValues={entry}
                                                onSubmitOverride={(payload) => updateEntry.mutateAsync({ id: entry.id, data: payload })}
                                                isSubmitting={updateEntry.isPending}
                                                submitLabel="Save changes"
                                                allocationHint="Reclassify this entry by task or board. Any remainder stays explicitly unallocated."
                                                onSaved={() => setEditingEntryId(null)}
                                            />
                                            <button type="button" className="pandat69-button" onClick={() => setEditingEntryId(null)} disabled={updateEntry.isPending}>Cancel</button>
                                        </div>
                                    ) : (
                                        <>
                                            <div className="pandat69-work-entry-main">
                                                <strong>{entry.title}</strong>
                                                <span>{entry.work_date} · {formatDuration(entry.duration_seconds)} · {entry.activity_type || 'Unitemised'}{entry.capacity ? ` · ${entry.capacity}` : ''}</span>
                                                {entry.notes && <small>{entry.notes}</small>}
                                                {entry.allocations?.length > 0 && (
                                                    <small>{entry.allocations.map((allocation) => `${workAllocationTargetLabel(allocation)} (${formatDuration(allocation.seconds)})`).join(' · ')}</small>
                                                )}
                                            </div>
                                            {entry.kind !== 'residual' && (
                                                <div className="pandat69-work-entry-actions">
                                                    <button type="button" className="pandat69-button pandat69-compact-control" onClick={() => setEditingEntryId(entry.id)}>Edit</button>
                                                    <button type="button" className="pandat69-button pandat69-button-danger pandat69-compact-control" disabled={deleteEntry.isPending} onClick={() => {
                                                        if (window.confirm(`Delete work entry “${entry.title}”?`)) deleteEntry.mutate({ id: entry.id });
                                                    }}>Delete</button>
                                                </div>
                                            )}
                                        </>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </div>
        </section>
    );
};;

export default WorkLogView;
