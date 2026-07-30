import React, { useState, useEffect, useId, useRef } from 'react';
import Icon from './Icon';

const AttachmentControl = ({ value, onChange }) => {
    const [mode, setMode] = useState('none');
    const [linkUrl, setLinkUrl] = useState('');
    const [mediaFrameRequested, setMediaFrameRequested] = useState(false);
    const mediaFrameRef = useRef(null);
    const onChangeRef = useRef(onChange);
    const radioName = `pandatask-attachment-${useId().replace(/[^a-z0-9_-]/gi, '')}`;

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    useEffect(() => {
        if (!mediaFrameRequested) {
            return undefined;
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

        frame.on('select', handleSelect);
        mediaFrameRef.current = frame;
        frame.open();

        return () => {
            frame.off('select', handleSelect);
            frame.remove?.();
            mediaFrameRef.current = null;
        };
    }, [mediaFrameRequested]);

    useEffect(() => {
        if (value?.type === 'link') {
            setMode('link');
            setLinkUrl(value.url || '');
        } else if (value?.type === 'file') {
            setMode('upload');
        } else {
            setMode('none');
        }
    }, [value?.type, value?.url]);

    const handleModeChange = (newMode) => {
        setMode(newMode);
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
        setLinkUrl(url);
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

        setMediaFrameRequested(true);
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
