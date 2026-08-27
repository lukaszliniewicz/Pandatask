import React, { useEffect, useRef, useState } from 'react';
import '../../../assets/scss/components/_rich-content.scss';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import CodeBlock from '@tiptap/extension-code-block';
import { TableKit } from '@tiptap/extension-table';
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import Modal from '../Modal';
import MermaidNode from './MermaidNode';
import MermaidEditorDialog from './MermaidEditorDialog';
import {
    convertMermaidMarkdownFences,
    looksLikeHtml,
    plainTextToHtml,
} from '../../rich-content/mermaidContent.mjs';

const CODE_LANGUAGES = [
    ['', 'Plain text'],
    ['json', 'JSON'],
    ['javascript', 'JavaScript'],
    ['typescript', 'TypeScript'],
    ['php', 'PHP'],
    ['css', 'CSS'],
    ['html', 'HTML'],
    ['bash', 'Shell / Bash'],
    ['sql', 'SQL'],
    ['yaml', 'YAML'],
];

const canonicalizeInitialValue = (value) => {
    const text = String(value ?? '');
    if (!text) return '';
    return looksLikeHtml(text) ? text : plainTextToHtml(text);
};

const markdownToSafeHtml = (markdown) => {
    const parsed = marked.parse(convertMermaidMarkdownFences(String(markdown ?? '')), {
        async: false,
        gfm: true,
        breaks: false,
    });
    return DOMPurify.sanitize(String(parsed), {
        USE_PROFILES: { html: true },
        ADD_ATTR: ['class'],
    });
};

const ToolbarButton = ({ active, children, label, onClick }) => (
    <button
        type="button"
        className={`pandat69-rich-editor-button ${active ? 'active' : ''}`}
        aria-pressed={active}
        title={label}
        aria-label={label}
        onClick={onClick}
    >
        {children}
    </button>
);

