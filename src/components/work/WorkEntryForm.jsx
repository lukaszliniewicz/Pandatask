import React, { useId, useMemo, useState } from "react";
import {
  useActivityTypes,
  useTaskWork,
  useWorkMutations,
} from "../../hooks/useWorkLog";
import TaskSelect from "../TaskSelect";
import Icon from "../Icon";
import {
  buildAllocationPayload,
  minutesToSeconds,
  summarizeAllocationDrafts,
  validateAllocationDrafts,
} from "../../workLogModel.mjs";

const today = () => new Date().toISOString().slice(0, 10);
let allocationSequence = 0;
const newAllocation = (minutes = 30) => ({
  key: `work-allocation-${++allocationSequence}`,
  taskId: "",
  minutes: Math.max(1, Number(minutes) || 1),
  residualHandling: "",
});

const WorkAllocationRow = ({ allocation, onChange, onRemove }) => {
  const { data: taskWork } = useTaskWork(allocation.taskId || null);
  const resolution = taskWork?.my_time?.resolution;
  const specificSeconds = Number(taskWork?.my_time?.specific_seconds || 0);
  const isResolved = resolution?.state === "resolved";
  const residualSeconds = isResolved
    ? Math.max(
        0,
        Number(resolution.declared_actual_seconds || 0) - specificSeconds,
      )
    : 0;

  return (
    <div className="pandat69-work-allocation-row">
      <div className="pandat69-work-allocation-task">
        <TaskSelect
          selectedTaskIds={allocation.taskId}
          onChange={(taskId) =>
            onChange({ ...allocation, taskId, residualHandling: "" })
          }
          mode="single"
          inputLabel="Search for a task to allocate work to"
        />
      </div>
      <label className="pandat69-work-allocation-minutes">
        <span className="pandat69-visually-hidden">Allocated minutes</span>
        <input
          type="number"
          min="1"
          step="1"
          className="pandat69-input"
          value={allocation.minutes}
          onChange={(event) =>
            onChange({ ...allocation, minutes: event.target.value })
          }
          aria-label="Allocated minutes"
        />
        <span>min</span>
      </label>
      <button
        type="button"
        className="pandat69-icon-button"
        onClick={onRemove}
        aria-label="Remove allocation"
        title="Remove allocation"
      >
        <Icon name="trash" size={16} />
      </button>
      {allocation.taskId && isResolved && (
        <div className="pandat69-work-allocation-residual">
          <label>
            {residualSeconds > 0
              ? `This task has ${Math.round(
                  residualSeconds / 60,
                )} minutes of unitemised time.`
              : "This task time was already resolved with no unitemised remainder."}
            <select
              className="pandat69-select"
              value={allocation.residualHandling}
              onChange={(event) =>
                onChange({
                  ...allocation,
                  residualHandling: event.target.value,
                })
              }
              required
            >
              <option value="">
                Choose how this detail relates to the resolved total…
              </option>
              {residualSeconds > 0 && (
                <option value="refine_residual">
                  Replace part of the unitemised time
                </option>
              )}
              <option value="additional">Count as additional work</option>
            </select>
          </label>
        </div>
      )}
    </div>
  );
};

