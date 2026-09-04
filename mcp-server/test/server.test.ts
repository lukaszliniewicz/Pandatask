import assert from 'node:assert/strict';
import test, { type TestContext } from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { PandataskClient } from '../src/client.js';
import type { PandataskConfig } from '../src/config.js';
import { createPandataskServer } from '../src/server.js';

const config: PandataskConfig = {
  siteUrl: 'https://example.com',
  apiBaseUrl: 'https://example.com/wp-json/pandatask/v1',
  username: 'wp-agent',
  appPassword: 'app-password',
  defaultDryRun: true,
  timeoutMs: 30000,
  allowInsecureHttp: false,
  toolProfile: 'admin',
  maxConcurrency: 5,
  maxCollectionItems: 1000,
};

async function connectedClient(
  testContext: TestContext,
  serverConfig: PandataskConfig,
  fetchImplementation: ConstructorParameters<typeof PandataskClient>[1],
): Promise<Client> {
  const server = createPandataskServer(new PandataskClient(serverConfig, fetchImplementation));
  const client = new Client({ name: 'pandatask-test', version: '1.0.0' });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  await Promise.all([server.connect(serverTransport), client.connect(clientTransport)]);
  testContext.after(async () => {
    await client.close();
    await server.close();
  });
  return client;
}

test('MCP server publishes annotated granular tools, workflows, resources, and prompts', async (t) => {
  let fetchCalls = 0;
  const pandatask = new PandataskClient(config, async () => {
    fetchCalls += 1;
    return new Response(JSON.stringify({ boards: [] }), { status: 200 });
  });
  const server = createPandataskServer(pandatask);
  const mcpClient = new Client({ name: 'pandatask-test', version: '1.0.0' });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  await Promise.all([server.connect(serverTransport), mcpClient.connect(clientTransport)]);
  t.after(async () => {
    await mcpClient.close();
    await server.close();
  });

  const tools = await mcpClient.listTools();
  assert.ok(tools.tools.length >= 35, `Expected at least 35 tools, got ${tools.tools.length}`);
  const names = new Set(tools.tools.map((tool) => tool.name));
  for (const expected of ['board_get_context', 'daily_briefing', 'task_create', 'task_bulk_update', 'project_plan', 'batch_execute']) {
    assert.ok(names.has(expected), `Missing ${expected}`);
  }
  for (const tool of tools.tools) {
    assert.equal(typeof tool.annotations?.readOnlyHint, 'boolean', `${tool.name} lacks readOnlyHint`);
    assert.equal(typeof tool.annotations?.openWorldHint, 'boolean', `${tool.name} lacks openWorldHint`);
    assert.equal(typeof tool.annotations?.destructiveHint, 'boolean', `${tool.name} lacks destructiveHint`);
    assert.ok(tool.outputSchema, `${tool.name} lacks outputSchema`);
  }
  assert.equal(tools.tools.find((tool) => tool.name === 'batch_execute')?.annotations?.idempotentHint, false);
  const taskListProperties = tools.tools.find((tool) => tool.name === 'task_list')?.inputSchema.properties ?? {};
  assert.ok('fields' in taskListProperties, 'task_list must advertise sparse-field selection.');
  assert.ok('assignee_id' in taskListProperties, 'task_list must advertise arbitrary assignee filtering.');
  assert.ok(!('response_mode' in taskListProperties), 'Read tools must not advertise mutation response modes.');
  const taskCreateProperties = tools.tools.find((tool) => tool.name === 'task_create')?.inputSchema.properties ?? {};
  assert.ok('name' in taskCreateProperties, 'Extending write schemas must preserve their original inputs.');
  assert.ok('response_mode' in taskCreateProperties, 'Write tools must advertise response_mode.');
  const taskCompleteProperties = tools.tools.find((tool) => tool.name === 'task_complete')?.inputSchema.properties ?? {};
  assert.ok('task_id' in taskCompleteProperties, 'Core work-tool schemas must preserve their original inputs.');
  assert.ok('response_mode' in taskCompleteProperties, 'Core work tools must advertise response_mode.');

  const preview = await mcpClient.callTool({
    name: 'task_create',
    arguments: { board_name: 'project_alpha', name: 'Preview me', priority: 8 },
  });
  assert.equal(fetchCalls, 0, 'Global dry-run must not call WordPress for a direct mutation.');
  const previewEnvelope = preview.structuredContent as Record<string, unknown>;
  assert.equal(previewEnvelope.ok, true);
  assert.equal((previewEnvelope.data as Record<string, unknown>).dry_run, true);
  const previewContent = preview.content as { type: string; text?: string }[];
  assert.deepEqual(JSON.parse(previewContent[0]?.type === 'text' ? (previewContent[0].text ?? '{}') : '{}'), previewEnvelope);

  const resources = await mcpClient.listResources();
  assert.ok(resources.resources.some((resource) => resource.uri === 'pandatask://guide'));
  const prompts = await mcpClient.listPrompts();
  assert.ok(prompts.prompts.some((prompt) => prompt.name === 'launch-project'));
});

