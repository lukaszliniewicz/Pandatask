import React, { Suspense } from 'react';
import ArchiveView from '../ArchiveView';
import OverviewView from '../OverviewView';
import ProjectsView from '../ProjectsView';
import TaskWorkspace from '../TaskWorkspace';
import { lazyWithRetry } from '../../utils/lazyWithRetry';

const ReportView = lazyWithRetry(() => import('../ReportView'));
const WorkLogView = lazyWithRetry(() => import('../WorkLogView'));
const InboxView = lazyWithRetry(() => import('../InboxView'));

const BoardContent = ({ controller }) => (
    <div className="pandat69-tabs">
        <div
            id="pandatask-current-tabpanel"
            role="tabpanel"
            aria-labelledby={`pandatask-${controller.currentTab}-tab`}
            className={`pandat69-tab-content pandat69-tab-${controller.currentTab} active`}
        >
            {controller.currentTab === 'tasks' && (
                <TaskWorkspace
                    filters={controller.filters}
                    onFilterChange={controller.setFilter}
                    currentView={controller.currentView}
                    allSubtasksExpanded={controller.allSubtasksExpanded}
                    onToggleSubtasks={controller.toggleAllSubtasks}
                    groupByProject={controller.groupByProject}
                    onToggleProjectGrouping={controller.toggleProjectGrouping}
                    tasks={controller.data}
                    isLoading={controller.isLoading}
                    isError={controller.isError}
                    error={controller.error}
                    onTaskAction={controller.handleTaskAction}
                />
            )}
            {controller.currentTab === 'projects' && (
                <ProjectsView
                    onEditProject={controller.openProjectDialog}
                    onTaskAction={controller.handleTaskAction}
                    privateOnly={controller.isUserBoard && controller.filters.onlyMyTasks}
                />
            )}
            {controller.currentTab === 'archive' && <ArchiveView onTaskAction={controller.handleTaskAction} />}
            {controller.currentTab === 'overview' && <OverviewView onTaskAction={controller.handleTaskAction} />}
            {controller.currentTab === 'inbox' && controller.isUserBoard && (
                <Suspense
                    fallback={
                        <div className="pandat69-loading" role="status">
                            Loading Inbox…
                        </div>
                    }
                >
                    <InboxView onOpenTask={controller.openTask} />
                </Suspense>
            )}
            {controller.currentTab === 'work' && controller.isUserBoard && controller.workLogEnabled && (
                <Suspense
                    fallback={
                        <div className="pandat69-loading" role="status">
                            Loading work log…
                        </div>
                    }
                >
                    <WorkLogView
                        onLogWork={controller.openWorkDialog}
                        onManageWorkTypes={controller.openWorkTypesDialog}
                        onOpenTask={controller.openTask}
                    />
                </Suspense>
            )}
            {controller.currentTab === 'report' && (
                <Suspense
                    fallback={
                        <div className="pandat69-loading" role="status">
                            Loading report…
                        </div>
                    }
                >
                    <ReportView
                        onLogWork={controller.openWorkDialog}
                        onOpenTask={controller.openTask}
                        workLogEnabled={controller.workLogEnabled}
                    />
                </Suspense>
            )}
        </div>
    </div>
);

export default BoardContent;
