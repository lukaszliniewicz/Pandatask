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

test('minimal mutation results preserve identity and state while removing rich record payloads', () => {
  const result = toolResult(
    {
      message: 'Task added',
      task: {
        id: 7,
        board_name: 'group_10',
        name: 'Compact me',
        status: 'pending',
        description: 'Large instructions that are not needed in the write receipt.',
        description_rendered: '<p>Large instructions that are not needed in the write receipt.</p>',
        comments: [{ id: 9, comment_text: 'Also unnecessary here.' }],
        assigned_users: [{ id: 8, name: 'Luke', avatar: 'https://example.com/avatar.png' }],
      },
    },
    { responseMode: 'minimal', operation: 'task_create', input: { board_name: 'group_10' } },
  );
  assert.deepEqual(result.structuredContent, {
    ok: true,
    data: {
      operation: 'task_create',
      message: 'Task added',
      task: { id: 7, board_name: 'group_10', name: 'Compact me', status: 'pending' },
      board_name: 'group_10',
    },
  });
});

test('full mutation and dry-run responses remain unprojected', () => {
  const fullValue = { message: 'Task added', task: { id: 7, description: 'Keep me.' } };
  assert.deepEqual(toolResult(fullValue, { responseMode: 'full', operation: 'task_create' }).structuredContent, {
    ok: true,
    data: fullValue,
  });

  const preview = { dry_run: true, would_execute: { method: 'POST', body: { description: 'Keep preview detail.' } } };
  assert.deepEqual(toolResult(preview, { responseMode: 'minimal', operation: 'task_create' }).structuredContent, {
    ok: true,
    data: preview,
  });
});

test('minimal work and batch receipts retain created keys, time state, and per-item outcomes', () => {
  const workType = toolResult(
    { activity_type: { key: 'community-outreach', label: 'Community outreach', is_active: true, owner_user_id: 8 } },
    { responseMode: 'minimal', operation: 'work_type_create' },
  );
  assert.deepEqual(workType.structuredContent?.data, {
    operation: 'work_type_create',
    message: 'Pandatask mutation completed.',
    activity_type: { key: 'community-outreach', label: 'Community outreach', is_active: true, owner_user_id: 8 },
  });

  const resolution = toolResult(
    {
      time: {
        occurrence: { id: 31, task_id: 7, sequence_number: 2, state: 'completed', task_name_snapshot: 'Verbose snapshot' },
        specific_seconds: 1200,
        resolution: { id: 44, occurrence_id: 31, state: 'resolved', declared_actual_seconds: 1800, residual_entry_id: 55 },
      },
    },
    { responseMode: 'minimal', operation: 'task_time_resolve', input: { task_id: 7 } },
  );
  assert.deepEqual(resolution.structuredContent?.data, {
    operation: 'task_time_resolve',
    message: 'Pandatask mutation completed.',
    task_id: 7,
    time: {
      occurrence: { id: 31, task_id: 7, sequence_number: 2, state: 'completed' },
      specific_seconds: 1200,
      resolution: { id: 44, occurrence_id: 31, state: 'resolved', declared_actual_seconds: 1800, residual_entry_id: 55 },
    },
  });

  const batch = toolResult(
    {
      results: [
        { success: true, action_description: 'create_task (A)', message: 'Success. ID: 9' },
        { success: false, action_description: 'update_task (10)', message: 'Denied', internal_trace: 'omit me' },
      ],
    },
    { responseMode: 'minimal', operation: 'batch_execute' },
  );
  assert.deepEqual(batch.structuredContent?.data, {
    operation: 'batch_execute',
    message: 'Pandatask mutation completed.',
    results: [
      { success: true, action_description: 'create_task (A)', message: 'Success. ID: 9' },
      { success: false, action_description: 'update_task (10)', message: 'Denied' },
    ],
  });
});
