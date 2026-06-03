import React from 'react';
import Modal from './Modal';
import { useConfig } from '../context/ConfigContext';

const RecurringDeleteModal = ({ isOpen, onClose, onConfirm }) => {
    const { text } = useConfig();

    return (
        <Modal 
            isOpen={isOpen} 
            onClose={onClose} 
            title={text.delete_recurring_title || "Delete Recurring Task"}
        >
            <div className="pandat69-modal-body-content">
                <p>{text.delete_recurring_text || "This is a recurring task. How would you like to delete it?"}</p>
                <div className="pandat69-form-actions" style={{ justifyContent: 'center', marginTop: '20px', gap: '10px' }}>
                    <button 
                        type="button" 
                        className="pandat69-button" 
                        onClick={() => onConfirm('single')}
                    >
                        {text.delete_single_instance || "This Instance Only"}
                    </button>
                    <button 
                        type="button" 
                        className="pandat69-button pandat69-button-danger" 
                        onClick={() => onConfirm('all')}
                    >
                        {text.delete_all_instances || "This & All Future"}
                    </button>
                    <button 
                        type="button" 
                        className="pandat69-button" 
                        onClick={onClose}
                    >
                        {text.cancel || "Cancel"}
                    </button>
                </div>
            </div>
        </Modal>
    );
};

export default RecurringDeleteModal;
