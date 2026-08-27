import React, { useMemo } from 'react';
import { useActivityTypes } from '../../hooks/useWorkLog';
import Icon from '../Icon';

let sequence = 0;
export const newCompletionWorkItem = () => ({
    key: `completion-work-${++sequence}`,
    minutes: 15,
    activity_type: '',
    capacity: '',
    title: '',
});

const CompletionWorkBreakdownFields = ({
    actualSeconds = 0,
    specificSeconds = 0,
    workItems,
    onWorkItemsChange,
    residual,
    onResidualChange,
    disabled = false,
}) => {
    const { data: activityTypes = [] } = useActivityTypes();
    const activeTypes = useMemo(
        () => activityTypes.filter(type => type.is_active !== false && type.is_active !== 0),
        [activityTypes]
    );
    const itemisedSeconds = workItems.reduce(
        (sum, item) => sum + Math.max(0, Number(item.minutes || 0)) * 60,
        0
    );
    const availableSeconds = Math.max(
        0,
        Number(actualSeconds || 0) - Number(specificSeconds || 0)
    );
    const overageSeconds = Math.max(0, itemisedSeconds - availableSeconds);
    const remainingSeconds = Math.max(0, availableSeconds - itemisedSeconds);

    const updateItem = (key, changes) =>
        onWorkItemsChange(
            workItems.map(item => (item.key === key ? { ...item, ...changes } : item))
        );

    return (
        <fieldset className="pandat69-completion-breakdown" disabled={disabled}>
            <legend>Optional work classification</legend>
            <p className="pandat69-field-hint">
                Itemise any still-unlogged work, or simply classify the remaining
                {remainingSeconds > 0 ? ` ${Math.round(remainingSeconds / 60)} minutes` : ' time'}.
                Detailed work already logged is not repeated here.
            </p>

            {workItems.map((item, index) => (
                <div className="pandat69-completion-work-item" key={item.key}>
                    <label>
                        <span className="pandat69-visually-hidden">Item {index + 1} minutes</span>
                        <input
                            className="pandat69-input"
                            type="number"
                            min="1"
                            step="1"
                            required
                            value={item.minutes}
                            onChange={event => updateItem(item.key, { minutes: event.target.value })}
                            aria-label={`Item ${index + 1} minutes`}
                        />
                        <span>min</span>
                    </label>
                    <label>
                        <span className="pandat69-visually-hidden">Item {index + 1} work type</span>
                        <select
                            className="pandat69-select"
                            value={item.activity_type}
                            onChange={event => updateItem(item.key, { activity_type: event.target.value })}
                            required
                            aria-label={`Item ${index + 1} work type`}
                        >
                            <option value="">Work type…</option>
                            {activeTypes.map(type => (
                                <option key={type.key} value={type.key}>{type.label}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="pandat69-visually-hidden">Item {index + 1} capacity</span>
                        <select
                            className="pandat69-select"
                            value={item.capacity}
                            onChange={event => updateItem(item.key, { capacity: event.target.value })}
                            aria-label={`Item ${index + 1} capacity`}
                        >
                            <option value="">Capacity…</option>
                            <option value="paid">Paid</option>
                            <option value="volunteer">Volunteer</option>
                            <option value="other">Other</option>
                        </select>
                    </label>
                    <button
                        type="button"
                        className="pandat69-icon-button"
                        aria-label={`Remove item ${index + 1}`}
                        onClick={() => onWorkItemsChange(workItems.filter(current => current.key !== item.key))}
                    >
                        <Icon name="trash" size={15} />
                    </button>
                </div>
            ))}

            {overageSeconds > 0 && (
                <div className="pandat69-error" role="alert">
                    Itemised work exceeds the unlogged time by {Math.ceil(overageSeconds / 60)} minute{Math.ceil(overageSeconds / 60) === 1 ? '' : 's'}.
                </div>
            )}

            <button
                type="button"
                className="pandat69-button"
                onClick={() => onWorkItemsChange([...workItems, newCompletionWorkItem()])}
            >
                Add itemised work
            </button>

            <div className="pandat69-completion-residual-fields">
                <label>
                    Remaining time work type
                    <select
                        className="pandat69-select"
                        value={residual.activity_type || ''}
                        onChange={event => onResidualChange({ ...residual, activity_type: event.target.value })}
                    >
                        <option value="">Leave unclassified</option>
                        {activeTypes.map(type => (
                            <option key={type.key} value={type.key}>{type.label}</option>
                        ))}
                    </select>
                </label>
                <label>
                    Capacity
                    <select
                        className="pandat69-select"
                        value={residual.capacity || ''}
                        onChange={event => onResidualChange({ ...residual, capacity: event.target.value })}
                    >
                        <option value="">Unspecified</option>
                        <option value="paid">Paid</option>
                        <option value="volunteer">Volunteer</option>
                        <option value="other">Other</option>
                    </select>
                </label>
            </div>
        </fieldset>
    );
};

export default CompletionWorkBreakdownFields;