test('work_log supports split allocations without double-counting the entry duration', async (t) => {
  const client = await connectedClient(t, config, async () => new Response('{}', { status: 200 }));

  const preview = await client.callTool({
    name: 'work_log',
    arguments: {
      title: 'Committee meeting',
      activity_type: 'meeting',
      work_date: '2026-08-24',
      duration_seconds: 5400,
      allocations: [
        { task_id: 10, seconds: 3600 },
        { task_id: 11, seconds: 1800 },
      ],
      idempotency_key: 'work-meeting-20260824',
    },
  });
  assert.equal(preview.isError, undefined);
  const envelope = preview.structuredContent as Record<string, unknown>;
  const data = envelope.data as Record<string, unknown>;
  const request = data.would_execute as Record<string, unknown>;
  const body = request.body as Record<string, unknown>;
  assert.equal(body.duration_seconds, 5400);
  assert.deepEqual(body.allocations, [
    { task_id: 10, seconds: 3600 },
    { task_id: 11, seconds: 1800 },
  ]);

  const boardPreview = await client.callTool({
    name: 'work_log',
    arguments: {
      title: 'Standalone trustee call',
      activity_type: 'call',
      work_date: '2026-08-24',
      duration_seconds: 1800,
      board_name: 'group_10',
      idempotency_key: 'work-board-20260824',
    },
  });
  assert.equal(boardPreview.isError, undefined);
  const boardEnvelope = boardPreview.structuredContent as Record<string, unknown>;
  const boardData = boardEnvelope.data as Record<string, unknown>;
  const boardRequest = boardData.would_execute as Record<string, unknown>;
  const boardBody = boardRequest.body as Record<string, unknown>;
  assert.deepEqual(boardBody.allocations, [{ board_name: 'group_10', seconds: 1800 }]);

  const overallocated = await client.callTool({
    name: 'work_log',
    arguments: {
      title: 'Impossible split',
      activity_type: 'meeting',
      work_date: '2026-08-24',
      duration_seconds: 3600,
      allocations: [
        { task_id: 10, seconds: 2400 },
        { task_id: 11, seconds: 2400 },
      ],
    },
  });
  assert.equal(overallocated.isError, true);

  const duplicate = await client.callTool({
    name: 'work_log',
    arguments: {
      title: 'Duplicate task split',
      activity_type: 'meeting',
      work_date: '2026-08-24',
      duration_seconds: 3600,
      allocations: [
        { task_id: 10, seconds: 1800 },
        { task_id: 10, seconds: 1800 },
      ],
    },
  });
  assert.equal(duplicate.isError, true);
});

test('task_time_log adds incremental task time without completing or resolving the task', async (t) => {
  const client = await connectedClient(t, config, async () => new Response('{}', { status: 200 }));

  const preview = await client.callTool({
    name: 'task_time_log',
    arguments: {
      task_id: 12,
      activity_type: 'development',
      work_date: '2026-08-24',
      duration_seconds: 2700,
      title: 'Implementation',
      idempotency_key: 'task-time-12-20260824-a',
    },
  });
  assert.equal(preview.isError, undefined);
  const envelope = preview.structuredContent as Record<string, unknown>;
  const data = envelope.data as Record<string, unknown>;
  const request = data.would_execute as Record<string, unknown>;
  assert.equal(request.method, 'POST');
  assert.ok(String(request.url || '').endsWith('/users/me/work-entries'));
  const body = request.body as Record<string, unknown>;
  assert.equal(body.duration_seconds, 2700);
  assert.deepEqual(body.allocations, [{ task_id: 12, seconds: 2700 }]);
});

test('work logging tools expose endpoint previews and support replacing allocations', async (t) => {
  const client = await connectedClient(t, config, async () => new Response(JSON.stringify({}), { status: 200 }));

  const update = await client.callTool({
    name: 'work_update',
    arguments: {
      entry_id: 42,
      title: 'Moved work',
      activity_type: 'community-outreach',
      duration_seconds: 5400,
      capacity: null,
      allocations: [{ task_id: 99, seconds: 5400, residual_handling: 'additional' }],
      idempotency_key: 'work-update-42',
    },
  });
  assert.equal(update.isError, undefined);
  const updateEnvelope = update.structuredContent as Record<string, unknown>;
  const updateRequest = (updateEnvelope.data as Record<string, unknown>).would_execute as Record<string, unknown>;
  assert.equal(updateRequest.method, 'PATCH');
  assert.ok(String(updateRequest.url).endsWith('/work-entries/42'));
  assert.deepEqual((updateRequest.body as Record<string, unknown>).allocations, [
    { task_id: 99, seconds: 5400, residual_handling: 'additional' },
  ]);
  assert.equal((updateRequest.body as Record<string, unknown>).capacity, null);
  assert.equal(updateRequest.idempotency_key, 'work-update-42');

  const detach = await client.callTool({ name: 'work_update', arguments: { entry_id: 42, allocations: [] } });
  assert.equal(detach.isError, undefined);
  const detachEnvelope = detach.structuredContent as Record<string, unknown>;
  const detachRequest = (detachEnvelope.data as Record<string, unknown>).would_execute as Record<string, unknown>;
  assert.deepEqual((detachRequest.body as Record<string, unknown>).allocations, []);

  const deleted = await client.callTool({
    name: 'work_delete',
    arguments: { entry_id: 42, idempotency_key: 'work-delete-42' },
  });
  assert.equal(deleted.isError, undefined);
  const deleteEnvelope = deleted.structuredContent as Record<string, unknown>;
  const deleteRequest = (deleteEnvelope.data as Record<string, unknown>).would_execute as Record<string, unknown>;
  assert.equal(deleteRequest.method, 'DELETE');
  assert.ok(String(deleteRequest.url).endsWith('/work-entries/42'));
  assert.equal(deleteRequest.idempotency_key, 'work-delete-42');
});

