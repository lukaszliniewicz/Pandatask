import React, { lazy, Suspense, useEffect, useState, useMemo } from 'react';
import Header from './Header';
import ViewSwitcher from './ViewSwitcher';
import FilterBar from './FilterBar';
import TaskList from './TaskList';
import CompactListView from './CompactListView';
import KanbanView from './KanbanView';
import CalendarView from './CalendarView';
import OverviewView from './OverviewView';
import ArchiveView from './ArchiveView';
import ProjectsView from './ProjectsView';
import CategoryManager from './CategoryManager';
import Modal from './Modal';
import ProjectSidebar from './ProjectSidebar';
import RecurringDeleteModal from './RecurringDeleteModal';
import { useTasks } from '../hooks/useTasks';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { generateGCalUrl } from '../utils';
import { useConfig } from '../context/ConfigContext';

const ProjectForm = lazy(() => import('./ProjectForm'));
const ReportView = lazy(() => import('./ReportView'));
const TaskDetail = lazy(() => import('./TaskDetail'));
const TaskForm = lazy(() => import('./TaskForm'));
const LoadingChunk = () => <div className="pandat69-loading">Loading...</div>;

const Layout = () => {
    const [currentTab, setCurrentTab] = useState('tasks');
    const [currentView, setCurrentView] = useState('compact');
    const [allSubtasksExpanded, setAllSubtasksExpanded] = useState(false);
    const [isSidebarOpen, setIsSidebarOpen] = useState(false);
    const [isMobile, setIsMobile] = useState(window.innerWidth <= 768);
    const [isTaskModalOpen, setIsTaskModalOpen] = useState(false);
    const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);
    const [isProjectModalOpen, setIsProjectModalOpen] = useState(false);
    const [isCategoryModalOpen, setIsCategoryModalOpen] = useState(false);
    const [isRecurringDeleteModalOpen, setIsRecurringDeleteModalOpen] = useState(false);
    
    // State for modal content
    const [selectedTaskId, setSelectedTaskId] = useState(null);
    const [taskToDelete, setTaskToDelete] = useState(null);
    const [editingTask, setEditingTask] = useState(null);
    const [editingProject, setEditingProject] = useState(null);
    const [taskFormDefaults, setTaskFormDefaults] = useState({});

    const { text } = useConfig(); // Get localized text

    // Resize handler to switch modes automatically
    useEffect(() => {
        const handleResize = () => {
            const mobile = window.innerWidth <= 768;
            setIsMobile(mobile);
            if (mobile) setIsSidebarOpen(false);
        };
        window.addEventListener('resize', handleResize);
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    // Deep Linking: Check for open_task param on mount
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const taskParam = params.get('open_task');
        if (taskParam) {
            const taskId = parseInt(taskParam, 10);
            if (!isNaN(taskId)) {
                setSelectedTaskId(taskId);
                setIsDetailModalOpen(true);
            }
            params.delete('open_task');
            const remainingQuery = params.toString();
            const newUrl = `${window.location.pathname}${remainingQuery ? `?${remainingQuery}` : ''}${window.location.hash}`;
            window.history.replaceState({}, document.title, newUrl);
        }
    }, []);

    // Mutations
    const { deleteTask, updateTask } = useTaskMutations();

    const { boardName } = useConfig();

    // FIX: Implement Fullscreen Redirection
    const handleFullscreen = () => {
        const params = new URLSearchParams();
        params.append('board_name', boardName);

        const siteUrl = window.pandatask_api_settings?.home_url || '/';
        const baseUrl = siteUrl.replace(/\/$/, '');

        window.location.href = `${baseUrl}/pandatask-fullscreen/?${params.toString()}`;
    };
    
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
    // Kanban view needs to see ALL statuses to distribute tasks into columns.
    const activeFilters = useMemo(() => {
        if (currentTab === 'tasks' && currentView === 'kanban') {
            return { ...filters, status: '' };
        }
        return filters;
    }, [filters, currentView, currentTab]);

    // Data Fetching
    const { data: tasks, isLoading, isError, error } = useTasks(activeFilters);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
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
        setIsDetailModalOpen(false);
        setIsProjectModalOpen(false);
        setIsCategoryModalOpen(false);
        setIsRecurringDeleteModalOpen(false);
        setEditingTask(null);
        setEditingProject(null);
        setSelectedTaskId(null);
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
            setSelectedTaskId(taskId);
            setIsDetailModalOpen(true);
        } else if (action === 'edit') {
            setEditingTask(task);
            setIsTaskModalOpen(true);
        } else if (action === 'add-subtask') {
            setTaskFormDefaults({ parent_task_id: taskId });
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

    const handleAddSubtask = (parentId) => {
        setTaskFormDefaults({ parent_task_id: parentId });
        setEditingTask(null);
        setIsDetailModalOpen(false);
        setIsTaskModalOpen(true);
    };

    const handleNavigateTask = (taskId) => {
        setSelectedTaskId(taskId);
        setIsDetailModalOpen(true);
    };

    const handleEditProject = (project) => {
        setEditingProject(project);
        setIsProjectModalOpen(true);
    };

    const toggleSidebar = () => setIsSidebarOpen((isOpen) => !isOpen);

    return (
        <div className="pandat69-container">
            <Header 
                onAddTask={handleAddTask} 
                onManageCategories={handleManageCategories}
                onFullscreen={handleFullscreen}
                currentView={currentView}
                onViewChange={setCurrentView}
                toggleSidebar={toggleSidebar}
            />
            
            <div className="pandat69-layout-body">
                <ProjectSidebar 
                    isOpen={isSidebarOpen}
                    toggleSidebar={toggleSidebar}
                    isMobile={isMobile}
                    selectedProjectId={filters.project}
                    onSelectProject={(pid) => handleFilterChange('project', pid)}
                    onClose={() => setIsSidebarOpen(false)}
                    onAddProject={() => handleEditProject(null)}
                    currentTab={currentTab}
                    onTabChange={setCurrentTab}
                    currentView={currentView}
                    onViewChange={setCurrentView}
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
                                <>
                                    <FilterBar 
                                        filters={filters} 
                                        onFilterChange={handleFilterChange} 
                                        hideProjectSelect={true}
                                        showSubtaskToggle={currentView === 'compact'}
                                        allSubtasksExpanded={allSubtasksExpanded}
                                        onToggleSubtasks={() => setAllSubtasksExpanded((isExpanded) => !isExpanded)}
                                    />
                                    
                                    {isLoading && <div className="pandat69-loading">Loading...</div>}
                                    {isError && <div className="pandat69-error">Error: {error.message}</div>}
                                    
                                    {!isLoading && !isError && currentView === 'compact' && (
                                        <CompactListView 
                                            tasks={tasks} 
                                            onTaskAction={handleTaskAction}
                                            allSubtasksExpanded={allSubtasksExpanded}
                                        />
                                    )}

                                    {!isLoading && !isError && currentView === 'list' && (
                                        <TaskList tasks={tasks} onTaskAction={handleTaskAction} />
                                    )}

                                    {!isLoading && !isError && currentView === 'kanban' && (
                                        <div className="pandat69-view-container pandat69-kanban-view active">
                                            <KanbanView tasks={tasks} onTaskAction={handleTaskAction} />
                                        </div>
                                    )}
                                    
                                    {!isLoading && !isError && currentView === 'calendar' && (
                                        <CalendarView tasks={tasks} onTaskAction={handleTaskAction} />
                                    )}
                                </>
                            )}

                            {currentTab === 'projects' && (
                                <ProjectsView onEditProject={handleEditProject} onTaskAction={handleTaskAction} />
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

            {/* Task Form Modal */}
            <Modal 
                isOpen={isTaskModalOpen} 
                onClose={handleCloseModal} 
                title={editingTask ? 'Edit Task' : (taskFormDefaults.parent_task_id ? 'Add Subtask' : 'Add New Task')}
            >
                <Suspense fallback={<LoadingChunk />}>
                    <TaskForm
                        task={editingTask}
                        defaultValues={taskFormDefaults}
                        onClose={handleCloseModal}
                    />
                </Suspense>
            </Modal>

            {/* Task Detail Modal */}
            <Modal 
                isOpen={isDetailModalOpen} 
                onClose={handleCloseModal} 
                title="Task Details"
            >
                {selectedTaskId && (
                    <Suspense fallback={<LoadingChunk />}>
                        <TaskDetail
                            taskId={selectedTaskId}
                            onEdit={(task) => {
                                handleCloseModal();
                                handleTaskAction('edit', task);
                            }}
                            onAddSubtask={handleAddSubtask}
                            onNavigate={handleNavigateTask}
                        />
                    </Suspense>
                )}
            </Modal>

            {/* Project Form Modal */}
            <Modal
                isOpen={isProjectModalOpen}
                onClose={handleCloseModal}
                title={editingProject ? 'Edit Project' : 'Add Project'}
            >
                <Suspense fallback={<LoadingChunk />}>
                    <ProjectForm project={editingProject} onClose={handleCloseModal} />
                </Suspense>
            </Modal>

            {/* Category Manager Modal */}
            <Modal
                isOpen={isCategoryModalOpen}
                onClose={handleCloseModal}
                title="Manage Categories"
            >
                <CategoryManager />
            </Modal>

            <RecurringDeleteModal 
                isOpen={isRecurringDeleteModalOpen}
                onClose={handleCloseModal}
                onConfirm={handleRecurringDeleteConfirm}
            />
        </div>
    );
};

export default Layout;
