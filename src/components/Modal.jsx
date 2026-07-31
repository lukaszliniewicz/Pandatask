import React, { useEffect, useId, useRef } from 'react';
import ReactDOM from 'react-dom';
import Icon from './Icon';

const openModals = new Set();
const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const Modal = ({ isOpen, onClose, title, children }) => {
    const dialogRef = useRef(null);
    const returnFocusRef = useRef(null);
    const onCloseRef = useRef(onClose);
    const modalTokenRef = useRef({});
    const titleId = useId();

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    useEffect(() => {
        if (!isOpen) return undefined;

        const dialog = dialogRef.current;
        if (!dialog) return undefined;

        const token = modalTokenRef.current;
        returnFocusRef.current = document.activeElement;
        openModals.add(token);
        document.body.classList.add('pandat69-modal-open');
        if (!dialog.open) dialog.showModal();

        const focusFrame = window.requestAnimationFrame(() => {
            const firstFocusable = dialog.querySelector(focusableSelector);
            (firstFocusable || dialog)?.focus();
        });

        const handleCancel = (event) => {
            event.preventDefault();
            onCloseRef.current();
        };

        dialog.addEventListener('cancel', handleCancel);

        return () => {
            window.cancelAnimationFrame(focusFrame);
            dialog.removeEventListener('cancel', handleCancel);
            if (dialog.open) dialog.close();
            openModals.delete(token);
            if (openModals.size === 0) document.body.classList.remove('pandat69-modal-open');
            if (returnFocusRef.current?.isConnected) returnFocusRef.current.focus();
        };
    }, [isOpen]);

    if (!isOpen) return null;

    return ReactDOM.createPortal(
        <dialog
            className="pandat69-react-modal active"
            ref={dialogRef}
            aria-labelledby={titleId}
        >
            <div
                className="pandat69-modal-container"
            >
                <div className="pandat69-modal-content">
                    <div className="pandat69-modal-header">
                        <h3 id={titleId}>{title}</h3>
                        <button 
                            type="button" 
                            className="pandat69-modal-close" 
                            onClick={onClose}
                            aria-label="Close modal"
                        >
                            <Icon name="x" />
                        </button>
                    </div>
                    <div className="pandat69-modal-body">
                        {children}
                    </div>
                </div>
            </div>
        </dialog>,
        document.body
    );
};

export default Modal;