test('work read tools use the task and entry endpoints', async (t) => {
  const calls: { method: string; path: string }[] = [];
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async (input, init) => {
    const url = new URL(String(input));
    calls.push({ method: init?.method ?? 'GET', path: url.pathname });
    return new Response(JSON.stringify({}), { status: 200 });
  });

  for (const [name, arguments_] of [
    ['task_work_get', { task_id: 12 }],
    ['work_get', { entry_id: 42 }],
    ['work_type_list', {}],
  ] as const) {
    const result = await client.callTool({ name, arguments: arguments_ });
    assert.equal(result.isError, undefined);
  }

  assert.deepEqual(calls, [
    { method: 'GET', path: '/wp-json/pandatask/v1/tasks/12/work' },
    { method: 'GET', path: '/wp-json/pandatask/v1/work-entries/42' },
    { method: 'GET', path: '/wp-json/pandatask/v1/work/activity-types' },
  ]);
});

test('work logging validates replacement allocations and accepts custom activity keys', async (t) => {
  const client = await connectedClient(t, config, async () => new Response(JSON.stringify({}), { status: 200 }));

  const custom = await client.callTool({
    name: 'task_time_log',
    arguments: { task_id: 12, activity_type: 'community-outreach', duration_seconds: 900 },
  });
  assert.equal(custom.isError, undefined);

  const duplicate = await client.callTool({
    name: 'work_update',
    arguments: {
      entry_id: 42,
      duration_seconds: 3600,
      allocations: [{ task_id: 10, seconds: 1800 }, { task_id: 10, seconds: 1800 }],
    },
  });
  assert.equal(duplicate.isError, true);

  const overallocated = await client.callTool({
    name: 'work_update',
    arguments: {
      entry_id: 42,
      duration_seconds: 3600,
      allocations: [{ task_id: 10, seconds: 2400 }, { board_name: 'group_10', seconds: 2400 }],
    },
  });
  assert.equal(overallocated.isError, true);
});

test('work type tools use the activity type routes and reversible archive semantics', async (t) => {
  const client = await connectedClient(t, config, async () => new Response(JSON.stringify({}), { status: 200 }));

  const created = await client.callTool({
    name: 'work_type_create',
    arguments: { label: 'Community Outreach', idempotency_key: 'work-type-create-1' },
  });
  const createdRequest = ((created.structuredContent as Record<string, unknown>).data as Record<string, unknown>).would_execute as Record<string, unknown>;
  assert.equal(createdRequest.method, 'POST');
  assert.ok(String(createdRequest.url).endsWith('/work/activity-types'));
  assert.deepEqual(createdRequest.body, { label: 'Community Outreach' });
  assert.equal(createdRequest.idempotency_key, 'work-type-create-1');

  const updated = await client.callTool({
    name: 'work_type_update',
    arguments: { key: 'community-outreach', label: 'Trustee outreach', is_active: false },
  });
  const updatedRequest = ((updated.structuredContent as Record<string, unknown>).data as Record<string, unknown>).would_execute as Record<string, unknown>;
  assert.equal(updatedRequest.method, 'PATCH');
  assert.ok(String(updatedRequest.url).endsWith('/work/activity-types/community-outreach'));
  assert.deepEqual(updatedRequest.body, { label: 'Trustee outreach', is_active: false });

  const archived = await client.callTool({ name: 'work_type_archive', arguments: { key: 'community-outreach' } });
  const archivedRequest = ((archived.structuredContent as Record<string, unknown>).data as Record<string, unknown>).would_execute as Record<string, unknown>;
  assert.equal(archivedRequest.method, 'DELETE');
  assert.ok(String(archivedRequest.url).endsWith('/work/activity-types/community-outreach'));
});

test('task_list_visible uses one boardless request and work_report forwards named periods', async (t) => {
  const calls: URL[] = [];
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async (input) => {
    const url = new URL(String(input));
    calls.push(url);
    if (url.pathname.endsWith('/tasks')) return new Response(JSON.stringify({ tasks: [] }), { status: 200 });
    if (url.pathname.endsWith('/work-report')) return new Response(JSON.stringify({ report: {} }), { status: 200 });
    return new Response(JSON.stringify({}), { status: 200 });
  });

  const visible = await client.callTool({
    name: 'task_list_visible',
    arguments: {
      search: 'trustees',
      status: 'all',
      sort: 'name_asc',
      archived: false,
      assigned_to_me: true,
      include_templates: false,
      task_type: 'task',
      limit: 25,
      offset: 50,
    },
  });
  assert.equal(visible.isError, undefined);
  assert.equal(calls.length, 1);
  assert.equal(calls[0]?.pathname, '/wp-json/pandatask/v1/users/me/tasks');
  assert.equal(calls[0]?.searchParams.get('search'), 'trustees');
  assert.equal(calls[0]?.searchParams.get('assigned_to_me'), 'true');
  assert.equal(calls[0]?.searchParams.get('private_only'), null);

  await client.callTool({ name: 'work_report', arguments: { period: 'last_30_days' } });
  assert.equal(calls.length, 2);
  assert.equal(calls[1]?.pathname, '/wp-json/pandatask/v1/users/me/work-report');
  assert.equal(calls[1]?.searchParams.get('period'), 'last_30_days');

  await client.callTool({ name: 'work_report', arguments: {} });
  assert.equal(calls.length, 3);
  assert.equal(calls[2]?.pathname, '/wp-json/pandatask/v1/users/me/work-report');
  assert.equal(calls[2]?.searchParams.get('period'), null, 'Omitting period must preserve the REST API last-30-days default.');
});

