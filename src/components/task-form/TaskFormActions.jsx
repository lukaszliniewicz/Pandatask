import React from 'react';
import Icon from '../Icon';

const TaskFormActions = ({ activeTab, isEdit, isSubmitting, onCancel, onTabChange }) => (
    <div className="pandat69-form-actions pandat69-task-form-actions">
        <div>
            {activeTab !== 'general' && (
                <button type="button" className="pandat69-button" onClick={() => onTabChange(activeTab === 'people' ? 'schedule' : 'general')}>
                    <Icon name="chevron-left" /> Previous
                </button>
            )}
            {activeTab !== 'people' && (
                <button type="button" className="pandat69-button" onClick={() => onTabChange(activeTab === 'general' ? 'schedule' : 'people')}>
                    Next <Icon name="chevron-right" />
                </button>
            )}
        </div>
        <div>
            <button type="button" className="pandat69-button pandat69-cancel-button" onClick={onCancel} disabled={isSubmitting}>
                Cancel
            </button>
            <button type="submit" className="pandat69-button pandat69-submit-task-btn" disabled={isSubmitting}>
                {isSubmitting ? 'Saving...' : (isEdit ? 'Save Changes' : 'Create Task')}
            </button>
        </div>
    </div>
);

export default TaskFormActions;
