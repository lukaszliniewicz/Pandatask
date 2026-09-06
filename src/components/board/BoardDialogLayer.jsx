import React, { Suspense } from 'react';
import CategoryManager from '../CategoryManager';
import Modal from '../Modal';
import RecurringDeleteModal from '../RecurringDeleteModal';
import TaskDetailModalMeta from '../task-detail/TaskDetailModalMeta';
import { lazyWithRetry } from '../../utils/lazyWithRetry';
import { useWorkMutations } from '../../hooks/useWorkLog';

const ProjectForm = lazyWithRetry(() => import('../ProjectForm'));
const TaskDetail = lazyWithRetry(() => import('../TaskDetail'));
const TaskForm = lazyWithRetry(() => import('../TaskForm'));
const WorkEntryForm = lazyWithRetry(() => import('../work/WorkEntryForm'));
const WorkTypeManager = lazyWithRetry(() => import('../work/WorkTypeManager'));
const LoadingDialog = () => (
    <div className="pandat69-loading" role="status">
        Loading dialog…
    </div>
);

const BoardDialogLayer = ({ controller }) => {
    const taskDialog = controller.dialog?.kind === 'task' ? controller.dialog : null;
    const projectDialog = controller.dialog?.kind === 'project' ? controller.dialog : null;
    const workDialog = controller.dialog?.kind === 'work' ? controller.dialog : null;
    const { updateEntry } = useWorkMutations();

    return (
        <>
            <Modal
                isOpen={Boolean(taskDialog)}
                onClose={controller.closeDialogs}
                title={
                    taskDialog?.task
                        ? 'Edit Task'
                        : taskDialog?.defaults.parent_task_id
                        ? 'Add Subtask'
                        : 'Add New Task'
                }
            >
                {taskDialog && (
                    <Suspense fallback={<LoadingDialog />}>
                        <TaskForm
                            task={taskDialog.task}
                            defaultValues={taskDialog.defaults}
                            onClose={controller.closeDialogs}
                        />
                    </Suspense>
                )}
            </Modal>

            <Modal
                isOpen={Boolean(workDialog)}
                onClose={controller.closeDialogs}
                title={
                    workDialog?.entry
                        ? 'Edit work entry'
                        : workDialog?.task
                        ? `Add detail · ${workDialog.task.name}`
                        : 'Log work'
                }
            >
                {workDialog && (
                    <Suspense fallback={<LoadingDialog />}>
                        <WorkEntryForm
                            task={workDialog.task}
                            initialValues={workDialog.entry || workDialog.initialValues}
                            onSubmitOverride={
                                workDialog.entry
                                    ? data =>
                                          updateEntry.mutateAsync({
                                              id: workDialog.entry.id,
                                              data,
                                              previousAllocations:
                                                  workDialog.entry.allocations || [],
                                          })
                                    : null
                            }
                            isSubmitting={updateEntry.isPending}
                            submitLabel={workDialog.entry ? 'Save changes' : null}
                            onSaved={controller.closeDialogs}
                        />
                    </Suspense>
                )}
            </Modal>

            <Modal
                isOpen={controller.dialog?.kind === 'work-types'}
                onClose={controller.closeDialogs}
                title="Manage work types"
            >
                <Suspense fallback={<LoadingDialog />}>
                    <WorkTypeManager />
                </Suspense>
            </Modal>

            <Modal
                isOpen={controller.isDetailModalOpen}
                onClose={controller.closeDialogs}
                title="Task Details"
                headerMeta={<TaskDetailModalMeta taskId={controller.selectedTaskId} />}
            >
                {controller.selectedTaskId && (
                    <Suspense fallback={<LoadingDialog />}>
                        <TaskDetail
                            taskId={controller.selectedTaskId}
                            onEdit={task => {
                                controller.closeDialogs();
                                controller.handleTaskAction('edit', task);
                            }}
                            onAddSubtask={controller.addSubtask}
                            onNavigate={controller.navigateTask}
                            contextInModalHeader
                        />
                    </Suspense>
                )}
            </Modal>

            <Modal
                isOpen={Boolean(projectDialog)}
                onClose={controller.closeDialogs}
                title={projectDialog?.project ? 'Edit Project' : 'Add Project'}
            >
                {projectDialog && (
                    <Suspense fallback={<LoadingDialog />}>
                        <ProjectForm project={projectDialog.project} onClose={controller.closeDialogs} />
                    </Suspense>
                )}
            </Modal>

            <Modal
                isOpen={controller.dialog?.kind === 'category'}
                onClose={controller.closeDialogs}
                title="Manage Categories"
            >
                <CategoryManager />
            </Modal>

            <RecurringDeleteModal
                isOpen={controller.dialog?.kind === 'recurring-delete'}
                onClose={controller.closeDialogs}
                onConfirm={controller.confirmRecurringDelete}
                isPending={controller.isDeleting}
            />
        </>
    );
};

export default BoardDialogLayer;