test('task list tools forward arbitrary assignee and sparse-field filters', async (t) => {
  const calls: URL[] = [];
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async (input) => {
    calls.push(new URL(String(input)));
    return new Response(JSON.stringify({ tasks: [], pagination: { has_more: false, next_offset: null } }), { status: 200 });
  });

  await client.callTool({
    name: 'task_list',
    arguments: {
      board_name: 'group_10',
      search: 'projection',
      assignee_id: 8,
      fields: ['name', 'description'],
    },
  });
  assert.equal(calls[0]?.pathname, '/wp-json/pandatask/v1/boards/group_10/tasks');
  assert.equal(calls[0]?.searchParams.get('assignee_id'), '8');
  assert.equal(calls[0]?.searchParams.get('fields'), 'name,description');

  await client.callTool({
    name: 'task_list_visible',
    arguments: { assignee_id: 8, fields: ['id', 'name'], limit: 25 },
  });
  assert.equal(calls[1]?.pathname, '/wp-json/pandatask/v1/users/me/tasks');
  assert.equal(calls[1]?.searchParams.get('assignee_id'), '8');
  assert.equal(calls[1]?.searchParams.get('fields'), 'id,name');

  const beforeConflict = calls.length;
  const conflict = await client.callTool({
    name: 'task_list',
    arguments: { board_name: 'group_10', assigned_to_me: true, assignee_id: 8 },
  });
  assert.equal(conflict.isError, true);
  assert.equal(calls.length, beforeConflict, 'Conflicting assignment filters must fail before REST access.');
});

