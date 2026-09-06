import React, { useState } from 'react';
import { useTaskRecurrence } from '../../hooks/useTaskRecurrence';
import Icon from '../Icon';

const TaskRecurrenceCard = ( { task, onNavigate } ) => {
	const [ beforeSequence, setBeforeSequence ] = useState( null );
	const { data, isLoading, isError, isFetching, refetch } = useTaskRecurrence(
		task,
		beforeSequence
	);
	if ( ! task.recurrence_series_id ) {
		return null;
	}
	const series = data?.series;
	return (
		<section
			className="pandat69-recurrence-card"
			aria-busy={ isFetching }
			aria-label="Repeating task"
		>
			<h3>
				<Icon name="refresh" size={ 18 } /> Repeating task · Occurrence{ ' ' }
				{ task.recurrence_sequence }
			</h3>
			<p>
				Originally scheduled for { task.recurrence_scheduled_start }.
				This occurrence keeps its own checklist and work history.
			</p>
			{ isLoading && <p role="status">Loading series…</p> }
			{ isError && (
				<p role="alert">
					The series could not be loaded.{ ' ' }
					<button
						type="button"
						className="pandat69-link-button"
						onClick={ () => refetch() }
					>
						Try again
					</button>
				</p>
			) }
			{ series && (
				<>
					<p>
						{ series.active
							? `Next scheduled start: ${
									series.next_start_date || 'unavailable'
							  }.`
							: 'No further occurrences are scheduled.' }
					</p>
					{ series.active &&
						Number( series.current_task_id ) ===
							Number( task.id ) &&
						task.status === 'done' && (
							<p role="status">
								This occurrence is complete. Creation of the
								next task is pending; the scheduler will retry.
							</p>
						) }
					{ series.current_task_id &&
						Number( series.current_task_id ) !==
							Number( task.id ) && (
							<button
								type="button"
								className="pandat69-button"
								onClick={ () =>
									onNavigate( series.current_task_id )
								}
							>
								Open latest occurrence
							</button>
						) }
					<details>
						<summary>Occurrence history</summary>
						<ul className="pandat69-recurrence-history">
							{ data.occurrences.map( ( occurrence ) => (
								<li key={ occurrence.id }>
									<button
										type="button"
										className="pandat69-link-button"
										aria-current={
											Number( occurrence.id ) ===
											Number( task.id )
												? 'page'
												: undefined
										}
										onClick={ () =>
											onNavigate( occurrence.id )
										}
									>
										{
											occurrence.recurrence_scheduled_start
										}{ ' ' }
										· { occurrence.name }
									</button>
									<span>
										{ Number( occurrence.archived ) === 1
											? 'Archived'
											: occurrence.status.replace(
													'-',
													' '
											  ) }
										{ occurrence.checklist_total
											? ` · ${ occurrence.checklist_checked }/${ occurrence.checklist_total } steps`
											: '' }
									</span>
								</li>
							) ) }
						</ul>
						{ data.has_more && (
							<button
								type="button"
								className="pandat69-button"
								onClick={ () =>
									setBeforeSequence(
										data.next_before_sequence
									)
								}
							>
								Older occurrences
							</button>
						) }
						{ beforeSequence && (
							<button
								type="button"
								className="pandat69-button"
								onClick={ () => setBeforeSequence( null ) }
							>
								Recent occurrences
							</button>
						) }
					</details>
				</>
			) }
		</section>
	);
};
export default TaskRecurrenceCard;
