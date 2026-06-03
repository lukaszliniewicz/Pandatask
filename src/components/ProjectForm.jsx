import React from 'react';
import { useForm, Controller } from 'react-hook-form';
import { useProjectMutations } from '../hooks/useProjectMutations';
import UserSelect from './UserSelect';

const ProjectForm = ({ project = null, onClose }) => {
    const isEdit = !!project;
    const { register, handleSubmit, control, formState: { errors, isSubmitting } } = useForm({
        defaultValues: {
            name: project?.name || '',
            description: project?.description || '',
            deadline: project?.deadline || '',
            assigned_persons: project?.assigned_user_ids?.map(id => parseInt(id, 10)) || [],
            supervisor_persons: project?.supervisor_user_ids?.map(id => parseInt(id, 10)) || [],
        }
    });

    const { createProject, updateProject } = useProjectMutations();

    const onSubmit = async (data) => {
        try {
            if (isEdit) {
                await updateProject.mutateAsync({ id: project.id, data });
            } else {
                await createProject.mutateAsync(data);
            }
            onClose();
        } catch (error) {
            console.error('Failed to save project:', error);
            alert('Failed to save project. Please try again.');
        }
    };

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="pandat69-form">
            <div className="pandat69-form-field">
                <label>Project Name</label>
                <input 
                    className="pandat69-input" 
                    {...register('name', { required: 'Project name is required' })} 
                />
                {errors.name && <span className="pandat69-error-text">{errors.name.message}</span>}
            </div>

            <div className="pandat69-form-field">
                <label>Description</label>
                <textarea 
                    className="pandat69-textarea" 
                    rows="4" 
                    {...register('description')} 
                />
            </div>

            <div className="pandat69-form-field">
                <label>Deadline</label>
                <input type="date" className="pandat69-input" {...register('deadline')} />
            </div>

            <div className="pandat69-form-field">
                <label>Assigned To</label>
                <Controller
                    control={control}
                    name="assigned_persons"
                    render={({ field: { onChange, value } }) => (
                        <UserSelect selectedUserIds={value} onChange={onChange} />
                    )}
                />
            </div>

            <div className="pandat69-form-field">
                <label>Supervisors</label>
                <Controller
                    control={control}
                    name="supervisor_persons"
                    render={({ field: { onChange, value } }) => (
                        <UserSelect selectedUserIds={value} onChange={onChange} />
                    )}
                />
            </div>

            <div className="pandat69-form-actions">
                <button 
                    type="submit" 
                    className="pandat69-button pandat69-save-project-btn" 
                    disabled={isSubmitting}
                >
                    {isSubmitting ? 'Saving...' : (isEdit ? 'Update Project' : 'Save Project')}
                </button>
                <button 
                    type="button" 
                    className="pandat69-button" 
                    onClick={onClose}
                    disabled={isSubmitting}
                >
                    Cancel
                </button>
            </div>
        </form>
    );
};

export default ProjectForm;