test('project workspace and reference tools map REST contracts, previews, and response modes', async (t) => {
  const calls: { method: string; path: string; body: Record<string, unknown> | undefined; idempotency: string | undefined }[] = [];
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async (input, init) => {
    const url = new URL(String(input));
    const method = init?.method ?? 'GET';
    const headers = init?.headers as Record<string, string> | undefined;
    const body = init?.body === undefined ? undefined : JSON.parse(String(init.body)) as Record<string, unknown>;
    calls.push({ method, path: url.pathname, body, idempotency: headers?.['Idempotency-Key'] });

    if (url.pathname.endsWith('/workspace')) {
      return new Response(JSON.stringify({ project: { id: 8 }, tasks: [], dependencies: [], references: [], counts: {} }), { status: 200 });
    }
    if (url.pathname.endsWith('/references/export')) {
		return new Response(JSON.stringify({ version: 1, references: [{ relation_type: 'related', task_id: 12 }], omitted_restricted: 0 }), { status: 200 });
    }
    if (url.pathname.endsWith('/references') && method === 'GET') {
      return new Response(JSON.stringify({ references: [{ relation_type: 'included', task_id: 12 }], counts: { included: 1 } }), { status: 200 });
    }
    if (method === 'POST' && url.pathname.endsWith('/references/import')) {
      return new Response(JSON.stringify({ created: 1, skipped: 1, errors: [{ index: 1, code: 'restricted' }] }), { status: 200 });
    }
    if (method === 'POST' && url.pathname.endsWith('/references')) {
      return new Response(JSON.stringify({ message: 'Reference added', reference: { reference_key: 'reference-9', relation_type: 'included', task_id: 12, task_key: 'task-12', restricted: false, secret: 'omit' } }), { status: 201 });
    }
    if (method === 'PATCH') {
      return new Response(JSON.stringify({ message: 'Reference updated', reference: { reference_key: 'reference-9', relation_type: 'related', task_id: 12, rich_data: 'keep in full' } }), { status: 200 });
    }
    if (method === 'DELETE') {
      return new Response(JSON.stringify({ message: 'Reference removed' }), { status: 200 });
    }
    return new Response('{}', { status: 200 });
  });

  const workspace = await client.callTool({ name: 'project_workspace_get', arguments: { project_id: 8 } });
  assert.equal(workspace.isError, undefined);
  const references = await client.callTool({ name: 'project_reference_list', arguments: { project_id: 8 } });
  assert.equal(references.isError, undefined);
  const exported = await client.callTool({ name: 'project_reference_export', arguments: { project_id: 8 } });
  assert.equal(exported.isError, undefined);

  const added = await client.callTool({
    name: 'project_reference_add',
    arguments: { project_id: 8, relation_type: 'included', task_id: 12, idempotency_key: 'project-reference-add-8' },
  });
  assert.equal(added.isError, undefined);
  const addedData = (added.structuredContent as Record<string, unknown>).data as Record<string, unknown>;
  assert.equal(addedData.operation, 'project_reference_add');
  assert.equal(addedData.message, 'Reference added');
  assert.deepEqual(addedData.reference, { reference_key: 'reference-9', relation_type: 'included', task_id: 12, task_key: 'task-12', restricted: false });

  const updated = await client.callTool({
    name: 'project_reference_update',
    arguments: { project_id: 8, reference_key: 'reference-9', relation_type: 'related', response_mode: 'full', idempotency_key: 'project-reference-update-8' },
  });
  assert.equal(updated.isError, undefined);
  assert.equal((((updated.structuredContent as Record<string, unknown>).data as Record<string, unknown>).reference as Record<string, unknown>).rich_data, 'keep in full');

  const removed = await client.callTool({
    name: 'project_reference_remove',
    arguments: { project_id: 8, reference_key: 'dependency-7', idempotency_key: 'project-reference-remove-8' },
  });
  const removedData = (removed.structuredContent as Record<string, unknown>).data as Record<string, unknown>;
  assert.equal(removedData.operation, 'project_reference_remove');
  assert.equal(removedData.project_id, 8);
  assert.equal(removedData.reference_key, 'dependency-7');
  assert.equal(removedData.message, 'Reference removed');

  const imported = await client.callTool({
    name: 'project_reference_import',
    arguments: {
      project_id: 8,
      references: [
        { relation_type: 'related', task_id: 12 },
        { relation_type: 'dependency', predecessor_task_id: 12, successor_task_id: 13 },
      ],
      idempotency_key: 'project-reference-import-8',
    },
  });
  const importedData = (imported.structuredContent as Record<string, unknown>).data as Record<string, unknown>;
  assert.equal(importedData.created, 1);
  assert.equal(importedData.skipped, 1);
  assert.deepEqual(importedData.errors, [{ index: 1, code: 'restricted' }]);

  const beforePreview = calls.length;
  const preview = await client.callTool({
    name: 'project_reference_add',
    arguments: {
      project_id: 8,
      relation_type: 'dependency',
      predecessor_task_id: 12,
      successor_task_id: 13,
      dry_run: true,
      idempotency_key: 'project-reference-preview-8',
    },
  });
  assert.equal(calls.length, beforePreview, 'Reference dry-run must not send a mutation.');
  const previewRequest = ((preview.structuredContent as Record<string, unknown>).data as Record<string, unknown>).would_execute as Record<string, unknown>;
  assert.equal(previewRequest.method, 'POST');
  assert.ok(String(previewRequest.url).endsWith('/projects/8/references'));
  assert.deepEqual(previewRequest.body, { relation_type: 'dependency', predecessor_task_id: 12, successor_task_id: 13 });
  assert.equal(previewRequest.idempotency_key, 'project-reference-preview-8');

  assert.deepEqual(calls.map(({ method, path }) => ({ method, path })), [
    { method: 'GET', path: '/wp-json/pandatask/v1/projects/8/workspace' },
    { method: 'GET', path: '/wp-json/pandatask/v1/projects/8/references' },
    { method: 'GET', path: '/wp-json/pandatask/v1/projects/8/references/export' },
    { method: 'POST', path: '/wp-json/pandatask/v1/projects/8/references' },
    { method: 'PATCH', path: '/wp-json/pandatask/v1/projects/8/references/reference-9' },
    { method: 'DELETE', path: '/wp-json/pandatask/v1/projects/8/references/dependency-7' },
    { method: 'POST', path: '/wp-json/pandatask/v1/projects/8/references/import' },
  ]);
  assert.deepEqual(calls[3]?.body, { relation_type: 'included', task_id: 12 });
  assert.equal(calls[3]?.idempotency, 'project-reference-add-8');
  assert.deepEqual(calls[4]?.body, { relation_type: 'related' });
  assert.equal(calls[4]?.idempotency, 'project-reference-update-8');
  assert.equal(calls[5]?.idempotency, 'project-reference-remove-8');
  assert.deepEqual(calls[6]?.body, {
    version: 1,
    references: [
      { relation_type: 'related', task_id: 12 },
      { relation_type: 'dependency', predecessor_task_id: 12, successor_task_id: 13 },
    ],
  });
  assert.equal(calls[6]?.idempotency, 'project-reference-import-8');
});

test('executed MCP writes default to minimal responses with an explicit full override', async (t) => {
  const requestBodies: Record<string, unknown>[] = [];
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async (_input, init) => {
    requestBodies.push(JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>);
    return new Response(JSON.stringify({
      message: 'Task added',
      task: {
        id: 77,
        board_name: 'group_10',
        name: 'Response shaping',
        status: 'pending',
        description: 'A deliberately verbose description.',
        description_rendered: '<p>A deliberately verbose description.</p>',
        comments: [{ id: 1, comment_text: 'Not needed in a mutation receipt.' }],
      },
    }), { status: 201 });
  });

  const minimal = await client.callTool({
    name: 'task_create',
    arguments: { board_name: 'group_10', name: 'Response shaping' },
  });
  const minimalData = (minimal.structuredContent as Record<string, unknown>).data as Record<string, unknown>;
  assert.equal(minimalData.operation, 'task_create');
  assert.deepEqual(minimalData.task, {
    id: 77,
    board_name: 'group_10',
    name: 'Response shaping',
    status: 'pending',
  });
  assert.equal((requestBodies[0] as Record<string, unknown>).response_mode, undefined, 'MCP-only response_mode must not leak into REST bodies.');

  const full = await client.callTool({
    name: 'task_create',
    arguments: { board_name: 'group_10', name: 'Response shaping', response_mode: 'full' },
  });
  const fullData = (full.structuredContent as Record<string, unknown>).data as Record<string, unknown>;
  assert.equal((fullData.task as Record<string, unknown>).description, 'A deliberately verbose description.');
  assert.equal(requestBodies[1]?.response_mode, undefined, 'Full response selection must remain MCP-local.');
});

