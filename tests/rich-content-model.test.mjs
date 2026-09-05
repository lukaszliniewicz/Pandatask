import test from 'node:test';
import assert from 'node:assert/strict';
import {
    convertMermaidMarkdownFences,
    normalizeMermaidContent,
    plainTextToHtml,
    serializeMermaidFigure,
    validateMermaidSource,
} from '../src/rich-content/mermaidContent.mjs';
import {
    looksLikeTaskMarkdown,
    markdownToTaskHtml,
    TASK_DESCRIPTION_MAX_LENGTH,
    validateTaskDescriptionLength,
    validateTaskMarkdown,
} from '../src/rich-content/markdownContent.mjs';

test('plain task text becomes escaped canonical paragraph HTML', () => {
    assert.equal(
        plainTextToHtml('First <line>\nsecond & line\n\nNext'),
        '<p>First &lt;line&gt;<br>second &amp; line</p><p>Next</p>'
    );
});

test('Mermaid figures use the shared canonical markup and escape source metadata', () => {
    const html = serializeMermaidFigure({
        source: 'flowchart LR\nA[<unsafe>] --> B',
        title: 'A & B',
        caption: '<caption>',
        description: 'Accessible <text>',
    });

    assert.match(html, /<figure class="iarf-mermaid">/);
    assert.match(html, /class="language-mermaid"/);
    assert.match(html, /A &amp; B/);
    assert.match(html, /A\[&lt;unsafe&gt;\] --&gt; B/);
    assert.doesNotMatch(html, /<unsafe>/);
});

test('Markdown Mermaid fences convert to editable canonical figures', () => {
    const converted = convertMermaidMarkdownFences('Before\n\n```mermaid\nflowchart LR\nA --> B\n```\n\nAfter');
    assert.match(converted, /Before/);
    assert.match(converted, /<figure class="iarf-mermaid">/);
    assert.match(converted, /flowchart LR\nA --&gt; B/);
    assert.match(converted, /After/);
});

test('Mermaid source validation blocks configuration and click actions', () => {
    assert.equal(validateMermaidSource('flowchart LR\nA --> B'), null);
    assert.match(validateMermaidSource('%%{init: {"theme":"dark"}}%%\nflowchart LR\nA --> B'), /init directives/);
    assert.match(validateMermaidSource('---\nconfig:\n  theme: dark\n---\nflowchart LR\nA --> B'), /frontmatter configuration/);
    assert.match(validateMermaidSource('flowchart LR\nA --> B\nclick A "https://example.com"'), /click actions/);
});

test('oversized Mermaid source is preserved for visible validation', () => {
    const source = `flowchart LR\n${'A --> B\n'.repeat(10_001)}`;
    const normalized = normalizeMermaidContent({ source });

    assert.equal(normalized.source, source.trim());
    assert.match(validateMermaidSource(normalized.source), /50,000 characters or fewer/);
    assert.throws(
        () => serializeMermaidFigure(normalized),
        /50,000 characters or fewer/
    );
});

test('Markdown detection distinguishes document syntax from ordinary hash text', () => {
    assert.equal(looksLikeTaskMarkdown('# Heading\n\nA **bold** paragraph.'), true);
    assert.equal(looksLikeTaskMarkdown('See issue #123 for the ordinary prose.'), false);
    assert.equal(looksLikeTaskMarkdown('| Name | State |\n| --- | --- |\n| Paste | Fixed |'), true);
    assert.equal(looksLikeTaskMarkdown('[Pandatask](https://example.test)'), true);
    assert.equal(looksLikeTaskMarkdown('![Diagram][architecture]'), true);
});

test('Markdown conversion handles mixed block and inline syntax without raw markers', () => {
    const html = markdownToTaskHtml('# Heading\n\nA **bold** paragraph.\n\n- One\n- Two');

    assert.match(html, /<h1>Heading<\/h1>/);
    assert.match(html, /A <strong>bold<\/strong> paragraph/);
    assert.match(html, /<ul>/);
    assert.doesNotMatch(html, /# Heading|\*\*bold\*\*/);
});

test('unsupported Markdown structures produce actionable validation errors', () => {
    assert.match(validateTaskMarkdown('- [ ] Not silently downgraded'), /Task-list checkboxes/);
    assert.match(validateTaskMarkdown('![Diagram](https://example.test/image.png)'), /Markdown images/);
    assert.throws(
        () => markdownToTaskHtml('![Diagram](https://example.test/image.png)'),
        /attachment or paste a link/
    );
});

test('task-description length validation matches the REST contract', () => {
    assert.equal(validateTaskDescriptionLength('x'.repeat(TASK_DESCRIPTION_MAX_LENGTH)), null);
    assert.match(validateTaskDescriptionLength('x'.repeat(TASK_DESCRIPTION_MAX_LENGTH + 1)), /10,000/);
});
