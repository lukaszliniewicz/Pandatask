import React, { Suspense, useEffect, useState, useMemo, useRef } from 'react';
import { createPortal } from 'react-dom';
import Header from './Header';
import OverviewView from './OverviewView';
import ArchiveView from './ArchiveView';
import ProjectsView from './ProjectsView';
import ProjectSidebar from './ProjectSidebar';
import TaskWorkspace from './TaskWorkspace';
import BoardModals from './BoardModals';
import { useTasks } from '../hooks/useTasks';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { generateGCalUrl } from '../utils';
import { useConfig } from '../context/ConfigContext';
import { useBoardNavigation } from '../hooks/useBoardNavigation';
import { useContainerMode } from '../hooks/useContainerMode';
import { lazyWithRetry } from '../utils/lazyWithRetry';

const ReportView = lazyWithRetry(() => import('./ReportView'));
const LoadingChunk = () => <div className="pandat69-loading">Loading...</div>;

const Layout = () => {
    const {
        currentTab,
        currentView,
        selectedTaskId,
        isDetailModalOpen,
        setCurrentTab,
        setCurrentView,
        openTask,
        closeTask,
    } = useBoardNavigation();
    const { containerRef, isContainerNarrow } = useContainerMode();
    const [allSubtasksExpanded, setAllSubtasksExpanded] = useState(false);
    const [isSidebarOpen, setIsSidebarOpen] = useState(() => window.innerWidth >= 1080);
    const [isTaskModalOpen, setIsTaskModalOpen] = useState(false);
    const [isProjectModalOpen, setIsProjectModalOpen] = useState(false);
    const [isCategoryModalOpen, setIsCategoryModalOpen] = useState(false);
    const [isRecurringDeleteModalOpen, setIsRecurringDeleteModalOpen] = useState(false);
    const [isFullscreen, setIsFullscreen] = useState(false);
    const fullscreenToggleRef = useRef(null);
    
    // State for modal content
    const [taskToDelete, setTaskToDelete] = useState(null);
    const [editingTask, setEditingTask] = useState(null);
    const [editingProject, setEditingProject] = useState(null);
    const [taskFormDefaults, setTaskFormDefaults] = useState({});

    const { text } = useConfig(); // Get localized text

    useEffect(() => {
        if (isContainerNarrow) {
            setIsSidebarOpen(false);
        }
    }, [isContainerNarrow]);

    // Mutations
    const { deleteTask, updateTask } = useTaskMutations();

    const { boardName } = useConfig();
    const isUserBoard = boardName?.startsWith('user_');

    const handleFullscreen = () => {
        setIsFullscreen((isActive) => !isActive);
    };

    useEffect(() => {
        if (!isFullscreen) return undefined;

        document.body.classList.add('pandat69-viewport-open');
        const toggleElement = fullscreenToggleRef.current;

        const handleKeyDown = (event) => {
            if (event.key !== 'Escape' || document.querySelector('.pandat69-react-modal.active')) {
                return;
            }

            event.preventDefault();
            setIsFullscreen(false);
        };

        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            window.requestAnimationFrame(() => {
                if (!document.querySelector('.pandat69-viewport-shell')) {
                    document.body.classList.remove('pandat69-viewport-open');
                }

                toggleElement?.focus();
            });
        };
    }, [isFullscreen]);
    
    // Filter State
    const [filters, setFilters] = useState({
        search: '',
        sort: 'deadline_asc',
        status: 'pending_in-progress',
        project: 'all',
        onlyMyTasks: false,
        archived: false
    });

    // Compute active filters based on view.
    // Kanban and Gantt need the complete status set. Gantt handles completed
    // context locally so dependency chains remain intelligible.
    const activeFilters = useMemo(() => {
        if (currentTab === 'tasks' && ['kanban', 'gantt'].includes(currentView)) {
            return { ...filters, status: '' };
        }
        return filters;
    }, [filters, currentView, currentTab]);

    // Data Fetching
    const { data: tasks, isLoading, isError, error } = useTasks(activeFilters);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({
            ...prev,
            [key]: value,
            ...(isUserBoard && key === 'onlyMyTasks' && value ? { project: 'all' } : {}),
        }));
    };

    const handleAddTask = () => {
        setEditingTask(null);
        setIsTaskModalOpen(true);
    };

    const handleManageCategories = () => {
        setIsCategoryModalOpen(true);
    };

    const handleCloseModal = () => {
        setIsTaskModalOpen(false);
        if (isDetailModalOpen) {
            closeTask();
        }
        setIsProjectModalOpen(false);
        setIsCategoryModalOpen(false);
        setIsRecurringDeleteModalOpen(false);
        setEditingTask(null);
        setEditingProject(null);
        setTaskToDelete(null);
        setTaskFormDefaults({});
    };

    const handleRecurringDeleteConfirm = async (scope) => {
        if (!taskToDelete) return;
        try {
            await deleteTask.mutateAsync({ id: taskToDelete.id, scope });
            handleCloseModal();
        } catch (err) {
            alert('Failed to delete task.');
        }
    };


    const handleTaskAction = async (action, task) => {
        let taskId = task.id;
        // Handle virtual recurrence instances
        if (typeof taskId === 'string' && taskId.startsWith('virtual-')) {
            const parts = taskId.split('-');
            if (parts.length >= 2) {
                taskId = parseInt(parts[1], 10);
            }
        }

        if (action === 'view') {
            openTask(taskId);
        } else if (action === 'edit') {
            setEditingTask(task);
            setIsTaskModalOpen(true);
        } else if (action === 'add-subtask') {
            setTaskFormDefaults({
                parent_task_id: taskId,
                project_id: task.project_id || '',
            });
            setEditingTask(null);
            setIsTaskModalOpen(true);
        } else if (action === 'gcal-export') {
            const url = generateGCalUrl(task);
            if (url) window.open(url, '_blank', 'noopener,noreferrer');
        } else if (action === 'delete') {
            if (task.is_recurring == 1) {
                setTaskToDelete(task);
                setIsRecurringDeleteModalOpen(true);
            } else {
                const confirmMsg = text.confirm_delete_task || `Are you sure you want to delete "${task.name}"?`;
                if (confirm(confirmMsg)) {
                    try {
                        await deleteTask.mutateAsync({ id: task.id });
                    } catch (err) {
                        alert('Failed to delete task.');
                    }
                }
            }
        } else if (action === 'archive') {
            if (confirm(`Are you sure you want to archive "${task.name}"?`)) {
                try {
                    await updateTask.mutateAsync({ id: task.id, data: { archived: 1 } });
                } catch (err) {
                    alert('Failed to archive task.');
                }
            }
        } else if (action === 'unarchive') {
            if (confirm(`Are you sure you want to unarchive "${task.name}"?`)) {
                try {
                    await updateTask.mutateAsync({ id: task.id, data: { archived: 0 } });
                } catch (err) {
                    alert('Failed to unarchive task.');
                }
            }
        }
    };

    const handleAddSubtask = (parentId, projectId = '') => {
        setTaskFormDefaults({
            parent_task_id: parentId,
            project_id: projectId || '',
        });
        setEditingTask(null);
        closeTask({ replace: true });
        setIsTaskModalOpen(true);
    };

    const handleNavigateTask = (taskId) => {
        openTask(taskId, { replace: true });
    };

    const handleEditProject = (project) => {
        setEditingProject(project);
        setIsProjectModalOpen(true);
    };

    const toggleSidebar = () => setIsSidebarOpen((isOpen) => !isOpen);

    const board = (
        <div
            ref={containerRef}
            className={`pandat69-container ${isFullscreen ? 'pandat69-viewport-shell' : ''} ${isContainerNarrow ? 'is-container-narrow' : 'is-container-wide'}`}
            data-pandatask-viewport={isFullscreen ? 'active' : 'inline'}
            data-pandatask-container-mode={isContainerNarrow ? 'compact' : 'wide'}
        >
            <Header 
                onAddTask={handleAddTask} 
                onManageCategories={handleManageCategories}
                onFullscreen={handleFullscreen}
                isFullscreen={isFullscreen}
                fullscreenToggleRef={fullscreenToggleRef}
                currentView={currentView}
                onViewChange={setCurrentView}
                toggleSidebar={toggleSidebar}
                isSidebarOpen={isSidebarOpen}
            />
            
            <div className="pandat69-layout-body">
                <ProjectSidebar 
                    isOpen={isSidebarOpen}
                    toggleSidebar={toggleSidebar}
                    isMobile={isContainerNarrow}
                    selectedProjectId={filters.project}
                    onSelectProject={(pid) => handleFilterChange('project', pid)}
                    onClose={() => setIsSidebarOpen(false)}
                    onAddProject={() => handleEditProject(null)}
                    currentTab={currentTab}
                    onTabChange={setCurrentTab}
                    currentView={currentView}
                    onViewChange={setCurrentView}
                    privateOnly={isUserBoard && filters.onlyMyTasks}
                />

                <div className="pandat69-main-content">
                    <div className="pandat69-desktop-nav">
                        <ul className="pandat69-tab-navigation">
                            {[
                                { id: 'tasks', label: 'All Tasks' },
                                { id: 'projects', label: 'Projects' },
                                { id: 'overview', label: 'Overview' },
                                { id: 'archive', label: 'Archive' },
                                { id: 'report', label: 'Report' },
                            ].map(tab => (
                                <li key={tab.id}>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={currentTab === tab.id}
                                        className={`pandat69-tab-item ${currentTab === tab.id ? 'active' : ''}`}
                                        onClick={() => setCurrentTab(tab.id)}
                                    >
                                        {tab.label}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="pandat69-tabs">
                        <div className={`pandat69-tab-content pandat69-tab-${currentTab} active`}>
                            {currentTab === 'tasks' && (
                                <TaskWorkspace
                                    filters={filters}
                                    onFilterChange={handleFilterChange}
                                    currentView={currentView}
                                    allSubtasksExpanded={allSubtasksExpanded}
                                    onToggleSubtasks={() => setAllSubtasksExpanded((isExpanded) => !isExpanded)}
                                    tasks={tasks}
                                    isLoading={isLoading}
                                    isError={isError}
                                    error={error}
                                    onTaskAction={handleTaskAction}
                                />
                            )}

                            {currentTab === 'projects' && (
                                <ProjectsView
                                    onEditProject={handleEditProject}
                                    onTaskAction={handleTaskAction}
                                    privateOnly={isUserBoard && filters.onlyMyTasks}
                                />
                            )}

                            {currentTab === 'archive' && (
                                <ArchiveView onTaskAction={handleTaskAction} />
                            )}
                            
                            {currentTab === 'overview' && (
                                <OverviewView onTaskAction={handleTaskAction} />
                            )}

                            {currentTab === 'report' && (
                                <Suspense fallback={<LoadingChunk />}>
                                    <ReportView />
                                </Suspense>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <BoardModals
                isTaskModalOpen={isTaskModalOpen}
                isDetailModalOpen={isDetailModalOpen}
                isProjectModalOpen={isProjectModalOpen}
                isCategoryModalOpen={isCategoryModalOpen}
                isRecurringDeleteModalOpen={isRecurringDeleteModalOpen}
                onClose={handleCloseModal}
                editingTask={editingTask}
                taskFormDefaults={taskFormDefaults}
                selectedTaskId={selectedTaskId}
                onTaskAction={handleTaskAction}
                onAddSubtask={handleAddSubtask}
                onNavigateTask={handleNavigateTask}
                editingProject={editingProject}
                onRecurringDeleteConfirm={handleRecurringDeleteConfirm}
            />
        </div>
    );

    return isFullscreen ? createPortal(board, document.body) : board;
};

export default Layout;