test('minimal idempotent replay receipts retain replay flags and omit rich task fields', async (t) => {
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async () => new Response(JSON.stringify({
    message: 'Task already created',
    idempotent: true,
    replayed: true,
    task: {
      id: 77,
      board_name: 'group_10',
      name: 'Replay me',
      status: 'pending',
      description: 'A rich replay payload that is not needed in a minimal receipt.',
      description_rendered: '<p>A rich replay payload that is not needed in a minimal receipt.</p>',
      comments: [{ id: 1, comment_text: 'Omit this history.' }],
    },
  }), { status: 200 }));

  const replay = await client.callTool({
    name: 'task_create',
    arguments: {
      board_name: 'group_10',
      name: 'Replay me',
      idempotency_key: 'replay-task-77',
      response_mode: 'minimal',
    },
  });
  assert.equal(replay.isError, undefined);
  assert.deepEqual((replay.structuredContent as Record<string, unknown>).data, {
    operation: 'task_create',
    message: 'Task already created',
    idempotent: true,
    replayed: true,
    task: {
      id: 77,
      board_name: 'group_10',
      name: 'Replay me',
      status: 'pending',
    },
    board_name: 'group_10',
  });
});

test('idempotency conflicts remain actionable 409 MCP error envelopes', async (t) => {
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async () => new Response(JSON.stringify({
    code: 'pandatask_idempotency_conflict',
    message: 'This idempotency key was already used with a different request.',
    data: { status: 409 },
  }), { status: 409 }));

  const conflict = await client.callTool({
    name: 'task_create',
    arguments: {
      board_name: 'group_10',
      name: 'Conflict me',
      idempotency_key: 'conflict-task-77',
      response_mode: 'minimal',
    },
  });
  assert.equal(conflict.isError, true);
  assert.deepEqual((conflict.structuredContent as Record<string, unknown>).error, {
    code: 'pandatask_idempotency_conflict',
    message: 'This idempotency key was already used with a different request.',
    http_status: 409,
    details: {
      code: 'pandatask_idempotency_conflict',
      message: 'This idempotency key was already used with a different request.',
      data: { status: 409 },
    },
  });
});

test('task_list_visible aggregates pages with broad defaults and accurate metadata', async (t) => {
  const calls: URL[] = [];
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async (input) => {
    const url = new URL(String(input));
    calls.push(url);
    if (!url.pathname.endsWith('/tasks')) return new Response(JSON.stringify({}), { status: 200 });

    const offset = Number(url.searchParams.get('offset'));
    if (offset === 0) {
      return new Response(JSON.stringify({
        tasks: [{ id: 101, name: 'First' }, { id: 102, name: 'Second' }],
        pagination: { limit: 100, offset: 0, returned: 2, has_more: true, next_offset: 2 },
      }), { status: 200 });
    }
    return new Response(JSON.stringify({
      tasks: [{ id: 103, name: 'Third' }],
      pagination: { limit: 100, offset: 2, returned: 1, has_more: false, next_offset: null },
    }), { status: 200 });
  });

  const result = await client.callTool({ name: 'task_list_visible', arguments: {} });
  assert.equal(result.isError, undefined);
  assert.equal(calls.length, 2);
  assert.equal(calls[0]?.pathname, '/wp-json/pandatask/v1/users/me/tasks');
  assert.equal(calls[0]?.searchParams.get('status_filter'), 'all', 'Default visible task listing must request every status explicitly.');
  assert.equal(calls[0]?.searchParams.get('archived'), null, 'Omitting the archive filter must include active and archived tasks.');
  assert.equal(calls[0]?.searchParams.get('include_templates'), 'true', 'Default visible task listing must include templates.');
  assert.equal(calls[0]?.searchParams.get('private_only'), null);
  assert.equal(calls[0]?.searchParams.get('sort'), 'created_at_desc', 'Default task ordering must show newly added tasks first.');
  assert.equal(calls[1]?.searchParams.get('offset'), '2');

  const envelope = result.structuredContent as Record<string, unknown>;
  const data = envelope.data as Record<string, unknown>;
  assert.deepEqual((data.tasks as Record<string, unknown>[]).map((task) => task.id), [101, 102, 103]);
  assert.deepEqual(data.pagination, {
    limit: 100,
    offset: 0,
    returned: 3,
    total: 3,
    has_more: false,
    next_offset: null,
    pages: 2,
    truncated: false,
    cap: 1000,
  });
});

