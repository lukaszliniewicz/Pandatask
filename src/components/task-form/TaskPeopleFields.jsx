import React from 'react';
import { Controller } from 'react-hook-form';
import AttachmentControl from '../AttachmentControl';
import UserSelect from '../UserSelect';

const TaskPeopleFields = ({ active, control, fieldPrefix, targetBoard }) => (
    <section
        id={`${fieldPrefix}-people-panel`}
        role="tabpanel"
        aria-labelledby={`${fieldPrefix}-people-tab`}
        className={`pandat69-form-tab-content ${active ? 'active' : ''}`}
        hidden={!active}
    >
        <fieldset className="pandat69-form-field pandat69-fieldset">
            <legend>Assigned To</legend>
            <Controller
                control={control}
                name="assigned_persons"
                render={({ field: { onChange, value } }) => (
                    <UserSelect
                        selectedUserIds={value}
                        onChange={onChange}
                        overrideBoardName={targetBoard}
                        inputLabel="Search people to assign"
                    />
                )}
            />
            <p className="pandat69-field-hint">Users responsible for doing the work.</p>
        </fieldset>

        <fieldset className="pandat69-form-field pandat69-fieldset">
            <legend>Supervisors</legend>
            <Controller
                control={control}
                name="supervisor_persons"
                render={({ field: { onChange, value } }) => (
                    <UserSelect
                        selectedUserIds={value}
                        onChange={onChange}
                        overrideBoardName={targetBoard}
                        inputLabel="Search supervisors"
                    />
                )}
            />
            <p className="pandat69-field-hint">Users overseeing the work and receiving notifications.</p>
        </fieldset>

        <fieldset className="pandat69-form-field pandat69-fieldset pandat69-attachment-fieldset">
            <legend>Attachment / External Link</legend>
            <Controller
                control={control}
                name="attachment"
                render={({ field: { onChange, value } }) => (
                    <AttachmentControl value={value} onChange={onChange} />
                )}
            />
        </fieldset>
    </section>
);

export default TaskPeopleFields;
