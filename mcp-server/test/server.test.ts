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

test('tool profiles keep core focused and administrator tools opt-in', async (t) => {
  const fetchImplementation = async () => new Response('{}', { status: 200 });
  const coreClient = await connectedClient(t, { ...config, toolProfile: 'core' }, fetchImplementation);
  const coreNames = new Set((await coreClient.listTools()).tools.map((tool) => tool.name));
  assert.ok(coreNames.has('daily_briefing'));
  assert.ok(coreNames.has('project_plan'));
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
