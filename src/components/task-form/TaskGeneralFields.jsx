import React from 'react';
import { Controller } from 'react-hook-form';
import Icon from '../Icon';
import TaskSelect from '../TaskSelect';

const TaskGeneralFields = ({
    active,
    categories,
    control,
    createCategory,
    errors,
    fieldPrefix,
    isEdit,
    isUserBoard,
    newCategoryName,
    onCancelCategory,
    onCreateCategory,
    onNewCategoryNameChange,
    onShowCategoryInput,
    parentTaskId,
    projects,
    register,
    showCategoryInput,
    targetBoard,
    task,
    taskType,
    userBoards
}) => (
    <section
        id={`${fieldPrefix}-general-panel`}
        role="tabpanel"
        aria-labelledby={`${fieldPrefix}-general-tab`}
        className={`pandat69-form-tab-content ${active ? 'active' : ''}`}
        hidden={!active}
    >
        {isUserBoard && userBoards?.length > 0 && (
            <div className="pandat69-form-field pandat69-board-target-field">
                <label htmlFor={`${fieldPrefix}-target-board`}>
                    {isEdit ? 'Move to Board:' : 'Create in Board:'}
                </label>
                <select id={`${fieldPrefix}-target-board`} className="pandat69-select" {...register('target_board')}>
                    {userBoards.map((board) => (
                        <option key={board.id} value={board.id}>{board.name}</option>
                    ))}
                </select>
                <p className="pandat69-field-hint">
                    Project, category, parent, dependency, and role selections clear when the board changes.
                </p>
            </div>
        )}

        <div className="pandat69-form-field">
            <label htmlFor={`${fieldPrefix}-name`}>Task Name</label>
            <input
                id={`${fieldPrefix}-name`}
                className="pandat69-input"
                aria-invalid={Boolean(errors.name)}
                aria-describedby={errors.name ? `${fieldPrefix}-name-error` : undefined}
                {...register('name', { required: 'Task name is required' })}
            />
            {errors.name && <span id={`${fieldPrefix}-name-error`} className="pandat69-error-text">{errors.name.message}</span>}
        </div>

        <div className="pandat69-form-row">
            <div className="pandat69-form-field pandat69-form-field-half">
                <label htmlFor={`${fieldPrefix}-type`}>Type</label>
                <select id={`${fieldPrefix}-type`} className="pandat69-select" {...register('task_type')}>
                    <option value="task">Standard Task</option>
                    <option value="bug">Bug Report</option>
                </select>
            </div>
            {taskType === 'bug' && (
                <div className="pandat69-form-field pandat69-form-field-half">
                    <label htmlFor={`${fieldPrefix}-bug-url`}>Bug URL</label>
                    <input id={`${fieldPrefix}-bug-url`} type="url" className="pandat69-input" placeholder="https://…" {...register('bug_url')} />
                </div>
            )}
        </div>

        <div className="pandat69-form-field">
            <label htmlFor={`${fieldPrefix}-description`}>Description</label>
            <textarea id={`${fieldPrefix}-description`} className="pandat69-textarea" rows="4" {...register('description')} />
        </div>

        <div className="pandat69-form-row">
            <div className="pandat69-form-field pandat69-form-field-half">
                <label htmlFor={`${fieldPrefix}-status`}>Status</label>
                <select id={`${fieldPrefix}-status`} className="pandat69-select" {...register('status')}>
                    <option value="pending">Pending</option>
                    <option value="in-progress">In Progress</option>
                    <option value="done">Done</option>
                </select>
            </div>
            <div className="pandat69-form-field pandat69-form-field-half">
                <label htmlFor={`${fieldPrefix}-priority`}>Priority</label>
                <input id={`${fieldPrefix}-priority`} type="number" min="1" max="10" className="pandat69-input" {...register('priority')} />
            </div>
        </div>

        <div className="pandat69-form-row">
            <div className="pandat69-form-field pandat69-form-field-half">
                <label htmlFor={`${fieldPrefix}-project`}>Project</label>
                <select id={`${fieldPrefix}-project`} className="pandat69-select" {...register('project_id')} disabled={Boolean(parentTaskId)}>
                    <option value="">-- No Project --</option>
                    {projects?.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                </select>
                {parentTaskId && <p className="pandat69-field-hint">This subtask inherits its parent task&apos;s project.</p>}
            </div>
            <div className="pandat69-form-field pandat69-form-field-half">
                <label htmlFor={`${fieldPrefix}-category`}>Category</label>
                {!showCategoryInput ? (
                    <div className="pandat69-inline-control">
                        <select id={`${fieldPrefix}-category`} className="pandat69-select" {...register('category_id')}>
                            <option value="">-- No Category --</option>
                            {categories?.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                        </select>
                        <button type="button" className="pandat69-button pandat69-compact-control" onClick={onShowCategoryInput} title="New Category" aria-label="New category"><Icon name="plus" /></button>
                    </div>
                ) : (
                    <div className="pandat69-inline-control">
                        <label className="pandat69-visually-hidden" htmlFor={`${fieldPrefix}-new-category`}>New category name</label>
                        <input
                            id={`${fieldPrefix}-new-category`}
                            type="text"
                            className="pandat69-input"
                            placeholder="Category name"
                            value={newCategoryName}
                            onChange={(event) => onNewCategoryNameChange(event.target.value)}
                        />
                        <button type="button" className="pandat69-button pandat69-compact-control" disabled={createCategory.isPending} onClick={onCreateCategory} aria-label="Create category"><Icon name="check" /></button>
                        <button type="button" className="pandat69-button pandat69-button-danger pandat69-compact-control" onClick={onCancelCategory} aria-label="Cancel category creation"><Icon name="x" /></button>
                    </div>
                )}
            </div>
        </div>

        <fieldset className="pandat69-form-field pandat69-fieldset">
            <legend>Subtask Of (Parent Task)</legend>
            <Controller
                control={control}
                name="parent_task_id"
                render={({ field: { onChange, value } }) => (
                    <TaskSelect
                        selectedTaskIds={value}
                        onChange={onChange}
                        currentTaskId={task?.id}
                        mode="single"
                        overrideBoardName={targetBoard}
                        inputLabel="Search for a parent task"
                    />
                )}
            />
        </fieldset>
    </section>
);

export default TaskGeneralFields;