test('task_list_visible surfaces truncation at the configured collection cap', async (t) => {
  const calls: URL[] = [];
  const client = await connectedClient(t, { ...config, defaultDryRun: false, maxCollectionItems: 2 }, async (input) => {
    const url = new URL(String(input));
    calls.push(url);
    return new Response(JSON.stringify({
      tasks: [{ id: 201 }, { id: 202 }, { id: 203 }],
      pagination: { limit: 3, offset: 0, returned: 3, has_more: true, next_offset: 3 },
    }), { status: 200 });
  });

  const result = await client.callTool({ name: 'task_list_visible', arguments: {} });
  assert.equal(result.isError, undefined);
  assert.equal(calls.length, 1);
  const data = (result.structuredContent as Record<string, unknown>).data as Record<string, unknown>;
  assert.deepEqual((data.tasks as Record<string, unknown>[]).map((task) => task.id), [201, 202]);
  const pagination = data.pagination as Record<string, unknown>;
  assert.equal(pagination.returned, 2);
  assert.equal(pagination.total, null);
  assert.equal(pagination.has_more, true);
  assert.equal(pagination.truncated, true);
  assert.equal(pagination.cap, 2);
});

test('task status tool keeps completion on the time-aware boundary', async (t) => {
  const client = await connectedClient(t, config, async () => new Response('{}', { status: 200 }));
  const tools = await client.listTools();
  assert.ok(tools.tools.some((tool) => tool.name === 'task_complete'));

  const done = await client.callTool({
    name: 'task_set_status',
    arguments: { task_id: 10, status: 'done' },
  });
  assert.equal(done.isError, true);
});

test('all task creation and bulk-update paths reject done before mutation', async (t) => {
  let fetchCalls = 0;
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async () => {
    fetchCalls += 1;
    return new Response('{}', { status: 200 });
  });

  const cases = [
    {
      name: 'task_create_subtask',
      arguments: { board_name: 'project_alpha', parent_task_id: 10, name: 'Done subtask', status: 'done' },
    },
    {
      name: 'project_plan',
      arguments: { board_name: 'project_alpha', project: { name: 'Done plan' }, tasks: [{ name: 'Done plan task', status: 'done' }] },
    },
    {
      name: 'task_bulk_update',
      arguments: { updates: [{ task_id: 10, changes: { status: 'done' } }] },
    },
  ] as const;

  for (const item of cases) {
    const result = await client.callTool({ name: item.name, arguments: item.arguments });
    assert.equal(result.isError, true, `${item.name} must keep completion behind task_complete`);
  }
  assert.equal(fetchCalls, 0, 'Rejected completion bypasses must not reach WordPress');
});

test('tool profiles keep core focused and administrator tools opt-in', async (t) => {
  const fetchImplementation = async () => new Response('{}', { status: 200 });
  const coreClient = await connectedClient(t, { ...config, toolProfile: 'core' }, fetchImplementation);
  const coreNames = new Set((await coreClient.listTools()).tools.map((tool) => tool.name));
  assert.ok(coreNames.has('daily_briefing'));
  assert.ok(coreNames.has('project_plan'));
  for (const name of [
    'project_workspace_get',
    'project_reference_list',
    'project_reference_add',
    'project_reference_update',
    'project_reference_remove',
    'project_reference_export',
    'project_reference_import',
  ]) {
    assert.ok(coreNames.has(name));
  }
  assert.ok(coreNames.has('task_complete'));
  assert.ok(coreNames.has('task_time_resolve'));
  assert.ok(coreNames.has('task_time_log'));
  assert.ok(coreNames.has('task_list_visible'));
  assert.ok(coreNames.has('task_work_get'));
  assert.ok(coreNames.has('work_log'));
  assert.ok(coreNames.has('work_list'));
  assert.ok(coreNames.has('work_get'));
  assert.ok(coreNames.has('work_update'));
  assert.ok(coreNames.has('work_delete'));
  assert.ok(coreNames.has('work_type_list'));
  assert.ok(coreNames.has('work_type_create'));
  assert.ok(coreNames.has('work_type_update'));
  assert.ok(coreNames.has('work_type_archive'));
  assert.ok(coreNames.has('work_report'));
  assert.equal(coreNames.size, 52);
  assert.equal(coreNames.has('task_delete'), false);
  assert.equal(coreNames.has('batch_execute'), false);

  const fullClient = await connectedClient(t, { ...config, toolProfile: 'full' }, fetchImplementation);
  const fullNames = new Set((await fullClient.listTools()).tools.map((tool) => tool.name));
  assert.ok(fullNames.has('task_delete'));
  assert.equal(fullNames.has('batch_execute'), false);

  const adminClient = await connectedClient(t, { ...config, toolProfile: 'admin' }, fetchImplementation);
  const adminNames = new Set((await adminClient.listTools()).tools.map((tool) => tool.name));
  assert.ok(adminNames.has('batch_execute'));
  assert.ok(adminNames.has('board_list'));
  assert.ok(adminNames.size > fullNames.size);
});

test('project_plan rejects invalid dependency graphs before any WordPress request', async (t) => {
  let fetchCalls = 0;
  const client = await connectedClient(
    t,
    { ...config, defaultDryRun: false },
    async () => {
      fetchCalls += 1;
      return new Response('{}', { status: 200 });
    },
  );

  const result = await client.callTool({
    name: 'project_plan',
    arguments: {
      board_name: 'project_alpha',
      project: { name: 'Invalid' },
      tasks: [{ name: 'First', depends_on_task_indexes: [0] }],
    },
  });
  assert.equal(result.isError, true);
  assert.equal(fetchCalls, 0);
});

