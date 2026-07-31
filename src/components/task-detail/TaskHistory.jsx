import React, { useId, useState } from 'react';
import { useTaskHistory } from '../../hooks/useTaskHistory';
import { formatDisplayDate, parseUtcDateTime } from '../../utils';
import Icon from '../Icon';
import { renderHistoryItem } from './taskDetailFormatters';

const TaskHistory = ({ taskId }) => {
    const { data: history, isLoading } = useTaskHistory(taskId);
    const [isOpen, setIsOpen] = useState(false);
    const contentId = useId();

    return (
        <section className="pandat69-history-section">
            <button
                type="button"
                className={`pandat69-history-toggle ${isOpen ? 'open' : ''}`}
                onClick={() => setIsOpen((open) => !open)}
                aria-expanded={isOpen}
                aria-controls={contentId}
            >
                <span><Icon name="history" /> Audit Log &amp; History</span>
                <Icon name="chevron-down" />
            </button>

            {isOpen && (
                <div id={contentId} className="pandat69-history-content">
                    <ul className="pandat69-history-list">
                        {isLoading ? (
                            <li>Loading history...</li>
                        ) : history?.length > 0 ? (
                            history.map((entry) => (
                                <li key={entry.id}>
                                    <div className="history-change">{renderHistoryItem(entry)}</div>
                                    <div className="history-meta">
                                        {formatDisplayDate(parseUtcDateTime(entry.changed_at))} at{' '}
                                        {parseUtcDateTime(entry.changed_at).toLocaleTimeString()}
                                    </div>
                                </li>
                            ))
                        ) : (
                            <li>No history recorded.</li>
                        )}
                    </ul>
                </div>
            )}
        </section>
    );
};

export default TaskHistory;
