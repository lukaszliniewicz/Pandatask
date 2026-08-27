import test from 'node:test';
import assert from 'node:assert/strict';
import {
    convertMermaidMarkdownFences,
    plainTextToHtml,
    serializeMermaidFigure,
    validateMermaidSource,
} from '../src/rich-content/mermaidContent.mjs';

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