const RichTaskDescriptionEditor = ({ value, onChange, id, 'aria-describedby': ariaDescribedBy }) => {
    const [markdownDialogOpen, setMarkdownDialogOpen] = useState(false);
    const [markdownDraft, setMarkdownDraft] = useState('');
    const [markdownError, setMarkdownError] = useState('');
    const [mermaidDialogValue, setMermaidDialogValue] = useState(undefined);
    const fileInputRef = useRef(null);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                codeBlock: false,
                link: false,
            }),
            Link.configure({
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
            }),
            CodeBlock.configure({
                languageClassPrefix: 'language-',
                defaultLanguage: null,
            }),
            TableKit.configure({
                table: {
                    resizable: false,
                },
            }),
            MermaidNode,
        ],
        content: canonicalizeInitialValue(value),
        onUpdate: ({ editor: currentEditor }) => {
            onChange(currentEditor.isEmpty ? '' : currentEditor.getHTML());
        },
        editorProps: {
            attributes: {
                id,
                class: 'pandat69-rich-editor-content',
                'aria-label': 'Task description',
                'aria-describedby': ariaDescribedBy || '',
                'aria-multiline': 'true',
            },
        },
    });

    useEffect(() => {
        if (!editor) return;
        const next = canonicalizeInitialValue(value);
        const current = editor.isEmpty ? '' : editor.getHTML();
        if (!editor.isFocused && next !== current) {
            editor.commands.setContent(next, { emitUpdate: false });
        }
    }, [editor, value]);

    if (!editor) return null;

    const insertMarkdown = (markdown) => {
        try {
            const html = markdownToSafeHtml(markdown);
            if (html) editor.chain().focus().insertContent(html).run();
            setMarkdownError('');
            return true;
        } catch (error) {
            setMarkdownError(error instanceof Error ? error.message : 'Markdown could not be imported.');
            return false;
        }
    };

    const editLink = () => {
        const currentHref = editor.getAttributes('link').href || '';
        const href = window.prompt('Link URL', currentHref);
        if (href === null) return;
        if (!href.trim()) {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }
        editor.chain().focus().extendMarkRange('link').setLink({ href: href.trim() }).run();
    };

    const currentLanguage = editor.isActive('codeBlock') ? (editor.getAttributes('codeBlock').language || '') : '';

    return (
        <>
            <div className="pandat69-rich-editor">
                <div className="pandat69-rich-editor-toolbar" role="toolbar" aria-label="Description formatting">
                    <ToolbarButton label="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}><strong>B</strong></ToolbarButton>
                    <ToolbarButton label="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}><em>I</em></ToolbarButton>
                    <ToolbarButton label="Heading 2" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>H2</ToolbarButton>
                    <ToolbarButton label="Heading 3" active={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>H3</ToolbarButton>
                    <ToolbarButton label="Bulleted list" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>• List</ToolbarButton>
                    <ToolbarButton label="Numbered list" active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>1. List</ToolbarButton>
                    <ToolbarButton label="Link" active={editor.isActive('link')} onClick={editLink}>Link</ToolbarButton>
                    <details className="pandat69-rich-editor-more">
                        <summary>More</summary>
                        <div className="pandat69-rich-editor-more-menu">
                            <ToolbarButton label="Blockquote" active={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()}>❝ Quote</ToolbarButton>
                            <ToolbarButton label="Inline code" active={editor.isActive('code')} onClick={() => editor.chain().focus().toggleCode().run()}>&lt;/&gt; Inline</ToolbarButton>
                            <ToolbarButton
                                label="Code block"
                                active={editor.isActive('codeBlock')}
                                onClick={() => editor.chain().focus().toggleCodeBlock({ language: currentLanguage || null }).run()}
                            >
                                Code block
                            </ToolbarButton>
                            <ToolbarButton
                                label={editor.isActive('mermaid') ? 'Edit Mermaid diagram' : 'Insert Mermaid diagram'}
                                active={editor.isActive('mermaid')}
                                onClick={() => setMermaidDialogValue(editor.isActive('mermaid') ? editor.getAttributes('mermaid') : null)}
                            >
                                Diagram
                            </ToolbarButton>
                            <ToolbarButton label="Paste Markdown" onClick={() => setMarkdownDialogOpen(true)}>Paste Markdown</ToolbarButton>
                            <ToolbarButton label="Upload Markdown file" onClick={() => fileInputRef.current?.click()}>Upload .md</ToolbarButton>
                        </div>
                    </details>
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept=".md,.markdown,text/markdown,text/plain"
                        hidden
                        onChange={async (event) => {
                            const file = event.target.files?.[0];
                            if (file) insertMarkdown(await file.text());
                            event.target.value = '';
                        }}
                    />
                </div>

                {markdownError && (
                    <div className="pandat69-rich-editor-error" role="alert">{markdownError}</div>
                )}

                {editor.isActive('codeBlock') && (
                    <div className="pandat69-code-language-row">
                        <label htmlFor={`${id}-code-language`}>Code language</label>
                        <select
                            id={`${id}-code-language`}
                            className="pandat69-select"
                            value={currentLanguage}
                            onChange={(event) => editor.chain().focus().updateAttributes('codeBlock', { language: event.target.value || null }).run()}
                        >
                            {CODE_LANGUAGES.map(([code, label]) => <option key={code || 'plain'} value={code}>{label}</option>)}
                        </select>
                    </div>
                )}

                <EditorContent editor={editor} />
            </div>

            <Modal isOpen={markdownDialogOpen} onClose={() => setMarkdownDialogOpen(false)} title="Paste Markdown">
                <label htmlFor={`${id}-markdown-input`}>Markdown</label>
                <textarea
                    id={`${id}-markdown-input`}
                    className="pandat69-textarea pandat69-markdown-import-input"
                    rows="14"
                    value={markdownDraft}
                    onChange={(event) => setMarkdownDraft(event.target.value)}
                    placeholder="Paste Markdown here. Mermaid fences are converted into editable diagrams."
                />
                <div className="pandat69-form-actions">
                    <button type="button" className="pandat69-button" onClick={() => setMarkdownDialogOpen(false)}>Cancel</button>
                    <button
                        type="button"
                        className="pandat69-button pandat69-submit-task-btn"
                        disabled={!markdownDraft.trim()}
                        onClick={() => {
                            if (insertMarkdown(markdownDraft)) {
                                setMarkdownDraft('');
                                setMarkdownDialogOpen(false);
                            }
                        }}
                    >
                        Insert Markdown
                    </button>
                </div>
            </Modal>

            {mermaidDialogValue !== undefined && (
                <MermaidEditorDialog
                    initialValue={mermaidDialogValue || undefined}
                    onClose={() => setMermaidDialogValue(undefined)}
                    onSave={(content) => {
                        if (editor.isActive('mermaid')) {
                            editor.chain().focus().updateAttributes('mermaid', content).run();
                        } else {
                            editor.chain().focus().insertContent([
                                { type: 'mermaid', attrs: content },
                                { type: 'paragraph' },
                            ]).run();
                        }
                        setMermaidDialogValue(undefined);
                    }}
                />
            )}
        </>
    );
};

export { markdownToSafeHtml };
export default RichTaskDescriptionEditor;
