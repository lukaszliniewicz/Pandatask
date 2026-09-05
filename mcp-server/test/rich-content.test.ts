import test from 'node:test';
import assert from 'node:assert/strict';
import { taskCreateData, taskUpdateData } from '../src/schemas.js';
import {
  convertMermaidMarkdownFences,
  markdownToHtml,
  normalizeBatchTaskDescriptions,
  normalizeTaskDescriptionBody,
  plainTextToHtml,
  TASK_DESCRIPTION_MAX_LENGTH,
} from '../src/rich-content.js';

test('task schemas accept explicit description input formats', () => {
  assert.equal(taskCreateData.parse({ name: 'Markdown task', description: '# Heading', description_format: 'markdown' }).description_format, 'markdown');
  assert.equal(taskUpdateData.parse({ description: 'plain text', description_format: 'plain' }).description_format, 'plain');
});

test('markdown descriptions become canonical HTML with code language metadata', () => {
  const html = markdownToHtml('# Heading\n\n```json\n{"ok":true}\n```');
  assert.match(html, /<h1>Heading<\/h1>/);
  assert.match(html, /<code class="language-json">/);
  assert.match(html, /&quot;ok&quot;|\{"ok":true\}/);
});

test('Mermaid markdown fences become canonical editable figures', () => {
  const converted = convertMermaidMarkdownFences('```mermaid\nflowchart LR\nA --> B\n```');
  assert.match(converted, /<figure class="iarf-mermaid">/);
  assert.match(converted, /class="language-mermaid"/);
  assert.match(converted, /A --&gt; B/);
});

test('unsafe Mermaid directives are rejected during MCP conversion', () => {
  assert.throws(
    () => markdownToHtml('```mermaid\n%%{init: {"theme":"dark"}}%%\nflowchart LR\nA --> B\n```'),
    /init directives/,
  );
  assert.throws(
    () => markdownToHtml('```mermaid\nflowchart LR\nA --> B\nclick A "https:\/\/example.com"\n```'),
    /click actions/,
  );
});

test('plain input is escaped and format metadata never reaches REST', () => {
  assert.equal(plainTextToHtml('One <two>\nline\n\nNext'), '<p>One &lt;two&gt;<br>line</p><p>Next</p>');
  assert.deepEqual(
    normalizeTaskDescriptionBody({ name: 'Task', description: 'One <two>', description_format: 'plain' }),
    { name: 'Task', description: '<p>One &lt;two&gt;</p>' },
  );
  assert.throws(
    () => normalizeTaskDescriptionBody({ description_format: 'markdown' }),
    /requires description/,
  );
});

test('description without a format is treated as HTML and the converted result is bounded', () => {
  assert.deepEqual(
    normalizeTaskDescriptionBody({ description: '<p>Already HTML</p>' }),
    { description: '<p>Already HTML</p>' },
  );

  const markdown = '- x\n'.repeat(1_000);
  assert.ok(markdown.length <= TASK_DESCRIPTION_MAX_LENGTH);
  assert.throws(
    () => normalizeTaskDescriptionBody({ description: markdown, description_format: 'markdown' }),
    /10000 JavaScript string code units or fewer after conversion/,
  );
});

test('batch task actions normalize descriptions while non-task actions stay unchanged', () => {
  const actions = normalizeBatchTaskDescriptions([
    { action: 'create_task', board_name: 'group_10', data: { name: 'Task', description: '**bold**', description_format: 'markdown' } },
    { action: 'delete_task', data: { id: 7 } },
  ]) as Record<string, unknown>[];
  const created = actions[0] as { data: Record<string, unknown> };
  assert.match(String(created.data.description), /<strong>bold<\/strong>/);
  assert.equal(created.data.description_format, undefined);
  assert.deepEqual(actions[1], { action: 'delete_task', data: { id: 7 } });
});
