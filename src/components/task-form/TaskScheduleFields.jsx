import React from 'react';
import { Controller } from 'react-hook-form';
import TaskSelect from '../TaskSelect';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const TaskScheduleFields = ({
    active,
    control,
    errors,
    fieldPrefix,
    isRecurring,
    notifyDeadline,
    recurrenceFrequency,
    register,
    scheduleMode,
    targetBoard,
    task
}) => (
    <section
        id={`${fieldPrefix}-schedule-panel`}
        role="tabpanel"
        aria-labelledby={`${fieldPrefix}-schedule-tab`}
        className={`pandat69-form-tab-content ${active ? 'active' : ''}`}
        hidden={!active}
    >
        <fieldset className="pandat69-form-field pandat69-fieldset pandat69-schedule-mode">
            <legend>Timeline &amp; Dependencies</legend>
            <div className="pandat69-choice-row">
                <label>
                    <input type="radio" value="fixed" {...register('schedule_mode')} />
                    Fixed Dates
                </label>
                <label>
                    <input type="radio" value="dynamic" {...register('schedule_mode')} />
                    Dynamic (Duration / Dependent)
                </label>
            </div>
        </fieldset>

        {scheduleMode === 'fixed' ? (
            <div className="pandat69-form-row pandat69-form-panel">
                <div className="pandat69-form-field pandat69-form-field-half">
                    <label htmlFor={`${fieldPrefix}-start-date`}>Start Date</label>
                    <input id={`${fieldPrefix}-start-date`} type="date" className="pandat69-input" {...register('start_date')} />
                </div>
                <div className="pandat69-form-field pandat69-form-field-half">
                    <label htmlFor={`${fieldPrefix}-deadline`}>Deadline Date</label>
                    <input id={`${fieldPrefix}-deadline`} type="date" className="pandat69-input" {...register('deadline')} />
                </div>
            </div>
        ) : (
            <div className="pandat69-form-panel pandat69-dynamic-schedule">
                <div className="pandat69-form-field">
                    <label htmlFor={`${fieldPrefix}-duration`}>Task Duration (Days)</label>
                    <input
                        id={`${fieldPrefix}-duration`}
                        type="number"
                        min="1"
                        className="pandat69-input"
                        placeholder="For example, 5"
                        aria-invalid={Boolean(errors.deadline_days_after_start)}
                        aria-describedby={`${fieldPrefix}-duration-hint${errors.deadline_days_after_start ? ` ${fieldPrefix}-duration-error` : ''}`}
                        {...register('deadline_days_after_start', {
                            required: scheduleMode === 'dynamic' ? 'Duration is required' : false
                        })}
                    />
                    {errors.deadline_days_after_start && (
                        <span id={`${fieldPrefix}-duration-error`} className="pandat69-error-text">{errors.deadline_days_after_start.message}</span>
                    )}
                    <p id={`${fieldPrefix}-duration-hint`} className="pandat69-field-hint">How many days will this take once started?</p>
                </div>

                <fieldset className="pandat69-form-field pandat69-fieldset pandat69-dependent-tasks">
                    <legend>Starts After (Blocked By)</legend>
                    <Controller
                        control={control}
                        name="predecessors"
                        render={({ field: { onChange, value } }) => (
                            <TaskSelect
                                selectedTaskIds={value}
                                onChange={onChange}
                                currentTaskId={task?.id}
                                overrideBoardName={targetBoard}
                                inputLabel="Search for predecessor tasks"
                            />
                        )}
                    />
                    <p className="pandat69-field-hint">
                        Select tasks that must finish first. Start and deadline dates are then calculated automatically.
                    </p>
                </fieldset>
            </div>
        )}

        <div className="pandat69-form-field pandat69-checkbox-field">
            <label>
                <input type="checkbox" {...register('notify_deadline')} />
                Notify users before the deadline
            </label>
        </div>

        {notifyDeadline && (
            <div className="pandat69-form-field pandat69-indented-field">
                <label htmlFor={`${fieldPrefix}-notify-days`}>Days before deadline to notify</label>
                <input id={`${fieldPrefix}-notify-days`} type="number" className="pandat69-input pandat69-small-number" min="1" max="30" {...register('notify_days_before')} />
            </div>
        )}

        <fieldset className="pandat69-form-field pandat69-fieldset pandat69-recurrence-fieldset">
            <legend className="pandat69-visually-hidden">Recurrence</legend>
            <label className="pandat69-checkbox-label">
                <input type="checkbox" {...register('is_recurring')} />
                <strong>Make this a repeating task</strong>
            </label>

            {isRecurring && (
                <div className="pandat69-recurrence-options pandat69-form-panel">
                    <div className="pandat69-form-field">
                        <label htmlFor={`${fieldPrefix}-recurrence-frequency`}>Repeats every</label>
                        <select id={`${fieldPrefix}-recurrence-frequency`} className="pandat69-select" {...register('recurrence_frequency')}>
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Bi-Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="custom_weekly">Custom Days of Week</option>
                        </select>
                    </div>

                    {recurrenceFrequency === 'custom_weekly' && (
                        <fieldset className="pandat69-form-field pandat69-fieldset">
                            <legend>Select Days</legend>
                            <div className="pandat69-choice-row pandat69-weekday-choices">
                                {WEEKDAYS.map((day, index) => (
                                    <label key={day}>
                                        <input type="checkbox" value={index + 1} {...register('recurrence_days')} /> {day}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    <div className="pandat69-form-field">
                        <label htmlFor={`${fieldPrefix}-recurrence-end`}>Stop repeating on (Optional)</label>
                        <input id={`${fieldPrefix}-recurrence-end`} type="date" className="pandat69-input" {...register('recurrence_ends_on')} />
                    </div>
                </div>
            )}
        </fieldset>
    </section>
);

export default TaskScheduleFields;
