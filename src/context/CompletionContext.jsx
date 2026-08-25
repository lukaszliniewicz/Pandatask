import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";
import Modal from "../components/Modal";
import { useTaskMutations } from "../hooks/useTaskMutations";
import { useTaskWork } from "../hooks/useWorkLog";
import { useConfig } from "./ConfigContext";

const CompletionContext = createContext(null);

const CompletionDialog = ({ task, changeComment = "", onClose }) => {
  const { completeTask } = useTaskMutations();
  const { data } = useTaskWork(task?.id);
  const specificSeconds = Number(data?.my_time?.specific_seconds || 0);
  const resolution = data?.my_time?.resolution;
  const declaredSeconds =
    resolution?.state === "resolved"
      ? Number(resolution.declared_actual_seconds || 0)
      : 0;
  const suggestedSeconds = Math.max(specificSeconds, declaredSeconds);
  const [hours, setHours] = useState(Math.floor(suggestedSeconds / 3600));
  const [minutes, setMinutes] = useState(
    Math.round((suggestedSeconds % 3600) / 60),
  );
  const [notTracked, setNotTracked] = useState(false);
  const [noPersonalWork, setNoPersonalWork] = useState(false);
  const [timeEdited, setTimeEdited] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    setTimeEdited(false);
    setNotTracked(false);
    setNoPersonalWork(false);
    setError("");
  }, [task?.id]);

  useEffect(() => {
    if (timeEdited) return;
    setHours(Math.floor(suggestedSeconds / 3600));
    setMinutes(Math.round((suggestedSeconds % 3600) / 60));
    setNotTracked(resolution?.state === "not_tracked");
  }, [resolution?.state, suggestedSeconds, timeEdited]);

  if (!task) return null;

  const submit = async (event) => {
    event.preventDefault();
    setError("");
    const actualSeconds = Math.max(
      0,
      Number(hours || 0) * 3600 + Number(minutes || 0) * 60,
    );
    try {
      await completeTask.mutateAsync({
        id: task.id,
        actualSeconds: noPersonalWork || notTracked ? null : actualSeconds,
        notTracked: noPersonalWork ? false : notTracked,
        noPersonalWork,
        changeComment,
      });
      onClose();
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          err?.message ||
          "Failed to complete task.",
      );
    }
  };

  return (
    <Modal isOpen onClose={onClose} title={`Complete: ${task.name}`}>
      <form
        className="pandat69-form pandat69-completion-form"
        onSubmit={submit}
      >
        <p>
          {specificSeconds > 0
            ? `You have ${Math.round(
                specificSeconds / 60,
              )} minutes of detailed work logged. Confirm your cumulative actual time.`
            : "Record your actual time, or explicitly mark it as not tracked."}
        </p>
        {data?.can_complete_without_personal_time && (
          <label className="pandat69-checkbox-label">
            <input
              type="checkbox"
              checked={noPersonalWork}
              onChange={(event) => {
                setNoPersonalWork(event.target.checked);
                setTimeEdited(true);
                if (event.target.checked) setNotTracked(false);
              }}
            />{" "}
            Complete as supervisor without recording personal work
          </label>
        )}
        {!noPersonalWork && (
          <label className="pandat69-checkbox-label">
            <input
              type="checkbox"
              checked={notTracked}
              onChange={(event) => {
                setTimeEdited(true);
                setNotTracked(event.target.checked);
              }}
            />{" "}
            Not tracked
          </label>
        )}
        {!noPersonalWork && !notTracked && (
          <div className="pandat69-form-row">
            <div className="pandat69-form-field pandat69-form-field-half">
              <label htmlFor="pandat69-completion-hours">Hours</label>
              <input
                id="pandat69-completion-hours"
                className="pandat69-input"
                type="number"
                min="0"
                value={hours}
                onChange={(event) => {
                  setTimeEdited(true);
                  setHours(event.target.value);
                }}
              />
            </div>
            <div className="pandat69-form-field pandat69-form-field-half">
              <label htmlFor="pandat69-completion-minutes">Minutes</label>
              <input
                id="pandat69-completion-minutes"
                className="pandat69-input"
                type="number"
                min="0"
                max="59"
                value={minutes}
                onChange={(event) => {
                  setTimeEdited(true);
                  setMinutes(event.target.value);
                }}
              />
            </div>
          </div>
        )}
        {error && (
          <div className="pandat69-error" role="alert">
            {error}
          </div>
        )}
        <div className="pandat69-form-actions">
          <button type="button" className="pandat69-button" onClick={onClose}>
            Cancel
          </button>
          <button
            type="submit"
            className="pandat69-button pandat69-button-primary"
            disabled={completeTask.isPending}
          >
            {completeTask.isPending ? "Completing…" : "Complete task"}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export const CompletionProvider = ({ children }) => {
  const { features } = useConfig();
  const workLogEnabled = features?.workLog !== false;
  const [request, setRequest] = useState(null);
  const requestCompletion = useCallback((nextTask, options = {}) => {
    setRequest({
      task: nextTask,
      changeComment: options.changeComment || "",
    });
  }, []);
  const value = useMemo(() => ({ requestCompletion }), [requestCompletion]);
  return (
    <CompletionContext.Provider value={value}>
      {children}
      {workLogEnabled && (
        <CompletionDialog
          task={request?.task || null}
          changeComment={request?.changeComment || ""}
          onClose={() => setRequest(null)}
        />
      )}
    </CompletionContext.Provider>
  );
};

export const useTaskStatusTransition = () => {
  const context = useContext(CompletionContext);
  const { features } = useConfig();
  const { updateTask, completeTask } = useTaskMutations();
  if (!context)
    throw new Error(
      "useTaskStatusTransition must be used inside CompletionProvider",
    );
  return {
    isPending: updateTask.isPending || completeTask.isPending,
    setStatus: async (task, status, options = {}) => {
      if (task.status === status) return;
      if (status === "done") {
        if (features?.workLog === false) {
          await completeTask.mutateAsync({
            id: task.id,
            noPersonalWork: true,
            changeComment: options.changeComment || "",
          });
          return;
        }
        context.requestCompletion(task, options);
        return;
      }
      await updateTask.mutateAsync({ id: task.id, data: { status } });
    },
  };
};