test('project_plan rolls back created work after an unkeyed task failure', async (t) => {
  const calls: { method: string; path: string }[] = [];
  let taskCreates = 0;
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async (input, init) => {
    const url = new URL(String(input));
    const method = init?.method ?? 'GET';
    calls.push({ method, path: url.pathname });
    if (method === 'POST' && url.pathname.endsWith('/projects')) {
      return new Response(JSON.stringify({ project: { id: 10 } }), { status: 201 });
    }
    if (method === 'POST' && url.pathname.endsWith('/tasks')) {
      taskCreates += 1;
      if (taskCreates === 1) return new Response(JSON.stringify({ task: { id: 20 } }), { status: 201 });
      return new Response(JSON.stringify({ code: 'rest_error', message: 'Task failed' }), { status: 500 });
    }
    return new Response(JSON.stringify({ message: 'Deleted' }), { status: 200 });
  });

  const result = await client.callTool({
    name: 'project_plan',
    arguments: {
      board_name: 'project_alpha',
      project: { name: 'Rollback' },
      tasks: [{ name: 'First' }, { name: 'Second', depends_on_task_indexes: [0] }],
    },
  });
  assert.equal(result.isError, true);
  const envelope = result.structuredContent as Record<string, unknown>;
  assert.equal((envelope.error as Record<string, unknown>).code, 'project_plan_rolled_back');
  assert.deepEqual(calls.slice(-2).map((call) => call.method), ['DELETE', 'DELETE']);
  assert.ok(calls.at(-2)?.path.endsWith('/tasks/20'));
  assert.ok(calls.at(-1)?.path.endsWith('/projects/10'));
});

test('project_plan preserves keyed partial work for an idempotent retry', async (t) => {
  const calls: { method: string; path: string; idempotencyKey: string | null }[] = [];
  let taskCreates = 0;
  const client = await connectedClient(t, { ...config, defaultDryRun: false }, async (input, init) => {
    const url = new URL(String(input));
    const method = init?.method ?? 'GET';
    calls.push({ method, path: url.pathname, idempotencyKey: new Headers(init?.headers).get('Idempotency-Key') });
    if (method === 'POST' && url.pathname.endsWith('/projects')) {
      return new Response(JSON.stringify({ project: { id: 10 } }), { status: 201 });
    }
    if (method === 'POST' && url.pathname.endsWith('/tasks')) {
      taskCreates += 1;
      if (taskCreates === 1) return new Response(JSON.stringify({ task: { id: 20 } }), { status: 201 });
      return new Response(JSON.stringify({ code: 'rest_error', message: 'Task failed' }), { status: 500 });
    }
    return new Response(JSON.stringify({ message: 'Deleted' }), { status: 200 });
  });

  const result = await client.callTool({
    name: 'project_plan',
    arguments: {
      board_name: 'project_alpha',
      project: { name: 'Retryable' },
      tasks: [{ name: 'First' }, { name: 'Second', depends_on_task_indexes: [0] }],
      idempotency_key: 'launch-2026-07-24',
    },
  });

  assert.equal(result.isError, true);
  const envelope = result.structuredContent as Record<string, unknown>;
  assert.equal((envelope.error as Record<string, unknown>).code, 'project_plan_resumable_failure');
  assert.equal(calls.some((call) => call.method === 'DELETE'), false);
  assert.deepEqual(
    calls.filter((call) => call.method === 'POST').map((call) => call.idempotencyKey),
    ['launch-2026-07-24:project', 'launch-2026-07-24:task-0', 'launch-2026-07-24:task-1'],
  );
});

test('daily_briefing keeps user boards private and bounds board concurrency', async (t) => {
  const taskQueries: { board: string; privateOnly: string | null }[] = [];
  let activeTaskRequests = 0;
  let peakTaskRequests = 0;
  const client = await connectedClient(t, { ...config, defaultDryRun: false, maxConcurrency: 1 }, async (input) => {
    const url = new URL(String(input));
    if (url.pathname.endsWith('/meta')) {
      return new Response(JSON.stringify({ today: '2026-07-24', timezone: 'Europe/Warsaw' }), { status: 200 });
    }
    if (url.pathname.endsWith('/users/me/boards')) {
      return new Response(JSON.stringify({ boards: [{ id: 'user_7' }, { id: 'group_1' }] }), { status: 200 });
    }
    if (url.pathname.endsWith('/tasks')) {
      const board = url.pathname.split('/').at(-2) ?? '';
      taskQueries.push({ board, privateOnly: url.searchParams.get('private_only') });
      activeTaskRequests += 1;
      peakTaskRequests = Math.max(peakTaskRequests, activeTaskRequests);
      await new Promise((resolve) => setTimeout(resolve, 5));
      activeTaskRequests -= 1;
      return new Response(JSON.stringify({ tasks: [] }), { status: 200 });
    }
    return new Response('{}', { status: 200 });
  });

  const result = await client.callTool({ name: 'daily_briefing', arguments: {} });
  assert.equal(result.isError, undefined);
  assert.deepEqual(taskQueries, [
    { board: 'user_7', privateOnly: 'true' },
    { board: 'group_1', privateOnly: 'false' },
  ]);
  assert.equal(peakTaskRequests, 1);
});
