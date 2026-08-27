import React, { useEffect, useRef, useState } from 'react';
import Modal from '../Modal';
import {
    defaultMermaidSource,
    normalizeMermaidContent,
    validateMermaidSource,
} from '../../rich-content/mermaidContent.mjs';
import { renderMermaidSvg } from '../../rich-content/renderMermaid';

const MermaidEditorDialog = ({ initialValue, onClose, onSave }) => {
    const [content, setContent] = useState(() => normalizeMermaidContent({
        source: initialValue?.source || defaultMermaidSource,
        title: initialValue?.title || '',
        caption: initialValue?.caption || '',
        description: initialValue?.description || '',
    }));
    const [previewSvg, setPreviewSvg] = useState('');
    const [previewError, setPreviewError] = useState('');
    const [isRendering, setIsRendering] = useState(false);
    const fileRef = useRef(null);

    useEffect(() => {
        const validation = validateMermaidSource(content.source);
        if (validation) {
            setPreviewSvg('');
            setPreviewError(validation);
            setIsRendering(false);
            return undefined;
        }

        let cancelled = false;
        const timer = window.setTimeout(async () => {
            setIsRendering(true);
            try {
                const svg = await renderMermaidSvg(content);
                if (!cancelled) {
                    setPreviewSvg(svg);
                    setPreviewError('');
                }
            } catch (error) {
                if (!cancelled) {
                    setPreviewSvg('');
                    setPreviewError(error instanceof Error ? error.message : 'The diagram could not be rendered.');
                }
            } finally {
                if (!cancelled) setIsRendering(false);
            }
        }, 250);

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [content]);

    const update = (field, value) => {
        setContent((current) => normalizeMermaidContent({ ...current, [field]: value }));
    };

    const validationError = validateMermaidSource(content.source);
    const canSave = !validationError && !previewError && !isRendering;

    return (
        <Modal isOpen onClose={onClose} title="Mermaid diagram">
            <div className="pandat69-mermaid-editor">
                <div className="pandat69-mermaid-editor-fields">
                    <label>
                        <span>Title</span>
                        <input
                            className="pandat69-input"
                            value={content.title}
                            maxLength="180"
                            onChange={(event) => update('title', event.target.value)}
                            placeholder="Optional diagram title"
                        />
                    </label>
                    <label>
                        <span>Accessible description</span>
                        <textarea
                            className="pandat69-textarea"
                            value={content.description}
                            maxLength="1200"
                            rows="3"
                            onChange={(event) => update('description', event.target.value)}
                            placeholder="Describe the relationships or sequence conveyed by the diagram."
                        />
                    </label>
                    <label>
                        <span>Caption</span>
                        <input
                            className="pandat69-input"
                            value={content.caption}
                            maxLength="500"
                            onChange={(event) => update('caption', event.target.value)}
                            placeholder="Optional context or source note"
                        />
                    </label>
                    <div>
                        <div className="pandat69-mermaid-source-heading">
                            <label htmlFor="pandat69-mermaid-source">Mermaid source</label>
                            <button type="button" className="pandat69-button pandat69-compact-control" onClick={() => fileRef.current?.click()}>
                                Upload .mmd
                            </button>
                        </div>
                        <textarea
                            id="pandat69-mermaid-source"
                            className="pandat69-mermaid-source-input"
                            value={content.source}
                            rows="14"
                            spellCheck="false"
                            onChange={(event) => update('source', event.target.value)}
                            aria-describedby="pandat69-mermaid-source-help"
                        />
                        <input
                            ref={fileRef}
                            type="file"
                            accept=".mmd,.mermaid,text/plain,text/markdown"
                            hidden
                            onChange={async (event) => {
                                const file = event.target.files?.[0];
                                if (file?.size > 200_000) {
                                    setPreviewSvg('');
                                    setPreviewError('Diagram files must be 200 KB or smaller.');
                                } else if (file) {
                                    update('source', await file.text());
                                }
                                event.target.value = '';
                            }}
                        />
                        <p id="pandat69-mermaid-source-help" className="pandat69-field-hint">
                            Init/config directives, HTML labels, click actions and external navigation are disabled.
                        </p>
                    </div>
                </div>

                <div className="pandat69-mermaid-editor-preview-column">
                    <strong>Preview</strong>
                    <div className="pandat69-mermaid-editor-preview">
                        {previewSvg ? (
                            <div className="pandat69-mermaid-preview-svg" dangerouslySetInnerHTML={{ __html: previewSvg }} />
                        ) : previewError ? (
                            <div className="pandat69-mermaid-preview-error" role="alert">
                                <strong>Diagram preview unavailable</strong>
                                <span>{previewError}</span>
                            </div>
                        ) : (
                            <span className="pandat69-field-hint">{isRendering ? 'Rendering…' : 'Enter a diagram to see its preview.'}</span>
                        )}
                    </div>
                </div>
            </div>
            <div className="pandat69-form-actions pandat69-mermaid-editor-actions">
                <button type="button" className="pandat69-button" onClick={onClose}>Cancel</button>
                <button
                    type="button"
                    className="pandat69-button pandat69-submit-task-btn"
                    disabled={!canSave}
                    onClick={() => onSave(normalizeMermaidContent(content))}
                >
                    Save diagram
                </button>
            </div>
        </Modal>
    );
};

export default MermaidEditorDialog;
