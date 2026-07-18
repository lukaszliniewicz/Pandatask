import React, { useEffect, useId, useRef } from 'react';
import ReactDOM from 'react-dom';

const openModals = [];
const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const Modal = ({ isOpen, onClose, title, children }) => {
    const containerRef = useRef(null);
    const returnFocusRef = useRef(null);
    const onCloseRef = useRef(onClose);
    const modalTokenRef = useRef({});
    const titleId = useId();

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    useEffect(() => {
        if (!isOpen) return undefined;

        const token = modalTokenRef.current;
        returnFocusRef.current = document.activeElement;
        openModals.push(token);
        document.body.classList.add('pandat69-modal-open');

        const focusFrame = window.requestAnimationFrame(() => {
            const firstFocusable = containerRef.current?.querySelector(focusableSelector);
            (firstFocusable || containerRef.current)?.focus();
        });

        const handleKeyDown = (event) => {
            if (openModals[openModals.length - 1] !== token) return;

            if (event.key === 'Escape') {
                event.preventDefault();
                onCloseRef.current();
                return;
            }

            if (event.key !== 'Tab' || !containerRef.current) return;

            const focusable = Array.from(containerRef.current.querySelectorAll(focusableSelector));
            if (focusable.length === 0) {
                event.preventDefault();
                containerRef.current.focus();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);

        return () => {
            window.cancelAnimationFrame(focusFrame);
            document.removeEventListener('keydown', handleKeyDown);
            const tokenIndex = openModals.lastIndexOf(token);
            if (tokenIndex !== -1) openModals.splice(tokenIndex, 1);
            if (openModals.length === 0) document.body.classList.remove('pandat69-modal-open');
            if (returnFocusRef.current?.isConnected) returnFocusRef.current.focus();
        };
    }, [isOpen]);

    if (!isOpen) return null;

    return ReactDOM.createPortal(
        <div className="pandat69-react-modal active">
            <div className="pandat69-modal-overlay" onClick={onClose} aria-hidden="true"></div>
            <div
                className="pandat69-modal-container"
                ref={containerRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex="-1"
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
