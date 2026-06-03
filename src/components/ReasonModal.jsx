import React, { useState } from 'react';
import Modal from './Modal';

const ReasonModal = ({ isOpen, onClose, onConfirm, title, message }) => {
    const [comment, setComment] = useState('');

    const handleConfirm = () => {
        onConfirm(comment);
        setComment('');
        onClose();
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} title={title}>
            <div className="pandat69-reason-modal-content">
                <p dangerouslySetInnerHTML={{ __html: message }}></p>
                <textarea 
                    className="pandat69-textarea" 
                    placeholder="Optional comment..."
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    rows={3}
                    style={{ width: '100%', marginTop: '10px', marginBottom: '15px' }}
                />
                <div className="pandat69-form-actions" style={{ justifyContent: 'flex-end' }}>
                    <button type="button" className="pandat69-button" onClick={onClose}>Cancel</button>
                    <button type="button" className="pandat69-button pandat69-save-btn" onClick={handleConfirm}>Save Change</button>
                </div>
            </div>
        </Modal>
    );
};

export default ReasonModal;
