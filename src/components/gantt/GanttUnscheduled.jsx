import React from 'react';
import Icon from '../Icon';

const GanttUnscheduled = ({ rows, onTaskAction }) => {
    if (!rows.length) return null;

    return (
        <section className="pandat69-gantt-unscheduled">
            <div className="pandat69-gantt-unscheduled-heading">
                <div>
                    <h3>Unscheduled</h3>
                    <p>These tasks stay visible without fabricated dates.</p>
                </div>
                <span>{rows.length}</span>
            </div>
            <ul>
                {rows.map((row) => (
                    <li key={row.id}>
						{row.task.restricted ? (
							<span className="pandat69-gantt-unscheduled-name is-restricted">{row.task.name}</span>
						) : (
							<button type="button" className="pandat69-gantt-unscheduled-name" onClick={() => onTaskAction('view', row.task)}>
								{row.task.name}
							</button>
						)}
                        <span>
							{row.task.origin === 'external' ? 'External · ' : ''}
							{row.task.restricted
								? 'Source hidden'
								: row.task.project_name || row.task.board_display_name || 'No project'}
                            {row.task.is_blocked ? ' · blocked' : ''}
                        </span>
						{row.task.origin !== 'external' && !row.task.restricted && (
							<button type="button" className="pandat69-gantt-schedule-button" onClick={() => onTaskAction('edit', row.task)}>
								<Icon name="calendar-plus" size={15} /> Set dates
							</button>
						)}
                    </li>
                ))}
            </ul>
        </section>
    );
};

export default GanttUnscheduled;
