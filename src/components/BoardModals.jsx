import React, { Suspense } from 'react';
import Modal from './Modal';
import CategoryManager from './CategoryManager';
import RecurringDeleteModal from './RecurringDeleteModal';
import { lazyWithRetry } from '../utils/lazyWithRetry';

const ProjectForm = lazyWithRetry( () => import( './ProjectForm' ) );
const TaskDetail = lazyWithRetry( () => import( './TaskDetail' ) );
const TaskForm = lazyWithRetry( () => import( './TaskForm' ) );
const LoadingChunk = () => (
	<div className="pandat69-loading">Loading dialog…</div>
);

const BoardModals = ( {
	isTaskModalOpen,
	isDetailModalOpen,
	isProjectModalOpen,
	isCategoryModalOpen,
	isRecurringDeleteModalOpen,
	onClose,
	editingTask,
	taskFormDefaults,
	selectedTaskId,
	onTaskAction,
	onAddSubtask,
	onNavigateTask,
	editingProject,
	onRecurringDeleteConfirm,
} ) => (
	<>
		<Modal
			isOpen={ isTaskModalOpen }
			onClose={ onClose }
			title={
				editingTask
					? 'Edit Task'
					: taskFormDefaults.parent_task_id
					? 'Add Subtask'
					: 'Add New Task'
			}
		>
			<Suspense fallback={ <LoadingChunk /> }>
				<TaskForm
					task={ editingTask }
					defaultValues={ taskFormDefaults }
					onClose={ onClose }
				/>
			</Suspense>
		</Modal>

		<Modal
			isOpen={ isDetailModalOpen }
			onClose={ onClose }
			title="Task Details"
		>
			{ selectedTaskId && (
				<Suspense fallback={ <LoadingChunk /> }>
					<TaskDetail
						taskId={ selectedTaskId }
						onEdit={ ( task ) => {
							onClose();
							onTaskAction( 'edit', task );
						} }
						onAddSubtask={ onAddSubtask }
						onNavigate={ onNavigateTask }
					/>
				</Suspense>
			) }
		</Modal>

		<Modal
			isOpen={ isProjectModalOpen }
			onClose={ onClose }
			title={ editingProject ? 'Edit Project' : 'Add Project' }
		>
			<Suspense fallback={ <LoadingChunk /> }>
				<ProjectForm project={ editingProject } onClose={ onClose } />
			</Suspense>
		</Modal>

		<Modal
			isOpen={ isCategoryModalOpen }
			onClose={ onClose }
			title="Manage Categories"
		>
			<CategoryManager />
		</Modal>

		<RecurringDeleteModal
			isOpen={ isRecurringDeleteModalOpen }
			onClose={ onClose }
			onConfirm={ onRecurringDeleteConfirm }
		/>
	</>
);

export default BoardModals;
