import React, { useEffect } from 'react';
import ReactDOM from 'react-dom';

const Modal = ({ isOpen, onClose, title, children }) => {
    // Prevent body scrolling when modal is open
    useEffect(() => {
        if (isOpen) {
            document.body.classList.add('pandat69-modal-open');
        } else {
            document.body.classList.remove('pandat69-modal-open');
        }
        return () => {
            document.body.classList.remove('pandat69-modal-open');
        };
    }, [isOpen]);

    if (!isOpen) return null;

    return ReactDOM.createPortal(
        <div className="pandat69-react-modal active">
            <div className="pandat69-modal-overlay" onClick={onClose}></div>
            <div className="pandat69-modal-container">
                <div className="pandat69-modal-content">
                    <div className="pandat69-modal-header">
                        <h3>{title}</h3>
                        <button 
                            type="button" 
                            className="pandat69-modal-close" 
                            onClick={onClose}
                            aria-label="Close modal"
                        >
                            &times;
                        </button>
                    </div>
                    <div className="pandat69-modal-body">
                        {children}
                    </div>
                </div>
            </div>
        </div>,
        document.body
    );
};

export default Modal;
