import React, { useEffect, useId, useMemo, useState } from "react";
import { useDebouncedValue } from "../../hooks/useDebouncedValue";
import { useVisibleTaskSearch } from "../../hooks/useVisibleTaskSearch";
import Icon from "../Icon";
import Modal from "../Modal";

const RELATION_HELP = {
  included:
    "Show the task in this project while its canonical home stays unchanged.",
  dependency:
    "Use an external task as a real predecessor that blocks a task in this project.",
  related:
    "Keep a contextual link in the project list, hidden from Flow and Timeline.",
};

const ProjectReferenceDialog = ({
  isOpen,
  onClose,
  onSubmit,
  project,
  tasks,
  references,
  isSaving,
}) => {
  const [relationType, setRelationType] = useState("included");
  const [search, setSearch] = useState("");
  const [selectedTask, setSelectedTask] = useState(null);
  const [successorTaskId, setSuccessorTaskId] = useState("");
  const [error, setError] = useState("");
  const searchId = useId();
  const relationId = useId();
  const successorId = useId();
  const debouncedSearch = useDebouncedValue(search, 250);
  const searchQuery = useVisibleTaskSearch(debouncedSearch);
  const nativeTasks = useMemo(
    () =>
      (tasks || []).filter(
        (task) =>
          task.origin === "native" &&
          !task.restricted &&
          task.status !== "done",
      ),
    [tasks],
  );
  const associatedTaskIds = useMemo(
    () =>
      new Set(
        (references || [])
          .filter((reference) =>
            ["included", "related"].includes(reference.relation_type),
          )
          .map((reference) => Number(reference.task_id))
          .filter(Number.isInteger),
      ),
    [references],
  );
  const results = useMemo(
    () =>
      (searchQuery.data || []).filter((task) => {
        if (Number(task.project_id) === Number(project.id)) {
          return false;
        }
        if (
          relationType !== "dependency" &&
          associatedTaskIds.has(Number(task.id))
        ) {
          return false;
        }
        if (relationType === "dependency" && successorTaskId) {
          const successor = nativeTasks.find(
            (taskNode) => Number(taskNode.task_id) === Number(successorTaskId),
          );
          return !(successor?.predecessor_keys || []).includes(
            `task-${task.id}`,
          );
        }
        return true;
      }),
    [
      associatedTaskIds,
      nativeTasks,
      project.id,
      relationType,
      searchQuery.data,
      successorTaskId,
    ],
  );

  useEffect(() => {
    if (!isOpen) {
      return;
    }
    setRelationType("included");
    setSearch("");
    setSelectedTask(null);
    setSuccessorTaskId(nativeTasks[0]?.task_id || "");
    setError("");
  }, [isOpen, nativeTasks]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (!selectedTask) {
      setError("Choose a task to reference.");
      return;
    }
    if (relationType === "dependency" && !successorTaskId) {
      setError(
        "Choose the project task that should wait for this predecessor.",
      );
      return;
    }

    const payload =
      relationType === "dependency"
        ? {
            relation_type: relationType,
            predecessor_task_id: Number(selectedTask.id),
            successor_task_id: Number(successorTaskId),
          }
        : {
            relation_type: relationType,
            task_id: Number(selectedTask.id),
          };

    try {
      setError("");
      await onSubmit(payload);
      onClose();
    } catch (mutationError) {
      setError(mutationError?.message || "The reference could not be added.");
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Add project reference">
      <form className="pandat69-project-reference-form" onSubmit={handleSubmit}>
        <div className="pandat69-form-group">
          <label htmlFor={relationId}>Relationship</label>
          <select
            id={relationId}
            value={relationType}
            onChange={(event) => {
              setRelationType(event.target.value);
              setSelectedTask(null);
              setError("");
            }}
          >
            <option value="included">Included in this project</option>
            <option value="dependency">External predecessor</option>
            <option value="related">Related context</option>
          </select>
          <p className="pandat69-field-help">{RELATION_HELP[relationType]}</p>
        </div>

        {relationType === "dependency" && (
          <div className="pandat69-form-group">
            <label htmlFor={successorId}>Blocks this project task</label>
            <select
              id={successorId}
              value={successorTaskId}
              onChange={(event) => setSuccessorTaskId(event.target.value)}
              required
            >
              <option value="">Choose a project task</option>
              {nativeTasks.map((task) => (
                <option key={task.workspace_key} value={task.task_id}>
                  {task.name}
                </option>
              ))}
            </select>
          </div>
        )}

        <div className="pandat69-form-group">
          <label htmlFor={searchId}>Find a task across your boards</label>
          {selectedTask ? (
            <div className="pandat69-project-reference-selection">
              <div>
                <strong>{selectedTask.name}</strong>
                <span>
                  {selectedTask.project_name ||
                    selectedTask.board_display_name ||
                    selectedTask.board_name}
                </span>
              </div>
              <button
                type="button"
                onClick={() => setSelectedTask(null)}
                aria-label={`Choose a different task instead of ${selectedTask.name}`}
              >
                <Icon name="x" size={16} />
              </button>
            </div>
          ) : (
            <>
              <input
                id={searchId}
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Type at least two characters…"
                autoComplete="off"
              />
              {debouncedSearch.trim().length >= 2 && (
                <ul
                  className="pandat69-project-reference-results"
                  aria-label="Matching tasks"
                >
                  {searchQuery.isLoading && (
                    <li className="is-message">Searching…</li>
                  )}
                  {searchQuery.isError && (
                    <li className="is-message" role="alert">
                      Task search is temporarily unavailable.
                    </li>
                  )}
                  {!searchQuery.isLoading &&
                    !searchQuery.isError &&
                    !results.length && (
                      <li className="is-message">No eligible tasks found.</li>
                    )}
                  {results.map((task) => (
                    <li key={task.id}>
                      <button
                        type="button"
                        onClick={() => setSelectedTask(task)}
                      >
                        <strong>{task.name}</strong>
                        <span>
                          {task.project_name ||
                            task.board_display_name ||
                            task.board_name}
                          · {task.status}
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>

        {error && (
          <div className="pandat69-error" role="alert">
            {error}
          </div>
        )}
        <div className="pandat69-form-actions">
          <button
            type="button"
            className="pandat69-button secondary"
            onClick={onClose}
          >
            Cancel
          </button>
          <button type="submit" className="pandat69-button" disabled={isSaving}>
            {isSaving ? "Adding…" : "Add reference"}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default ProjectReferenceDialog;
