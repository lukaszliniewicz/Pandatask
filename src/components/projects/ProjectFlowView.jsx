import React, { useEffect, useId, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import {
  buildProjectFlowFocus,
  buildProjectFlowModel,
} from "../../projectWorkspaceModel.mjs";
import Icon from "../Icon";

const STATUS_LABELS = {
  pending: "Pending",
  "in-progress": "In progress",
  done: "Done",
  restricted: "Restricted",
};

const ProjectFlowView = ({ dependencies, onTaskAction, tasks }) => {
  const [filter, setFilter] = useState("all");
  const [zoom, setZoom] = useState(1);
  const [isExpanded, setIsExpanded] = useState(false);
  const [isPanning, setIsPanning] = useState(false);
  const [relationFocusKey, setRelationFocusKey] = useState("");
  const expandButtonRef = useRef(null);
  const scrollRef = useRef(null);
  const panRef = useRef(null);
  const restoreFocusRef = useRef(false);
  const markerId = `pandatask-flow-arrow-${useId().replace(
    /[^a-z0-9_-]/gi,
    "",
  )}`;
  const focusedMarkerId = `${markerId}-focused`;
  const model = useMemo(
    () => buildProjectFlowModel(tasks, dependencies, filter),
    [dependencies, filter, tasks],
  );
  const relationFocus = useMemo(
    () => buildProjectFlowFocus(model, relationFocusKey),
    [model, relationFocusKey],
  );
  const hasRelationFocus = relationFocus.taskKeys.size > 0;

  useEffect(() => {
    if (
      relationFocusKey &&
      !model.nodes.some((node) => node.key === relationFocusKey)
    ) {
      setRelationFocusKey("");
    }
  }, [model.nodes, relationFocusKey]);

  useEffect(() => {
    if (!isExpanded) {
      if (restoreFocusRef.current) {
        restoreFocusRef.current = false;
        const frame = window.requestAnimationFrame(() =>
          expandButtonRef.current?.focus(),
        );
        return () => window.cancelAnimationFrame(frame);
      }
      return undefined;
    }
    restoreFocusRef.current = true;
    document.body.classList.add("pandat69-project-canvas-open");
    const frame = window.requestAnimationFrame(() =>
      expandButtonRef.current?.focus(),
    );
    return () => {
      window.cancelAnimationFrame(frame);
      document.body.classList.remove("pandat69-project-canvas-open");
    };
  }, [isExpanded]);

  useEffect(() => {
    if (!isExpanded && !relationFocusKey) {
      return undefined;
    }
    const handleKeyDown = (event) => {
      if (
        event.key !== "Escape" ||
        document.querySelector(".pandat69-react-modal[open]")
      ) {
        return;
      }
      event.preventDefault();
      if (relationFocusKey) {
        setRelationFocusKey("");
      } else {
        setIsExpanded(false);
      }
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [isExpanded, relationFocusKey]);

  const changeZoom = (amount) => {
    setZoom((current) =>
      Math.min(1.4, Math.max(0.65, Number((current + amount).toFixed(2)))),
    );
  };

  const resetViewport = () => {
    setZoom(1);
    if (scrollRef.current) {
      scrollRef.current.scrollLeft = 0;
      scrollRef.current.scrollTop = 0;
    }
  };

  const startPan = (event) => {
    if (
      event.button !== 0 ||
      event.target.closest(
        "button, select, input, a, .pandat69-project-flow-group, .pandat69-project-flow-edges",
      )
    ) {
      return;
    }
    panRef.current = {
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      left: event.currentTarget.scrollLeft,
      top: event.currentTarget.scrollTop,
    };
    event.currentTarget.setPointerCapture?.(event.pointerId);
    setIsPanning(true);
    event.preventDefault();
  };

  const movePan = (event) => {
    const pan = panRef.current;
    if (!pan || pan.pointerId !== event.pointerId) {
      return;
    }
    event.currentTarget.scrollLeft = pan.left - (event.clientX - pan.x);
    event.currentTarget.scrollTop = pan.top - (event.clientY - pan.y);
  };

  const endPan = (event) => {
    if (panRef.current?.pointerId !== event.pointerId) {
      return;
    }
    event.currentTarget.releasePointerCapture?.(event.pointerId);
    panRef.current = null;
    setIsPanning(false);
  };

  const toggleRelationFocus = (key) => {
    setRelationFocusKey((current) => (current === key ? "" : key));
  };

  const renderRelationButton = (node) => (
    <button
      type="button"
      className="pandat69-project-flow-relation-focus"
      onClick={() => toggleRelationFocus(node.key)}
      aria-label={
        relationFocusKey === node.key
          ? `Clear relation focus for ${node.task.name}`
          : `Focus relations for ${node.task.name}`
      }
      aria-pressed={relationFocusKey === node.key}
      title="Highlight this task and its direct relations"
    >
      <Icon name="eye" size={node.isRoot ? 15 : 13} />
    </button>
  );

  const renderRoot = (node) => {
    const task = node.task;
    const status = task.restricted ? "restricted" : task.status;
    const content = (
      <>
        <span className="pandat69-project-flow-node-topline">
          <span className={`pandat69-project-status status-${status}`}>
            {STATUS_LABELS[status] || "Pending"}
          </span>
          {task.origin === "external" && <span>External</span>}
        </span>
        <strong>{task.name}</strong>
        <small>
          {task.restricted
            ? "Details hidden by source permissions"
            : task.project_name || task.board_display_name || "This project"}
          {task.is_blocked ? " · blocked" : ""}
        </small>
      </>
    );
    return (
      <div className="pandat69-project-flow-group-head">
        {task.restricted ? (
          <div
            className="pandat69-project-flow-task-open"
            aria-label="Restricted external task"
          >
            {content}
          </div>
        ) : (
          <button
            type="button"
            className="pandat69-project-flow-task-open"
            onClick={() => onTaskAction("view", task)}
          >
            {content}
          </button>
        )}
        {renderRelationButton(node)}
      </div>
    );
  };

  const renderChild = (node) => {
    const task = node.task;
    const status = task.restricted ? "restricted" : task.status;
    const content = (
      <>
        <span
          className={`pandat69-project-flow-task-dot status-${status}`}
          aria-hidden="true"
        />
        <span className="pandat69-visually-hidden">
          {STATUS_LABELS[status] || "Pending"}:{" "}
        </span>
        <strong>{task.name}</strong>
        {task.is_blocked && <small>Blocked</small>}
      </>
    );
    return (
      <li
        key={node.key}
        className={`${
          hasRelationFocus && !relationFocus.taskKeys.has(node.key)
            ? "is-dimmed"
            : ""
        } ${relationFocusKey === node.key ? "is-relation-focus" : ""}`}
        style={{
          "--pandatask-flow-indent": `${Math.min(node.depth, 5) * 11}px`,
        }}
      >
        <div
          className="pandat69-project-flow-child-branch"
          aria-hidden="true"
        />
        {task.restricted ? (
          <div
            className="pandat69-project-flow-child-open"
            aria-label="Restricted external task"
          >
            {content}
          </div>
        ) : (
          <button
            type="button"
            className="pandat69-project-flow-child-open"
            onClick={() => onTaskAction("view", task)}
          >
            {content}
          </button>
        )}
        {renderRelationButton(node)}
      </li>
    );
  };

  const flow = (
    <section
      className={`pandat69-project-flow ${
        isExpanded
          ? "is-viewport-expanded pandat69-container pandat69-root"
          : ""
      } ${hasRelationFocus ? "has-relation-focus" : ""}`}
      aria-labelledby="pandatask-project-flow-title"
    >
      <header className="pandat69-project-canvas-toolbar">
        <div>
          <h4 id="pandatask-project-flow-title">Dependency flow</h4>
          <p>
            Solid arrows show blockers; nested rows show parent and child
            structure. Drag empty space to pan.
          </p>
        </div>
        <div className="pandat69-project-canvas-controls">
          {hasRelationFocus && (
            <button
              type="button"
              className="pandat69-project-flow-clear-focus"
              onClick={() => setRelationFocusKey("")}
            >
              <Icon name="eye" size={14} /> Clear highlight
            </button>
          )}
          <label>
            <span className="pandat69-visually-hidden">Filter flow tasks</span>
            <select
              value={filter}
              onChange={(event) => setFilter(event.target.value)}
            >
              <option value="all">All tasks</option>
              <option value="open">Open work</option>
              <option value="blocked">Blocked work</option>
            </select>
          </label>
          <div
            className="pandat69-project-zoom-controls"
            aria-label="Flow zoom"
          >
            <button
              type="button"
              onClick={() => changeZoom(-0.1)}
              disabled={zoom <= 0.65}
              aria-label="Zoom out"
            >
              <span aria-hidden="true">−</span>
            </button>
            <button
              type="button"
              onClick={resetViewport}
              aria-label="Reset zoom and pan"
            >
              {Math.round(zoom * 100)}%
            </button>
            <button
              type="button"
              onClick={() => changeZoom(0.1)}
              disabled={zoom >= 1.4}
              aria-label="Zoom in"
            >
              <span aria-hidden="true">+</span>
            </button>
          </div>
          <button
            type="button"
            ref={expandButtonRef}
            className="pandat69-project-canvas-expand"
            onClick={() => setIsExpanded((expanded) => !expanded)}
            aria-label={
              isExpanded
                ? "Exit full-screen flow view"
                : "Open flow in full screen"
            }
            aria-pressed={isExpanded}
          >
            <Icon name={isExpanded ? "minimize" : "maximize"} size={16} />
            {isExpanded ? "Exit full screen" : "Full screen"}
          </button>
        </div>
      </header>

      {model.nodes.length ? (
        <div
          ref={scrollRef}
          className={`pandat69-project-flow-scroll ${
            isPanning ? "is-panning" : ""
          }`}
          tabIndex="0"
          aria-label="Scrollable project dependency diagram. Drag empty space to pan."
          onPointerDown={startPan}
          onPointerMove={movePan}
          onPointerUp={endPan}
          onPointerCancel={endPan}
        >
          <div
            className="pandat69-project-flow-stage"
            style={{
              width: `${model.width * zoom}px`,
              height: `${model.height * zoom}px`,
            }}
          >
            <div
              className="pandat69-project-flow-canvas"
              style={{
                width: `${model.width}px`,
                height: `${model.height}px`,
                transform: `scale(${zoom})`,
              }}
            >
              <svg
                className="pandat69-project-flow-edges"
                width={model.width}
                height={model.height}
                aria-hidden="true"
              >
                <defs>
                  <marker
                    id={markerId}
                    viewBox="0 0 10 10"
                    refX="9"
                    refY="5"
                    markerWidth="7"
                    markerHeight="7"
                    orient="auto-start-reverse"
                  >
                    <path d="M 0 0 L 10 5 L 0 10 z" />
                  </marker>
                  <marker
                    id={focusedMarkerId}
                    className="is-focused"
                    viewBox="0 0 10 10"
                    refX="9"
                    refY="5"
                    markerWidth="7"
                    markerHeight="7"
                    orient="auto-start-reverse"
                  >
                    <path d="M 0 0 L 10 5 L 0 10 z" />
                  </marker>
                </defs>
                {model.edges.map((edge) => {
                  const isFocused = relationFocus.edgeIds.has(edge.id);
                  return (
                    <path
                      key={edge.id}
                      d={edge.path}
                      className={`is-dependency ${
                        edge.isWithinGroup ? "is-within-group" : ""
                      } ${hasRelationFocus && !isFocused ? "is-dimmed" : ""} ${
                        isFocused ? "is-focused" : ""
                      }`}
                      markerEnd={`url(#${
                        isFocused ? focusedMarkerId : markerId
                      })`}
                    />
                  );
                })}
              </svg>

              <ul
                className="pandat69-project-flow-groups"
                aria-label="Project tasks"
              >
                {model.groups.map((group) => {
                  const root = group.members[0];
                  const groupHasFocus = group.members.some((node) =>
                    relationFocus.taskKeys.has(node.key),
                  );
                  return (
                    <li
                      key={group.key}
                      className={`pandat69-project-flow-group status-${
                        root.task.restricted ? "restricted" : root.task.status
                      } ${
                        root.task.origin === "external" ? "is-external" : ""
                      } ${
                        hasRelationFocus && !groupHasFocus ? "is-dimmed" : ""
                      } ${
                        relationFocusKey === root.key ? "is-relation-focus" : ""
                      }`}
                      style={{
                        left: `${group.x}px`,
                        top: `${group.y}px`,
                        width: `${group.width}px`,
                        height: `${group.height}px`,
                      }}
                    >
                      {renderRoot(root)}
                      {group.members.length > 1 && (
                        <ul className="pandat69-project-flow-children">
                          {group.members.slice(1).map(renderChild)}
                        </ul>
                      )}
                    </li>
                  );
                })}
              </ul>
            </div>
          </div>
        </div>
      ) : (
        <div className="pandat69-project-canvas-empty">
          <Icon name="share" size={24} />
          <p>No tasks match this flow filter.</p>
        </div>
      )}
    </section>
  );

  return isExpanded && typeof document !== "undefined"
    ? createPortal(flow, document.body)
    : flow;
};

export default ProjectFlowView;
