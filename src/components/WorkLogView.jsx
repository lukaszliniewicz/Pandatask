import React, { useMemo, useState } from 'react';
import { useActivityTypes, useInfiniteWorkEntries, useWorkMutations, useWorkReport } from '../hooks/useWorkLog';
import { useUserBoards } from '../hooks/useUserBoards';
import WorkSuggestionsPanel from './work/WorkSuggestionsPanel';
import WorkReportSummary from './work/WorkReportSummary';
import Icon from './Icon';
import { formatWorkDuration, getWorkAllocationLabel, getWorkEntryPresentation } from '../workReportModel.mjs';

const isoDate = date => date.toISOString().slice(0, 10);
const rangeStart = days => {
    const date = new Date();
    date.setDate(date.getDate() - (days - 1));
    return isoDate(date);
};

const formatDay = value =>
    new Date(`${value}T12:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

const WorkLogView = ({ onLogWork, onManageWorkTypes, onOpenTask }) => {
    const [startDate, setStartDate] = useState(() => rangeStart(30));
    const [endDate, setEndDate] = useState(() => isoDate(new Date()));
    const [deleteError, setDeleteError] = useState('');
    const filters = useMemo(() => ({ start_date: startDate, end_date: endDate }), [startDate, endDate]);
    const entriesQuery = useInfiniteWorkEntries(filters);
    const entries = useMemo(() => entriesQuery.data?.pages.flat() || [], [entriesQuery.data]);
    const { data: report, isLoading: isReportLoading, isError: isReportError } = useWorkReport(filters);
    const { data: activityTypes = [] } = useActivityTypes();
    const { data: boards = [] } = useUserBoards();
    const { deleteEntry } = useWorkMutations();

    const groupedEntries = useMemo(() => {
        const groups = new Map();
        entries.forEach(entry => {
            if (!groups.has(entry.work_date)) groups.set(entry.work_date, []);
            groups.get(entry.work_date).push(entry);
        });
        return Array.from(groups.entries());
    }, [entries]);

    const setPreset = days => {
        setStartDate(rangeStart(days));
        setEndDate(isoDate(new Date()));
    };

    const exportCsv = () => {
        const rows = [
            ['Date', 'Work type', 'Title', 'Minutes', 'Capacity', 'Allocated targets'],
            ...entries.map(entry => {
                const presentation = getWorkEntryPresentation(entry, {
                    activityTypes,
                    boards,
                });
                return [
                    entry.work_date,
                    presentation.typeLabel,
                    presentation.title,
                    Math.round(Number(entry.duration_seconds || 0) / 60),
                    entry.capacity || '',
                    (entry.allocations || []).map(allocation => getWorkAllocationLabel(allocation, boards)).join(' | '),
                ];
            }),
        ];
        const csv = rows
            .map(row => row.map(value => `"${String(value ?? '').replaceAll('"', '""')}"`).join(','))
            .join('\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `pandatask-work-${startDate}-${endDate}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    };

    const remove = async entry => {
        if (!window.confirm(`Delete work entry “${entry.title}”?`)) return;
        setDeleteError('');
        try {
            await deleteEntry.mutateAsync({ id: entry.id });
        } catch (error) {
            setDeleteError(error?.response?.data?.message || error?.message || 'The entry could not be deleted.');
        }
    };

    return (
        <section className="pandat69-work-log">
            <header className="pandat69-work-log-header">
                <div className="pandat69-work-log-heading">
                    <span className="pandat69-work-eyebrow">Your time, without the timesheet theatre</span>
                    <h2>Work log</h2>
                    <p>Record what happened, then connect it to tasks or boards only when that adds useful context.</p>
                </div>
                <div className="pandat69-work-log-primary-actions">
                    <button type="button" className="pandat69-button" onClick={onManageWorkTypes}>
                        <Icon name="tags" size={17} /> Work types
                    </button>
                    <button
                        type="button"
                        className="pandat69-button pandat69-button-primary"
                        onClick={() => onLogWork()}
                    >
                        <Icon name="clock" size={17} /> Log work
                    </button>
                </div>
            </header>

            <div className="pandat69-work-toolbar" aria-label="Work log date range">
                <div className="pandat69-work-presets">
                    <button
                        type="button"
                        className="pandat69-button pandat69-compact-control"
                        onClick={() => setPreset(7)}
                    >
                        7 days
                    </button>
                    <button
                        type="button"
                        className="pandat69-button pandat69-compact-control"
                        onClick={() => setPreset(30)}
                    >
                        30 days
                    </button>
                </div>
                <div className="pandat69-work-range">
                    <label>
                        From{' '}
                        <input
                            type="date"
                            value={startDate}
                            max={endDate}
                            onChange={event => setStartDate(event.target.value)}
                        />
                    </label>
                    <label>
                        To{' '}
                        <input
                            type="date"
                            value={endDate}
                            min={startDate}
                            onChange={event => setEndDate(event.target.value)}
                        />
                    </label>
                </div>
                <div className="pandat69-work-export-actions">
                    <button
                        type="button"
                        className="pandat69-button pandat69-compact-control"
                        onClick={exportCsv}
                        disabled={!entries.length}
                    >
                        CSV
                    </button>
                    <button
                        type="button"
                        className="pandat69-button pandat69-compact-control"
                        onClick={() => window.print()}
                    >
                        Print
                    </button>
                </div>
            </div>

            <WorkSuggestionsPanel filters={filters} />

            <section className="pandat69-work-insights" aria-labelledby="pandat69-work-insights-title">
                <div className="pandat69-work-section-heading">
                    <div>
                        <span>Overview</span>
                        <h3 id="pandat69-work-insights-title">Where the time went</h3>
                    </div>
                </div>
                {isReportLoading ? (
                    <div className="pandat69-loading" role="status">
                        Calculating your work summary…
                    </div>
                ) : isReportError ? (
                    <div className="pandat69-error" role="alert">
                        The work summary could not be loaded.
                    </div>
                ) : (
                    <WorkReportSummary report={report} onOpenTask={onOpenTask} />
                )}
            </section>

            <section className="pandat69-work-history-panel" aria-labelledby="pandat69-work-history-title">
                <div className="pandat69-work-section-heading">
                    <div>
                        <span>History</span>
                        <h3 id="pandat69-work-history-title">Recorded work</h3>
                    </div>
                    <small>{entries.length} loaded</small>
                </div>
                {deleteError && (
                    <div className="pandat69-error" role="alert">
                        {deleteError}
                    </div>
                )}
                {entriesQuery.isLoading ? (
                    <div className="pandat69-loading" role="status">
                        Loading work…
                    </div>
                ) : entriesQuery.isError ? (
                    <div className="pandat69-empty-state">
                        <p>Your work entries could not be loaded.</p>
                        <button type="button" className="pandat69-button" onClick={() => entriesQuery.refetch()}>
                            Try again
                        </button>
                    </div>
                ) : groupedEntries.length === 0 ? (
                    <div className="pandat69-empty-state pandat69-work-empty">
                        <Icon name="clock" size={28} />
                        <h4>No work recorded for these dates</h4>
                        <p>Log the useful reality, not a forensic reconstruction of your entire day.</p>
                        <button
                            type="button"
                            className="pandat69-button pandat69-button-primary"
                            onClick={() => onLogWork()}
                        >
                            Log work
                        </button>
                    </div>
                ) : (
                    <div className="pandat69-work-entry-groups">
                        {groupedEntries.map(([date, dateEntries]) => (
                            <section key={date} className="pandat69-work-entry-day">
                                <h4>
                                    <span>{formatDay(date)}</span>
                                    <strong>
                                        {formatWorkDuration(
                                            dateEntries.reduce(
                                                (total, entry) => total + Number(entry.duration_seconds || 0),
                                                0
                                            )
                                        )}
                                    </strong>
                                </h4>
                                <ol className="pandat69-work-entry-list">
                                    {dateEntries.map(entry => {
                                        const presentation = getWorkEntryPresentation(entry, {
                                            activityTypes,
                                            boards,
                                        });
                                        return (
                                            <li key={entry.id} className={presentation.isResidual ? 'is-residual' : ''}>
                                                <div className="pandat69-work-entry-type" aria-hidden="true">
                                                    <span />
                                                </div>
                                                <div className="pandat69-work-entry-main">
                                                    <div className="pandat69-work-entry-title-row">
                                                        <strong>{presentation.title}</strong>
                                                        <span>{formatWorkDuration(entry.duration_seconds)}</span>
                                                    </div>
                                                    <div className="pandat69-work-entry-meta">
                                                        <span className={presentation.isResidual ? 'is-attention' : ''}>
                                                            {presentation.typeLabel}
                                                        </span>
                                                        {entry.capacity && <span>{entry.capacity}</span>}
                                                        {!presentation.isResidual && entry.allocations?.length > 0 && (
                                                            <span>
                                                                {entry.allocations
                                                                    .map(allocation =>
                                                                        getWorkAllocationLabel(allocation, boards)
                                                                    )
                                                                    .join(' · ')}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {entry.notes && <p>{entry.notes}</p>}
                                                    {presentation.isResidual && (
                                                        <small>
                                                            Included in the declared actual total, but not represented
                                                            by a specific work entry. Adding detail is optional.
                                                        </small>
                                                    )}
                                                </div>
                                                <div className="pandat69-work-entry-actions">
                                                    {presentation.isResidual ? (
                                                        presentation.task && (
                                                            <button
                                                                type="button"
                                                                className="pandat69-button pandat69-compact-control"
                                                                onClick={() =>
                                                                    onLogWork({
                                                                        task: presentation.task,
                                                                    })
                                                                }
                                                            >
                                                                Add detail
                                                            </button>
                                                        )
                                                    ) : (
                                                        <>
                                                            <button
                                                                type="button"
                                                                className="pandat69-button pandat69-compact-control"
                                                                onClick={() =>
                                                                    onLogWork({
                                                                        entry,
                                                                    })
                                                                }
                                                            >
                                                                Edit
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="pandat69-button pandat69-button-danger pandat69-compact-control"
                                                                disabled={deleteEntry.isPending}
                                                                onClick={() => remove(entry)}
                                                            >
                                                                Delete
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ol>
                            </section>
                        ))}
                    </div>
                )}
                {entriesQuery.hasNextPage && (
                    <button
                        type="button"
                        className="pandat69-button pandat69-work-load-more"
                        onClick={() => entriesQuery.fetchNextPage()}
                        disabled={entriesQuery.isFetchingNextPage}
                    >
                        {entriesQuery.isFetchingNextPage ? 'Loading…' : 'Load older entries'}
                    </button>
                )}
            </section>
        </section>
    );
};

export default WorkLogView;
