import React, { Suspense, useMemo, useState } from "react";
import { useProjectReferenceMutations } from "../../hooks/useProjectReferenceMutations";
import { useProjectWorkspace } from "../../hooks/useProjectWorkspace";
import {
  PROJECT_WORKSPACE_VIEWS,
  toProjectVisualTasks,
} from "../../projectWorkspaceModel.mjs";
import { lazyWithRetry } from "../../utils/lazyWithRetry";
import Icon from "../Icon";
import ProjectFlowView from "./ProjectFlowView";
import ProjectReferenceDialog from "./ProjectReferenceDialog";
import ProjectWorkspaceList from "./ProjectWorkspaceList";

const GanttView = lazyWithRetry(() => import("../GanttView"));

const ProjectWorkspace = ({
  currentView,
  onBack,
  onEditProject,
  onTaskAction,
  onViewChange,
  projectId,
}) => {
  const workspaceQuery = useProjectWorkspace(projectId);
  const referenceMutations = useProjectReferenceMutations(projectId);
  const [isDescriptionExpanded, setIsDescriptionExpanded] = useState(false);
  const [isReferenceDialogOpen, setIsReferenceDialogOpen] = useState(false);
  const workspace = workspaceQuery.data;
  const project = workspace?.project;
  const tasks = workspace?.tasks || [];
  const references = workspace?.references || [];
  const dependencies = workspace?.dependencies || [];
  const visualTasks = useMemo(() => toProjectVisualTasks(tasks), [tasks]);
  const canManage = project?.can_manage !== false;

  const handleTaskAction = (action, task) => {
    if (task.restricted || (!task.task_id && !task.canonical_task_id)) {
      return;
    }
    onTaskAction(action, {
      ...task,
      id: task.task_id || task.canonical_task_id,
    });
  };

  if (workspaceQuery.isLoading) {
    return <div className="pandat69-loading">Opening project workspace…</div>;
  }
  if (workspaceQuery.isError || !project) {
    return (
      <div className="pandat69-project-workspace-error" role="alert">
        <h3>This project workspace could not be opened.</h3>
        <p>
          {workspaceQuery.error?.message || "The project may no longer exist."}
        </p>
        <button type="button" className="pandat69-button" onClick={onBack}>
          Back to projects
        </button>
      </div>
    );
  }

  return (
    <section
      className="pandat69-project-workspace"
      aria-labelledby="pandatask-project-workspace-title"
    >
      <header className="pandat69-project-workspace-header">
        <div className="pandat69-project-workspace-breadcrumb">
          <button type="button" onClick={onBack}>
            <Icon name="chevron-left" size={15} /> All projects
          </button>
        </div>
        <div className="pandat69-project-workspace-heading">
          <div className="pandat69-project-workspace-title">
            <p className="pandat69-eyebrow">Project workspace</p>
            <h3 id="pandatask-project-workspace-title">{project.name}</h3>
            <div className="pandat69-project-workspace-meta">
              {project.board_display_name && (
                <span>
                  <Icon name="users" size={13} /> {project.board_display_name}
                </span>
              )}
              <span className={project.deadline ? "" : "is-muted"}>
                <Icon name="calendar" size={13} />
                {project.deadline
                  ? `Due ${project.deadline}`
                  : "No project deadline"}
              </span>
              <span>
                {workspace.counts?.native || 0} native ·{" "}
                {workspace.counts?.external || 0} external
              </span>
            </div>
          </div>
          {canManage && (
            <div className="pandat69-project-workspace-actions">
              <button
                type="button"
                className="pandat69-button secondary"
                onClick={() => onEditProject(project)}
              >
                <Icon name="pencil" size={15} /> Edit project
              </button>
              <button
                type="button"
                className="pandat69-button"
                onClick={() => setIsReferenceDialogOpen(true)}
              >
                <Icon name="link" size={15} /> Add reference
              </button>
            </div>
          )}
        </div>

        {project.description && (
          <div
            className={`pandat69-project-workspace-description ${
              isDescriptionExpanded ? "is-expanded" : ""
            }`}
          >
            <p>{project.description}</p>
            <button
              type="button"
              onClick={() => setIsDescriptionExpanded((expanded) => !expanded)}
              aria-expanded={isDescriptionExpanded}
            >
              {isDescriptionExpanded ? "Show less" : "Show full overview"}
              <Icon
                name={isDescriptionExpanded ? "chevron-up" : "chevron-down"}
                size={14}
              />
            </button>
          </div>
        )}
      </header>

      <nav className="pandat69-project-view-nav" aria-label="Project views">
        <div role="tablist">
          {PROJECT_WORKSPACE_VIEWS.map((view) => (
            <button
              type="button"
              key={view.id}
              id={`pandatask-project-${view.id}-tab`}
              role="tab"
              aria-selected={currentView === view.id}
              aria-controls="pandatask-project-view-panel"
              className={currentView === view.id ? "is-active" : ""}
              onClick={() => onViewChange(view.id)}
            >
              <Icon name={view.icon} size={16} /> {view.label}
            </button>
          ))}
        </div>
        <p>
          {currentView === "list" &&
            "The detailed project record and its external context."}
          {currentView === "flow" &&
            "Dependencies and hierarchy on a pannable canvas."}
          {currentView === "timeline" &&
            "Dated work with unscheduled tasks kept visible."}
        </p>
      </nav>

      <div
        id="pandatask-project-view-panel"
        role="tabpanel"
        aria-labelledby={`pandatask-project-${currentView}-tab`}
        className={`pandat69-project-view-panel view-${currentView}`}
      >
        {currentView === "list" && (
          <ProjectWorkspaceList
            canManage={canManage}
            onTaskAction={handleTaskAction}
            referenceMutations={referenceMutations}
            references={references}
            tasks={tasks}
          />
        )}
        {currentView === "flow" && (
          <ProjectFlowView
            dependencies={dependencies}
            onTaskAction={handleTaskAction}
            tasks={tasks}
          />
        )}
        {currentView === "timeline" && (
          <Suspense
            fallback={<div className="pandat69-loading">Loading timeline…</div>}
          >
            <GanttView tasks={visualTasks} onTaskAction={handleTaskAction} />
          </Suspense>
        )}
      </div>

      <ProjectReferenceDialog
        isOpen={isReferenceDialogOpen}
        onClose={() => setIsReferenceDialogOpen(false)}
        onSubmit={(data) => referenceMutations.addReference.mutateAsync(data)}
        project={project}
        tasks={tasks}
        references={references}
        isSaving={referenceMutations.addReference.isPending}
      />
    </section>
  );
};

export default ProjectWorkspace;
