import assert from 'node:assert/strict';
import test from 'node:test';
import { toolResult } from '../src/result.js';

test('tool results serialize ordinary structured output for client compatibility', () => {
  const result = toolResult({ task: { id: 7 } });
  assert.deepEqual(JSON.parse(result.content[0]?.type === 'text' ? result.content[0].text : '{}'), result.structuredContent);
});

test('tool results bound duplicated text while preserving complete structured output', () => {
  const result = toolResult({ description: 'x'.repeat(70_000) });
  const text = JSON.parse(result.content[0]?.type === 'text' ? result.content[0].text : '{}') as Record<string, unknown>;
  assert.equal(text.truncated_text, true);
  assert.equal((result.structuredContent?.data as Record<string, unknown>).description, 'x'.repeat(70_000));
});
