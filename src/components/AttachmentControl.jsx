import React, { useEffect, useId, useRef } from 'react';
import Icon from './Icon';

const subscribeToMediaSelection = ( frame, onSelect ) => {
	frame.on( 'select', onSelect );
	return () => frame.off( 'select', onSelect );
};

const AttachmentControl = ({ value, onChange }) => {
    const mediaFrameRef = useRef(null);
    const mediaCleanupRef = useRef(null);
    const onChangeRef = useRef(onChange);
    const radioName = `pandatask-attachment-${useId().replace(/[^a-z0-9_-]/gi, '')}`;
    const mode = value?.type === 'link'
        ? 'link'
        : value?.type === 'file'
            ? 'upload'
            : 'none';
    const linkUrl = mode === 'link' ? value?.url || '' : '';

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    useEffect(() => () => {
        const frame = mediaFrameRef.current;
        if (frame) {
            mediaCleanupRef.current?.();
            frame.remove?.();
            mediaFrameRef.current = null;
            mediaCleanupRef.current = null;
        }
    }, []);

    const handleModeChange = (newMode) => {
        if (newMode === 'none') {
            onChange({ type: '', url: '', id: '', filename: '' });
        } else if (newMode === 'link') {
            onChange({ type: 'link', url: linkUrl, id: '', filename: linkUrl });
        } else if (newMode === 'upload') {
            if (value?.type !== 'file') {
                onChange({ type: 'file', url: '', id: '', filename: '' });
            }
        }
    };

    const handleLinkChange = (e) => {
        const url = e.target.value;
        onChange({ type: 'link', url: url, id: '', filename: url });
    };

    const openMediaUploader = () => {
        if (typeof wp === 'undefined' || !wp.media) {
            console.error('WordPress Media Uploader not available.');
            alert('Media Uploader not available.');
            return;
        }

        if (mediaFrameRef.current) {
            mediaFrameRef.current.open();
            return;
        }

        const frame = wp.media({
            title: 'Select or Upload File',
            button: { text: 'Use this file' },
            multiple: false
        });
        const handleSelect = () => {
            const attachment = frame
                .state()
                .get('selection')
                .first()
                .toJSON();
            onChangeRef.current({
                type: 'file',
                url: attachment.url,
                id: attachment.id,
                filename: attachment.filename
            });
        };
        mediaCleanupRef.current = subscribeToMediaSelection(frame, handleSelect);
        mediaFrameRef.current = frame;
        frame.open();
    };

    const handleRemove = () => {
        handleModeChange('none');
    };

    return (
        <div className="pandat69-attachment-control">
            <div className="pandat69-attachment-options">
                <label>
                    <input 
                        type="radio" 
                        name={radioName}
                        value="none" 
                        checked={mode === 'none'} 
                        onChange={() => handleModeChange('none')}
                    /> None
                </label>
                <label style={{ marginLeft: '10px' }}>
                    <input 
                        type="radio" 
                        name={radioName}
                        value="link" 
                        checked={mode === 'link'} 
                        onChange={() => handleModeChange('link')}
                    /> Link URL
                </label>
                <label style={{ marginLeft: '10px' }}>
                    <input 
                        type="radio" 
                        name={radioName}
                        value="upload" 
                        checked={mode === 'upload'} 
                        onChange={() => handleModeChange('upload')}
                    /> Upload File
                </label>
            </div>

            {mode === 'link' && (
                <div className="pandat69-attachment-input-area" style={{ marginTop: '10px' }}>
                    <input 
                        type="url"
                        className="pandat69-input" 
                        placeholder="https://..." 
                        value={linkUrl}
                        onChange={handleLinkChange}
                        aria-label="Attachment URL"
                    />
                </div>
            )}

            {mode === 'upload' && (
                <div className="pandat69-attachment-input-area" style={{ marginTop: '10px' }}>
                    <button 
                        type="button" 
                        className="pandat69-button pandat69-upload-attachment-btn"
                        onClick={openMediaUploader}
                    >
                        Select or Upload File
                    </button>
                    <p className="description pandat69-attachment-privacy-note">
                        PandaTask stores a protected task copy, but the selected
                        Media Library original may remain publicly reachable. Use
                        a private source for confidential files.
                    </p>
                </div>
            )}

            {(value?.url || value?.filename) && mode !== 'none' && (
                <div className="pandat69-attachment-display" style={{ marginTop: '10px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <div className="pandat69-attachment-info" style={{ background: '#fff', padding: '5px 10px', border: '1px solid #ddd', borderRadius: '3px', display: 'flex', alignItems: 'center', gap: '5px' }}>
                        <Icon name={value.type === 'file' ? 'file' : 'link'} size={16} />
                        <a href={value.url} target="_blank" rel="noopener noreferrer">{value.filename || value.url}</a>
                        <button 
                            type="button" 
                            className="pandat69-remove-attachment-btn" 
                            onClick={handleRemove}
                            aria-label="Remove attachment"
                            style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#d63638', marginLeft: '5px' }}
                        >
                            <Icon name="x" size={16} />
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AttachmentControl;
