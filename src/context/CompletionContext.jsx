import React, {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
} from "react";
import { useTaskMutations } from "../hooks/useTaskMutations";
import { useConfig } from "./ConfigContext";

const CompletionContext = createContext(null);
const CompletionDialog = React.lazy(() =>
  import("../components/TaskCompletionDialog"),
);

const ReopenDialog = React.lazy(() => import("../components/ReopenTaskDialog"));

export const CompletionProvider = ({ children }) => {
  const { features } = useConfig();
  const workLogEnabled = features?.workLog !== false;
  const [request, setRequest] = useState(null);
  const requestTransition = useCallback((kind, nextTask, options = {}) => {
    setRequest({
      kind,
      task: nextTask,
      ...(kind === "complete"
        ? { changeComment: options.changeComment || "" }
        : { targetStatus: options.status || "in-progress" }),
    });
  }, []);
  const value = useMemo(() => ({ requestTransition }), [requestTransition]);
  return (
    <CompletionContext.Provider value={value}>
      {children}
      {workLogEnabled && request?.kind === "complete" && (
        <React.Suspense
          fallback={
            <div className="pandat69-loading" role="status" aria-live="polite">
              Loading completion options…
            </div>
          }
        >
          <CompletionDialog
            task={request?.task || null}
            changeComment={request?.changeComment || ""}
            onClose={() => setRequest(null)}
          />
        </React.Suspense>
      )}
      {request?.kind === "reopen" && (
        <React.Suspense fallback={null}>
          <ReopenDialog
            task={request?.task || null}
            targetStatus={request?.targetStatus || "in-progress"}
            onClose={() => setRequest(null)}
          />
        </React.Suspense>
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
        context.requestTransition("complete", task, options);
        return;
      }
      if (task.status === "done") {
        context.requestTransition("reopen", task, { status });
        return;
      }
      await updateTask.mutateAsync({ id: task.id, data: { status } });
    },
  };
};