const WorkEntryForm = ({
  task = null,
  onSaved = null,
  compact = false,
  initialValues = null,
  onSubmitOverride = null,
  isSubmitting = false,
  submitLabel = null,
  allocationHint = "",
}) => {
  const { data: activityTypes = [] } = useActivityTypes();
  const { createEntry } = useWorkMutations();
  const formId = useId().replaceAll(":", "");
  const [activityType, setActivityType] = useState(
    initialValues?.activity_type || "research",
  );
  const [minutes, setMinutes] = useState(() =>
    initialValues?.duration_seconds
      ? Math.max(1, Math.round(Number(initialValues.duration_seconds) / 60))
      : 30,
  );
  const [workDate, setWorkDate] = useState(initialValues?.work_date || today());
  const [title, setTitle] = useState(initialValues?.title || task?.name || "");
  const [notes, setNotes] = useState(initialValues?.notes || "");
  const [capacity, setCapacity] = useState(initialValues?.capacity || "");
  const [allocations, setAllocations] = useState([]);
  const [residualHandling, setResidualHandling] = useState("");
  const [error, setError] = useState("");
  const { data: targetWork } = useTaskWork(task?.id || null);
  const resolution = targetWork?.my_time?.resolution;
  const specificSeconds = Number(targetWork?.my_time?.specific_seconds || 0);
  const isResolved = resolution?.state === "resolved";
  const residualSeconds = isResolved
    ? Math.max(
        0,
        Number(resolution.declared_actual_seconds || 0) - specificSeconds,
      )
    : 0;

  const durationSeconds = useMemo(() => minutesToSeconds(minutes), [minutes]);
  const allocationSummary = useMemo(
    () => summarizeAllocationDrafts(minutes, allocations),
    [minutes, allocations],
  );
  const { allocatedMinutes, remainingMinutes, overallocatedMinutes } =
    allocationSummary;

  const updateAllocation = (nextAllocation) => {
    setAllocations((current) =>
      current.map((allocation) =>
        allocation.key === nextAllocation.key ? nextAllocation : allocation,
      ),
    );
  };

  const addAllocation = () => {
    setAllocations((current) => [
      ...current,
      newAllocation(
        remainingMinutes > 0
          ? remainingMinutes
          : Math.min(30, Number(minutes || 30)),
      ),
    ]);
  };

  const submit = async (event) => {
    event.preventDefault();
    setError("");
    if (durationSeconds <= 0) {
      setError("Enter a positive duration.");
      return;
    }

    let payloadAllocations;
    if (task) {
      if (isResolved && !residualHandling) {
        setError(
          residualSeconds > 0
            ? "Choose whether this detail refines existing unitemised time or is additional work."
            : "Confirm that this detail is additional work because the task time was already resolved.",
        );
        return;
      }
      payloadAllocations = [
        {
          task_id: Number(task.id),
          seconds: durationSeconds,
          ...(residualHandling ? { residual_handling: residualHandling } : {}),
        },
      ];
    } else {
      const allocationError = validateAllocationDrafts(
        durationSeconds,
        allocations,
      );
      if (allocationError) {
        setError(allocationError);
        return;
      }
      payloadAllocations = buildAllocationPayload(allocations);
    }

    try {
      const payload = {
        title:
          title.trim() ||
          activityTypes.find((item) => item.key === activityType)?.label ||
          "Work",
        notes,
        activity_type: activityType,
        capacity: capacity || null,
        work_date: workDate,
        duration_seconds: durationSeconds,
        allocations: payloadAllocations,
      };
      const entry = onSubmitOverride
        ? await onSubmitOverride(payload)
        : await createEntry.mutateAsync(payload);
      if (!onSubmitOverride) {
        setNotes("");
        if (!task) {
          setTitle("");
          setAllocations([]);
        }
      }
      onSaved?.(entry);
    } catch (err) {
      setError(
        err?.response?.data?.message || err?.message || "Failed to log work.",
      );
    }
  };

  return (
    <form
      className={`pandat69-work-form ${
        compact ? "pandat69-work-form-compact" : ""
      }`}
      onSubmit={submit}
    >
      <div className="pandat69-form-row">
        <div className="pandat69-form-field pandat69-form-field-half">
          <label htmlFor={`${formId}-activity`}>Activity</label>
          <select
            id={`${formId}-activity`}
            className="pandat69-select"
            value={activityType}
            onChange={(event) => setActivityType(event.target.value)}
            required
          >
            {activityTypes.map((type) => (
              <option key={type.key} value={type.key}>
                {type.label}
              </option>
            ))}
          </select>
        </div>
        <div className="pandat69-form-field pandat69-form-field-half">
          <label htmlFor={`${formId}-date`}>Work date</label>
          <input
            id={`${formId}-date`}
            type="date"
            className="pandat69-input"
            value={workDate}
            onChange={(event) => setWorkDate(event.target.value)}
            required
          />
        </div>
      </div>

      <div className="pandat69-form-field">
        <span className="pandat69-form-label">Duration</span>
        <div className="pandat69-work-duration-row">
          {[15, 30, 60].map((value) => (
            <button
              key={value}
              type="button"
              className={`pandat69-button pandat69-work-duration-shortcut ${
                Number(minutes) === value ? "active" : ""
              }`}
              onClick={() => setMinutes(value)}
            >
              {value === 60 ? "1h" : `${value}m`}
            </button>
          ))}
          <input
            type="number"
            min="1"
            step="1"
            className="pandat69-input pandat69-work-minutes"
            value={minutes}
            onChange={(event) => setMinutes(event.target.value)}
            aria-label="Custom duration in minutes"
          />
          <span>minutes</span>
        </div>
      </div>

      <div className="pandat69-form-field">
        <label htmlFor={`${formId}-title`}>Title</label>
        <input
          id={`${formId}-title`}
          className="pandat69-input"
          value={title}
          onChange={(event) => setTitle(event.target.value)}
          maxLength={255}
          placeholder="What did you work on?"
        />
      </div>

      {!task && (
        <fieldset className="pandat69-form-field pandat69-fieldset pandat69-work-allocations">
          <legend>Allocate time (optional)</legend>
          <p className="pandat69-field-hint">
            {allocationHint ||
              "One real event stays one work entry. Split its time between tasks here; any remainder stays explicitly unallocated."}
          </p>
          {allocations.map((allocation) => (
            <WorkAllocationRow
              key={allocation.key}
              allocation={allocation}
              onChange={updateAllocation}
              onRemove={() =>
                setAllocations((current) =>
                  current.filter((item) => item.key !== allocation.key),
                )
              }
            />
          ))}
          <div className="pandat69-work-allocation-summary">
            <span>Allocated: {allocatedMinutes} min</span>
            {overallocatedMinutes > 0 ? (
              <span className="pandat69-error-text">
                Overallocated: {overallocatedMinutes} min
              </span>
            ) : (
              <span>Unallocated: {remainingMinutes} min</span>
            )}
          </div>
          <button
            type="button"
            className="pandat69-button"
            onClick={addAllocation}
          >
            <Icon name="plus" size={16} /> Add allocation
          </button>
        </fieldset>
      )}

      {task && isResolved && (
        <div className="pandat69-form-field">
          <label htmlFor={`${formId}-residual-handling`}>
            Resolved task time
          </label>
          <select
            id={`${formId}-residual-handling`}
            className="pandat69-select"
            value={residualHandling}
            onChange={(event) => setResidualHandling(event.target.value)}
            required
          >
            <option value="">Choose…</option>
            {residualSeconds > 0 && (
              <option value="refine_residual">
                This detail replaces part of the unitemised time
              </option>
            )}
            <option value="additional">This is additional work</option>
          </select>
          <p className="pandat69-field-hint">
            {residualSeconds > 0
              ? `This task currently has ${Math.round(
                  residualSeconds / 60,
                )} minutes of unitemised time.`
              : "The previous declared actual was fully itemised, so new detail must be explicitly additional."}
          </p>
        </div>
      )}

      <div className="pandat69-form-row">
        <div className="pandat69-form-field pandat69-form-field-half">
          <label htmlFor={`${formId}-capacity`}>Capacity</label>
          <select
            id={`${formId}-capacity`}
            className="pandat69-select"
            value={capacity}
            onChange={(event) => setCapacity(event.target.value)}
          >
            <option value="">Unspecified</option>
            <option value="paid">Paid</option>
            <option value="volunteer">Volunteer</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>

      <div className="pandat69-form-field">
        <label htmlFor={`${formId}-notes`}>Notes (private by default)</label>
        <textarea
          id={`${formId}-notes`}
          className="pandat69-textarea"
          rows={compact ? 2 : 3}
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
        />
      </div>

      {error && (
        <div className="pandat69-error" role="alert">
          {error}
        </div>
      )}
      <button
        type="submit"
        className="pandat69-button pandat69-button-primary"
        disabled={createEntry.isPending || isSubmitting}
      >
        {createEntry.isPending || isSubmitting
          ? onSubmitOverride
            ? "Confirming…"
            : "Logging…"
          : submitLabel || (onSubmitOverride ? "Confirm work" : "Log work")}
      </button>
    </form>
  );
};

export default WorkEntryForm;
