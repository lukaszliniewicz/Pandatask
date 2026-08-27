import React, { useEffect, useRef } from 'react';

const RichTaskDescription = ({ html, emptyHtml = '' , className = '' }) => {
    const rootRef = useRef(null);
    const content = html || emptyHtml;

    useEffect(() => {
        if (!content || !rootRef.current) return undefined;
        let cancelled = false;
        const root = rootRef.current;

        Promise.all([
            import('../../../assets/scss/components/_rich-content.scss'),
            import('../../rich-content/renderMermaid'),
        ]).then(([, { renderMermaidFigures }]) => {
            if (cancelled) return;
            return renderMermaidFigures(root);
        }).catch((error) => {
            if (!cancelled) console.error('Failed to render Mermaid task description:', error);
        });

        return () => {
            cancelled = true;
        };
    }, [content]);

    return (
        <div
            ref={rootRef}
            className={className}
            dangerouslySetInnerHTML={{ __html: content }}
        />
    );
};

export default RichTaskDescription;
