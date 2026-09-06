import React, { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { useProjects } from '../hooks/useProjects';
import { useCategories } from '../hooks/useCategories';
import { useCategoryMutations } from '../hooks/useCategoryMutations';
import { useConfig } from '../context/ConfigContext';
import { useTaskStatusTransition } from '../context/CompletionContext';
import { useUserBoards } from '../hooks/useUserBoards';
import {
    buildTaskPayload,
    createTaskFormDefaults,
    requiresTaskChangeReason,
    validationErrorTab
} from '../taskFormModel.mjs';
import ReasonModal from './ReasonModal';
import TaskFormActions from './task-form/TaskFormActions';
import TaskFormTabs from './task-form/TaskFormTabs';
import TaskGeneralFields from './task-form/TaskGeneralFields';
import TaskPeopleFields from './task-form/TaskPeopleFields';
import TaskScheduleFields from './task-form/TaskScheduleFields';
import { useTaskRecurrence } from '../hooks/useTaskRecurrence';

const EMPTY_DEFAULT_VALUES = {};

const TaskForm = ({ task = null, onClose, defaultTaskType = 'task', defaultValues = EMPTY_DEFAULT_VALUES }) => {
    const isEdit = Boolean(task);
    const recurrence = useTaskRecurrence(task);
    const seriesVersion = useRef(null);
    const [saveError, setSaveError] = useState('');

    const { boardName, currentUser } = useConfig();
    const isUserBoard = boardName?.startsWith('user_');
    const fieldPrefix = useId().replace(/[^a-z0-9_-]/gi, '');
    const [activeTab, setActiveTab] = useState('general');
    const [reasonModalConfig, setReasonModalConfig] = useState({ isOpen: false, message: '', pendingData: null });
    const [showCategoryInput, setShowCategoryInput] = useState(false);
    const [newCategoryName, setNewCategoryName] = useState('');
    const { data: userBoards } = useUserBoards();
    const { createTask, updateTask } = useTaskMutations();
    const { setStatus } = useTaskStatusTransition();
    const { createCategory } = useCategoryMutations();

    const initialValues = useMemo(
        () => createTaskFormDefaults({ task, defaultTaskType, defaultValues, boardName, currentUser }),
        [task, defaultTaskType, defaultValues, boardName, currentUser]
    );
    const {
        register,
        handleSubmit,
        control,
        watch,
        setValue,
        formState: { errors, isSubmitting }
    } = useForm({ defaultValues: initialValues });

    useEffect(() => {
        if (seriesVersion.current === null && recurrence.data) {
            seriesVersion.current = recurrence.data.series.version;
            if (!recurrence.data.series.active) setValue('is_recurring', false);
        }
    }, [recurrence.data, setValue]);

    const taskType = watch('task_type');
    const scheduleMode = watch('schedule_mode');
    const targetBoard = watch('target_board') || task?.board_name || boardName;
    const previousTargetBoardRef = useRef(targetBoard);
    const notifyDeadline = watch('notify_deadline');
    const isRecurring = watch('is_recurring');
    const recurrenceScope = watch('recurrence_scope');
    const recurrenceFrequency = watch('recurrence_frequency');
    const recurrenceInterval = watch('recurrence_interval');
    const parentTaskId = watch('parent_task_id');
    const projectId = watch('project_id');
    const { data: projects } = useProjects(targetBoard);
    const { data: categories } = useCategories(targetBoard);

    useEffect(() => {
        const previousTargetBoard = previousTargetBoardRef.current;
        if (targetBoard && previousTargetBoard && targetBoard !== previousTargetBoard) {
            setValue('project_id', '');
            setValue('category_id', '');
            setValue('parent_task_id', '');
            setValue('predecessors', []);
            setValue('assigned_persons', []);
            setValue('supervisor_persons', []);
        }
        previousTargetBoardRef.current = targetBoard;
    }, [targetBoard, setValue]);

    const processSubmit = async (data, changeComment = '') => {
        if (createTask.isPending || updateTask.isPending) return;
        const payload = buildTaskPayload(data, {
            boardName,
            isUserBoard,
            isEdit,
            task,
            changeComment
        });

        if (payload.recurrence_scope === 'future') {
            if (seriesVersion.current === null) { setSaveError('Wait for the series to load before changing future occurrences.'); return; }
            payload.expected_series_version = seriesVersion.current;
        }
        setSaveError('');
        const shouldComplete = data.status === 'done' && (!isEdit || task.status !== 'done');
        if (shouldComplete) {
            payload.status = isEdit ? task.status : 'pending';
        }

        try {
            let savedTask;
            if (isEdit) {
                savedTask = await updateTask.mutateAsync({ id: task.id, data: payload });
            } else {
                savedTask = await createTask.mutateAsync(payload);
            }
            onClose();
            if (shouldComplete && savedTask) {
                await setStatus(savedTask, 'done', { changeComment });
            }
        } catch (error) {
            setSaveError(error.status === 409 ? 'The task or series changed elsewhere. Your draft is still here. Close and reopen the editor to review the latest occurrence before saving.' : error.message || 'Failed to save task. Please try again.');
        }
    };

    const onSubmit = async (data) => {
        if (requiresTaskChangeReason(task, data)) {
            setReasonModalConfig({
                isOpen: true,
                message: 'You are changing sensitive task details. Add a brief reason if useful.',
                pendingData: data
            });
            return;
        }
        await processSubmit(data);
    };

    const onValidationError = (formErrors) => {
        const errorTab = validationErrorTab(formErrors);
        if (errorTab) setActiveTab(errorTab);
    };

    const handleCreateCategory = async () => {
        if (!newCategoryName.trim() || createCategory.isPending) return;
        try {
            const newCategory = await createCategory.mutateAsync({
                name: newCategoryName,
                boardName: targetBoard
            });
            setShowCategoryInput(false);
            setNewCategoryName('');
            setValue('category_id', newCategory.id);
        } catch (error) {
            alert('Error creating category');
        }
    };

    const closeReasonModal = () => {
        setReasonModalConfig({ isOpen: false, message: '', pendingData: null });
    };

    return (
        <>
            <form onSubmit={handleSubmit(onSubmit, onValidationError)} className="pandat69-form">
                {task?.recurrence_series_id && <fieldset className="pandat69-form-field pandat69-fieldset pandat69-recurrence-scope">
                    <legend>Apply changes to</legend>
                    <label><input type="radio" value="this" {...register('recurrence_scope')} /> This occurrence</label>
                    {recurrence.data?.series.can_edit && Number(recurrence.data.series.current_task_id) === Number(task.id) && <label><input type="radio" value="future" {...register('recurrence_scope')} /> This and future occurrences</label>}
                    <p className="pandat69-field-hint">{recurrenceScope === 'future' ? 'These details become the defaults for new occurrences. Earlier tasks keep their own details.' : 'Future occurrences keep their existing defaults.'}</p>
                    {recurrence.isLoading && <p role="status">Loading future editing options…</p>}
                    {recurrence.isError && <p role="alert">Future editing options could not be loaded. <button type="button" className="pandat69-link-button" onClick={() => recurrence.refetch()}>Try again</button></p>}
                </fieldset>}
                {saveError && <p className="pandat69-error-text" role="alert">{saveError}</p>}
                <TaskFormTabs activeTab={activeTab} errors={errors} fieldPrefix={fieldPrefix} onChange={setActiveTab} />
                <TaskGeneralFields
                    active={activeTab === 'general'}
                    categories={categories}
                    control={control}
                    createCategory={createCategory}
                    errors={errors}
                    fieldPrefix={fieldPrefix}
                    isEdit={isEdit}
                    isUserBoard={isUserBoard}
                    newCategoryName={newCategoryName}
                    onCancelCategory={() => {
                        setShowCategoryInput(false);
                        setNewCategoryName('');
                    }}
                    onCreateCategory={handleCreateCategory}
                    onNewCategoryNameChange={setNewCategoryName}
                    onShowCategoryInput={() => setShowCategoryInput(true)}
                    parentTaskId={parentTaskId}
                    projects={projects}
                    register={register}
                    showCategoryInput={showCategoryInput}
                    targetBoard={targetBoard}
                    task={task}
                    taskType={taskType}
                    userBoards={userBoards}
                />
                <TaskScheduleFields
                    active={activeTab === 'schedule'}
                    control={control}
                    errors={errors}
                    fieldPrefix={fieldPrefix}
                    isRecurring={isRecurring}
                    notifyDeadline={notifyDeadline}
                    projectId={projectId}
                    recurrenceFrequency={recurrenceFrequency}
                    recurrenceInterval={recurrenceInterval}
                    recurrenceScope={recurrenceScope}
                    register={register}
                    scheduleMode={scheduleMode}
                    targetBoard={targetBoard}
                    task={task}
                />
                <TaskPeopleFields
                    active={activeTab === 'people'}
                    control={control}
                    fieldPrefix={fieldPrefix}
                    targetBoard={targetBoard}
                />
                <TaskFormActions
                    activeTab={activeTab}
                    isEdit={isEdit}
                    isSubmitting={isSubmitting || createTask.isPending || updateTask.isPending}
                    onCancel={onClose}
                    onTabChange={setActiveTab}
                />
            </form>

            <ReasonModal
                isOpen={reasonModalConfig.isOpen}
                onClose={closeReasonModal}
                onConfirm={(comment) => processSubmit(reasonModalConfig.pendingData, comment)}
                title="Reason for Change"
                message={reasonModalConfig.message}
            />
        </>
    );
};

export default TaskForm;
