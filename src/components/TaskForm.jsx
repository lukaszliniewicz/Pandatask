import React, { useState, useEffect } from 'react';
import { useForm, Controller } from 'react-hook-form';
import { useTaskMutations } from '../hooks/useTaskMutations';
import { useProjects } from '../hooks/useProjects';
import { useCategories } from '../hooks/useCategories';
import { useCategoryMutations } from '../hooks/useCategoryMutations';
import { useConfig } from '../context/ConfigContext';
import { useUserBoards } from '../hooks/useUserBoards';
import UserSelect from './UserSelect';
import TaskSelect from './TaskSelect';
import AttachmentControl from './AttachmentControl';
import ReasonModal from './ReasonModal';

const TaskForm = ({ task = null, onClose, defaultTaskType = 'task', defaultValues = {} }) => {
    const isEdit = !!task;
    const { boardName, currentUser } = useConfig();
    
    const [activeTab, setActiveTab] = useState('general');
    const [reasonModalConfig, setReasonModalConfig] = useState({ isOpen: false, message: '', pendingData: null });
    
    const isUserBoard = boardName && boardName.startsWith('user_');
    const { data: userBoards } = useUserBoards();

    let initialAssigned = [];
    if (task?.assigned_user_ids) {
        initialAssigned = task.assigned_user_ids.map(id => parseInt(id, 10));
    } else if (defaultValues.assigned_persons) {
        initialAssigned = defaultValues.assigned_persons;
    } else if (isUserBoard && !isEdit && currentUser?.id) {
        initialAssigned = [parseInt(currentUser.id, 10)];
    }

    // Determine initial schedule mode
    const initialScheduleMode = task?.deadline_days_after_start ? 'dynamic' : 'fixed';

    const { register, handleSubmit, control, watch, setValue, formState: { errors, isSubmitting } } = useForm({
        defaultValues: {
            name: task?.name || '',
            description: task?.description || defaultValues.description || '',
            status: task?.status || 'pending',
            priority: task?.priority || 5,
            schedule_mode: initialScheduleMode,
            start_date: task?.start_date || '',
            deadline: task?.deadline || '',
            deadline_days_after_start: task?.deadline_days_after_start || '',
            project_id: task?.project_id || '',
            category_id: task?.category_id || '',
            assigned_persons: initialAssigned,
            supervisor_persons: task?.supervisor_user_ids?.map(id => parseInt(id, 10)) || [],
            predecessors: task?.predecessor_ids?.map(id => parseInt(id, 10)) || [],
            task_type: task?.task_type || defaultTaskType,
            bug_url: task?.bug_url || defaultValues.bug_url || '',
            notify_deadline: task?.notify_deadline == 1,
            notify_days_before: task?.notify_days_before || 3,
            parent_task_id: task?.parent_task_id || defaultValues.parent_task_id || '',
            is_recurring: task?.is_recurring == 1,
            recurrence_frequency: task?.recurrence_frequency === 'weekly' && Number(task?.recurrence_interval) === 2
                ? 'bi-weekly'
                : (task?.recurrence_frequency || 'weekly'),
            recurrence_interval: task?.recurrence_interval || 1,
            recurrence_days: task?.recurrence_days ? task.recurrence_days.split(',') : [],
            recurrence_ends_on: task?.recurrence_ends_on || '',
            attachment: {
                type: task?.attachment_type || '',
                url: task?.attachment_url || '',
                id: task?.attachment_post_id || '',
                filename: task?.attachment_filename || ''
            },
            target_board: task?.board_name || boardName || ''
        }
    });

    const taskType = watch('task_type');
    const scheduleMode = watch('schedule_mode');
    const targetBoard = watch('target_board') || task?.board_name || boardName;
    const notifyDeadline = watch('notify_deadline');
    const isRecurring = watch('is_recurring');
    const recurrenceFrequency = watch('recurrence_frequency');

    // Context Cleanup: If board changes, clear context-specific data
    useEffect(() => {
        const originalBoard = task?.board_name || boardName;
        if (targetBoard && targetBoard !== originalBoard) {
            setValue('project_id', '');
            setValue('category_id', '');
            setValue('parent_task_id', '');
            setValue('predecessors', []);
            setValue('assigned_persons', []);
            setValue('supervisor_persons', []);
        }
    }, [targetBoard, task?.board_name, boardName, setValue]);

    const { createTask, updateTask } = useTaskMutations();
    const { createCategory } = useCategoryMutations();
    const { data: projects } = useProjects(targetBoard);
    const { data: categories } = useCategories(targetBoard);

    const [showCategoryInput, setShowCategoryInput] = useState(false);
    const [newCategoryName, setNewCategoryName] = useState('');

    const processSubmit = async (data, changeComment = '') => {
        const payload = { ...data };
        
        payload.board_name = (isUserBoard && data.target_board) ? data.target_board : (task?.board_name || boardName);
        delete payload.target_board;

        if (data.attachment) {
            payload.attachment_type = data.attachment.type;
            payload.attachment_url = data.attachment.url;
            payload.attachment_post_id = data.attachment.id;
            payload.attachment_filename = data.attachment.filename;
            delete payload.attachment;
        }

        // Apply Gantt / Schedule Mode logic
        if (data.schedule_mode === 'dynamic') {
            payload.deadline = ''; // Cleared so backend calculates it based on days
            if (payload.predecessors && payload.predecessors.length > 0) {
                payload.start_date = ''; // Will start dynamically when predecessor finishes
                if (payload.status === 'in-progress' && !isEdit) {
                    payload.status = 'pending'; // Force pending if waiting on predecessor
                }
            }
        } else {
            payload.deadline_days_after_start = ''; // Wipe duration if fixed
        }
        delete payload.schedule_mode;
        
        payload.is_recurring = data.is_recurring ? 1 : 0;
        payload.notify_deadline = data.notify_deadline ? 1 : 0;
        if (Array.isArray(data.recurrence_days)) payload.recurrence_days = data.recurrence_days.join(',');
        if (data.parent_task_id) payload.parent_task_id = parseInt(data.parent_task_id, 10);
        if (changeComment) payload.change_comment = changeComment;

        try {
            if (isEdit) {
                await updateTask.mutateAsync({ id: task.id, data: payload });
            } else {
                await createTask.mutateAsync(payload);
            }
            onClose();
        } catch (error) {
            console.error('Failed to save task:', error);
            alert('Failed to save task. Please try again.');
        }
    };

    const onSubmit = (data) => {
        if (isEdit) {
            const statusChanged = task.status !== data.status;
            const deadlineChanged = data.schedule_mode === 'fixed' && (task.deadline || '') !== (data.deadline || '');

            if (statusChanged || deadlineChanged) {
                let message = 'You are changing sensitive task details. Please provide a brief reason for this change (optional).';
                setReasonModalConfig({ isOpen: true, message, pendingData: data });
                return;
            }
        }
        processSubmit(data);
    };

    // Handle shifting user to correct tab if validation fails
    const onValidationError = (formErrors) => {
        if (formErrors.name || formErrors.task_type) setActiveTab('general');
        else if (formErrors.deadline_days_after_start) setActiveTab('schedule');
    };

    return (
        <>
            <form onSubmit={handleSubmit(onSubmit, onValidationError)} className="pandat69-form">
                
                {/* Form Navigation Tabs */}
                <div className="pandat69-form-tabs">
                    <button type="button" className={`pandat69-form-tab ${activeTab === 'general' ? 'active' : ''}`} onClick={() => setActiveTab('general')}>
                        General Details {errors.name && <span className="pandat69-tab-error-dot"></span>}
                    </button>
                    <button type="button" className={`pandat69-form-tab ${activeTab === 'schedule' ? 'active' : ''}`} onClick={() => setActiveTab('schedule')}>
                        Schedule & Rules {errors.deadline_days_after_start && <span className="pandat69-tab-error-dot"></span>}
                    </button>
                    <button type="button" className={`pandat69-form-tab ${activeTab === 'people' ? 'active' : ''}`} onClick={() => setActiveTab('people')}>
                        People & Files
                    </button>
                </div>

                {/* TAB 1: General */}
                <div className={`pandat69-form-tab-content ${activeTab === 'general' ? 'active' : ''}`}>
                    {isUserBoard && userBoards && userBoards.length > 0 && (
                        <div className="pandat69-form-field" style={{background: '#f0f4f8', padding: '10px', borderRadius: '4px', border: '1px solid #d1d9e6'}}>
                            <label style={{fontWeight:'bold', color:'#384D68'}}>
                                {isEdit ? 'Move to Board:' : 'Create in Board:'}
                            </label>
                            <select className="pandat69-select" {...register('target_board')}>
                                {userBoards.map(b => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                            <p className="pandat69-description" style={{fontSize:'12px', marginTop:'5px', color: '#666'}}>
                                Context specific data (Project, Category, Parent) clears if board changes.
                            </p>
                        </div>
                    )}

                    <div className="pandat69-form-field">
                        <label>Task Name</label>
                        <input className="pandat69-input" {...register('name', { required: 'Task name is required' })} />
                        {errors.name && <span className="pandat69-error-text">{errors.name.message}</span>}
                    </div>

                    <div className="pandat69-form-row">
                        <div className="pandat69-form-field pandat69-form-field-half">
                            <label>Type</label>
                            <select className="pandat69-select" {...register('task_type')}>
                                <option value="task">Standard Task</option>
                                <option value="bug">Bug Report</option>
                            </select>
                        </div>
                        {taskType === 'bug' && (
                            <div className="pandat69-form-field pandat69-form-field-half">
                                <label>Bug URL</label>
                                <input type="text" className="pandat69-input" placeholder="https://..." {...register('bug_url')} />
                            </div>
                        )}
                    </div>

                    <div className="pandat69-form-field">
                        <label>Description</label>
                        <textarea className="pandat69-textarea" rows="4" {...register('description')} />
                    </div>

                    <div className="pandat69-form-row">
                        <div className="pandat69-form-field pandat69-form-field-half">
                            <label>Status</label>
                            <select className="pandat69-select" {...register('status')}>
                                <option value="pending">Pending</option>
                                <option value="in-progress">In Progress</option>
                                <option value="done">Done</option>
                            </select>
                        </div>
                        <div className="pandat69-form-field pandat69-form-field-half">
                            <label>Priority</label>
                            <input type="number" min="1" max="10" className="pandat69-input" {...register('priority')} />
                        </div>
                    </div>

                    <div className="pandat69-form-row">
                        <div className="pandat69-form-field pandat69-form-field-half">
                            <label>Project</label>
                            <select className="pandat69-select" {...register('project_id')}>
                                <option value="">-- No Project --</option>
                                {projects?.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        </div>
                        <div className="pandat69-form-field pandat69-form-field-half">
                            <label>Category</label>
                            {!showCategoryInput ? (
                                <div style={{ display: 'flex', gap: '5px' }}>
                                    <select className="pandat69-select" {...register('category_id')} style={{ flexGrow: 1 }}>
                                        <option value="">-- No Category --</option>
                                        {categories?.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                    </select>
                                    <button type="button" className="pandat69-button" onClick={() => setShowCategoryInput(true)} title="New Category" style={{ padding: '0 10px' }}>+</button>
                                </div>
                            ) : (
                                <div style={{ display: 'flex', gap: '5px' }}>
                                    <input type="text" className="pandat69-input" placeholder="New Name" value={newCategoryName} onChange={(e) => setNewCategoryName(e.target.value)} style={{ flexGrow: 1 }}/>
                                    <button type="button" className="pandat69-button" disabled={createCategory.isPending} onClick={async () => {
                                        if (!newCategoryName.trim()) return;
                                        try {
                                            const newCat = await createCategory.mutateAsync({ name: newCategoryName, boardName: targetBoard });
                                            setShowCategoryInput(false);
                                            setNewCategoryName('');
                                            setValue('category_id', newCat.id);
                                        } catch(e) { alert('Error creating category'); }
                                    }}>✓</button>
                                    <button type="button" className="pandat69-button pandat69-button-danger" onClick={() => setShowCategoryInput(false)}>&times;</button>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="pandat69-form-field">
                        <label>Subtask Of (Parent Task)</label>
                        <Controller
                            control={control}
                            name="parent_task_id"
                            render={({ field: { onChange, value } }) => (
                                <TaskSelect selectedTaskIds={value} onChange={onChange} currentTaskId={task?.id} mode="single" overrideBoardName={targetBoard} />
                            )}
                        />
                    </div>
                </div>

                {/* TAB 2: Schedule */}
                <div className={`pandat69-form-tab-content ${activeTab === 'schedule' ? 'active' : ''}`}>
                    <div className="pandat69-form-field">
                        <label style={{ fontSize: '1.1em', paddingBottom: '10px', borderBottom: '1px solid #eee', marginBottom: '15px', display: 'block' }}>
                            Timeline & Dependencies
                        </label>
                        <div style={{ display: 'flex', gap: '20px', marginBottom: '20px' }}>
                            <label style={{ display: 'flex', alignItems: 'center', fontWeight: 'normal', cursor: 'pointer' }}>
                                <input type="radio" value="fixed" {...register('schedule_mode')} style={{ marginRight: '8px' }} /> 
                                Fixed Dates
                            </label>
                            <label style={{ display: 'flex', alignItems: 'center', fontWeight: 'normal', cursor: 'pointer' }}>
                                <input type="radio" value="dynamic" {...register('schedule_mode')} style={{ marginRight: '8px' }} /> 
                                Dynamic (Duration / Dependent)
                            </label>
                        </div>
                    </div>

                    {scheduleMode === 'fixed' ? (
                        <div className="pandat69-form-row" style={{ background: '#fcfcfc', padding: '15px', border: '1px solid #eee', borderRadius: '4px' }}>
                            <div className="pandat69-form-field pandat69-form-field-half">
                                <label>Start Date</label>
                                <input type="date" className="pandat69-input" {...register('start_date')} />
                            </div>
                            <div className="pandat69-form-field pandat69-form-field-half">
                                <label>Deadline Date</label>
                                <input type="date" className="pandat69-input" {...register('deadline')} />
                            </div>
                        </div>
                    ) : (
                        <div style={{ background: '#f5f7fa', padding: '15px', borderRadius: '4px', border: '1px solid #e0e5eb', marginBottom: '20px' }}>
                            <div className="pandat69-form-field">
                                <label>Task Duration (Days)</label>
                                <input 
                                    type="number" 
                                    min="1"
                                    className="pandat69-input" 
                                    placeholder="e.g. 5" 
                                    {...register('deadline_days_after_start', { required: scheduleMode === 'dynamic' ? 'Duration is required' : false })} 
                                />
                                {errors.deadline_days_after_start && <span className="pandat69-error-text" style={{display:'block', marginTop:'5px'}}>{errors.deadline_days_after_start.message}</span>}
                                <p className="pandat69-description" style={{ marginTop: '5px' }}>How many days will this take to complete once started?</p>
                            </div>
                            
                            <div className="pandat69-form-field" style={{ marginTop: '15px', paddingTop: '15px', borderTop: '1px solid #dcdfe4' }}>
                                <label>Starts After (Blocked By)</label>
                                <Controller
                                    control={control}
                                    name="predecessors"
                                    render={({ field: { onChange, value } }) => (
                                        <TaskSelect 
                                            selectedTaskIds={value} 
                                            onChange={onChange} 
                                            currentTaskId={task?.id}
                                            overrideBoardName={targetBoard}
                                        />
                                    )}
                                />
                                <p className="pandat69-description" style={{ marginTop: '5px' }}>Select tasks that must finish before this begins. If selected, start and deadline dates are calculated automatically.</p>
                            </div>
                        </div>
                    )}

                    <div className="pandat69-form-field" style={{ marginTop: '20px' }}>
                        <label style={{display: 'flex', alignItems: 'center', fontWeight: 'normal', cursor: 'pointer'}}>
                            <input type="checkbox" {...register('notify_deadline')} style={{marginRight: '8px'}} />
                            Notify users before deadline arrives?
                        </label>
                    </div>

                    {notifyDeadline && (
                        <div className="pandat69-form-field" style={{ paddingLeft: '25px' }}>
                            <label>Days before deadline to notify:</label>
                            <input type="number" className="pandat69-input" min="1" max="30" style={{maxWidth: '100px'}} {...register('notify_days_before')} />
                        </div>
                    )}

                    <div className="pandat69-form-field" style={{borderTop: '1px solid #eee', paddingTop: '20px', marginTop: '20px'}}>
                        <label style={{display: 'flex', alignItems: 'center', cursor: 'pointer'}}>
                            <input type="checkbox" {...register('is_recurring')} style={{marginRight: '8px'}} />
                            <strong>Make this a repeating task</strong>
                        </label>

                        {isRecurring && (
                            <div className="pandat69-recurrence-options" style={{marginTop: '15px', padding: '15px', background: '#fcfcfc', border: '1px solid #eee', borderRadius: '4px'}}>
                                <div className="pandat69-form-field">
                                    <label>Repeats every</label>
                                    <select className="pandat69-select" {...register('recurrence_frequency')}>
                                        <option value="weekly">Weekly</option>
                                        <option value="bi-weekly">Bi-Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <option value="custom_weekly">Custom Days of Week</option>
                                    </select>
                                </div>

                                {recurrenceFrequency === 'custom_weekly' && (
                                    <div className="pandat69-form-field">
                                        <label>Select Days</label>
                                        <div style={{display: 'flex', flexWrap: 'wrap', gap: '10px'}}>
                                            {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((day, idx) => (
                                                <label key={day} style={{fontWeight: 'normal', cursor: 'pointer'}}>
                                                    <input type="checkbox" value={idx + 1} {...register('recurrence_days')} /> {day}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <div className="pandat69-form-field">
                                    <label>Stop repeating on (Optional)</label>
                                    <input type="date" className="pandat69-input" {...register('recurrence_ends_on')} />
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {/* TAB 3: People & Files */}
                <div className={`pandat69-form-tab-content ${activeTab === 'people' ? 'active' : ''}`}>
                    <div className="pandat69-form-field">
                        <label>Assigned To</label>
                        <Controller
                            control={control}
                            name="assigned_persons"
                            render={({ field: { onChange, value } }) => (
                                <UserSelect selectedUserIds={value} onChange={onChange} overrideBoardName={targetBoard} />
                            )}
                        />
                        <p className="pandat69-description" style={{ marginTop: '5px' }}>Users responsible for doing the work.</p>
                    </div>

                    <div className="pandat69-form-field">
                        <label>Supervisors</label>
                        <Controller
                            control={control}
                            name="supervisor_persons"
                            render={({ field: { onChange, value } }) => (
                                <UserSelect selectedUserIds={value} onChange={onChange} overrideBoardName={targetBoard} />
                            )}
                        />
                        <p className="pandat69-description" style={{ marginTop: '5px' }}>Users overseeing the work (receives notifications).</p>
                    </div>

                    <div className="pandat69-form-field" style={{borderTop: '1px solid #eee', paddingTop: '20px', marginTop: '20px'}}>
                        <label>Attachment / External Link</label>
                        <Controller
                            control={control}
                            name="attachment"
                            render={({ field: { onChange, value } }) => (
                                <AttachmentControl value={value} onChange={onChange} />
                            )}
                        />
                    </div>
                </div>

                <div className="pandat69-form-actions" style={{ borderTop: '1px solid #e0e5eb', paddingTop: '15px', marginTop: '25px', display: 'flex', justifyContent: 'space-between' }}>
                    <div>
                        {activeTab !== 'general' && (
                            <button type="button" className="pandat69-button" onClick={() => setActiveTab(activeTab === 'people' ? 'schedule' : 'general')} style={{marginRight: '10px'}}>
                                &laquo; Previous
                            </button>
                        )}
                        {activeTab !== 'people' && (
                            <button type="button" className="pandat69-button" onClick={() => setActiveTab(activeTab === 'general' ? 'schedule' : 'people')}>
                                Next &raquo;
                            </button>
                        )}
                    </div>
                    <div>
                        <button type="button" className="pandat69-button" onClick={onClose} disabled={isSubmitting} style={{marginRight: '10px', background: '#f5f5f5', border: '1px solid #ccc', color: '#333'}}>
                            Cancel
                        </button>
                        <button type="submit" className="pandat69-button pandat69-submit-task-btn" disabled={isSubmitting} style={{background: '#384D68', color: '#fff', fontWeight: 'bold'}}>
                            {isSubmitting ? 'Saving...' : (isEdit ? 'Save Changes' : 'Create Task')}
                        </button>
                    </div>
                </div>
            </form>

            <ReasonModal 
                isOpen={reasonModalConfig.isOpen}
                onClose={() => setReasonModalConfig({ isOpen: false, message: '', pendingData: null })}
                onConfirm={(comment) => processSubmit(reasonModalConfig.pendingData, comment)}
                title="Reason for Change"
                message={reasonModalConfig.message}
            />
        </>
    );
};

export default TaskForm;
