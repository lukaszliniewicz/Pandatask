import React, { useState, useEffect, useRef } from 'react';
import ReactDOM from 'react-dom';
import Modal from './Modal';
import TaskForm from './TaskForm';

const FloatingBugReporter = ({ boardName, defaultAssigneeId }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [position, setPosition] = useState({ top: 'auto', left: 'auto', bottom: '20px', right: '20px' });
    const widgetRef = useRef(null);
    const isDragging = useRef(false);

    useEffect(() => {
        const savedPos = localStorage.getItem('pandat69_bug_widget_pos');
        if (savedPos) {
            try {
                const pos = JSON.parse(savedPos);
                // Ensure absolute positioning
                setPosition({ 
                    top: pos.top, 
                    left: pos.left, 
                    bottom: 'auto', 
                    right: 'auto' 
                });
            } catch (e) {}
        }
    }, []);

    const handleMouseDown = (e) => {
        if (e.button !== 0) return; // Only left click
        const widget = widgetRef.current;
        if (!widget) return;

        const rect = widget.getBoundingClientRect();
        const offsetX = e.clientX - rect.left;
        const offsetY = e.clientY - rect.top;

        const handleMouseMove = (moveEvent) => {
            isDragging.current = true;
            widget.style.cursor = 'grabbing';
            document.body.style.userSelect = 'none';

            let x = moveEvent.clientX - offsetX;
            let y = moveEvent.clientY - offsetY;

            // Boundaries
            const winWidth = window.innerWidth;
            const winHeight = window.innerHeight;
            x = Math.max(0, Math.min(x, winWidth - rect.width));
            y = Math.max(0, Math.min(y, winHeight - rect.height));

            widget.style.left = `${x}px`;
            widget.style.top = `${y}px`;
            widget.style.bottom = 'auto';
            widget.style.right = 'auto';
        };

        const handleMouseUp = () => {
            widget.style.cursor = 'grab';
            document.body.style.userSelect = '';
            
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleMouseUp);

            if (isDragging.current) {
                // Save position
                const currentRect = widget.getBoundingClientRect();
                localStorage.setItem('pandat69_bug_widget_pos', JSON.stringify({
                    left: `${currentRect.left}px`,
                    top: `${currentRect.top}px`
                }));
                
                // Prevent click firing immediately
                setTimeout(() => {
                    isDragging.current = false;
                }, 50);
            }
        };

        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('mouseup', handleMouseUp);
    };

    const getSystemInfo = () => {
        const ua = navigator.userAgent;
        const screenRes = `${window.screen.width}x${window.screen.height}`;
        const viewport = `${window.innerWidth}x${window.innerHeight}`;
        return `\n\n<hr><p><strong>System Info:</strong><br>UA: ${ua}<br>Screen: ${screenRes}<br>Viewport: ${viewport}</p>`;
    };

    const handleClick = (e) => {
        if (isDragging.current) {
            e.stopPropagation();
            return;
        }
        setIsOpen(true);
    };

    return ReactDOM.createPortal(
        <>
            <div 
                ref={widgetRef}
                id="pandat69-floating-bug-reporter" 
                style={{ 
                    position: 'fixed', 
                    zIndex: 99999, 
                    cursor: 'grab',
                    ...position
                }}
                onMouseDown={handleMouseDown}
            >
                <button 
                    className="pandat69-floating-btn"
                    onClick={handleClick}
                    title="Report a bug"
                >
                    <span className="dashicons dashicons-code-standards"></span>
                </button>
            </div>

            <Modal isOpen={isOpen} onClose={() => setIsOpen(false)} title="Report an Issue">
                <TaskForm 
                    onClose={() => setIsOpen(false)}
                    defaultTaskType="bug"
                    defaultValues={{
                        bug_url: window.location.href,
                        description: getSystemInfo(),
                        assigned_persons: defaultAssigneeId ? [parseInt(defaultAssigneeId, 10)] : []
                    }}
                />
            </Modal>
        </>,
        document.body
    );
};

export default FloatingBugReporter;
