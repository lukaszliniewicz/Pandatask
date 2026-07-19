import assert from 'node:assert/strict';
import test from 'node:test';
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
};

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
  }

  const preview = await mcpClient.callTool({
    name: 'task_create',
    arguments: { board_name: 'project_alpha', name: 'Preview me', priority: 8 },
  });
  assert.equal(fetchCalls, 0, 'Global dry-run must not call WordPress for a direct mutation.');
  assert.equal((preview.structuredContent as Record<string, unknown>).dry_run, true);

  const resources = await mcpClient.listResources();
  assert.ok(resources.resources.some((resource) => resource.uri === 'pandatask://guide'));
  const prompts = await mcpClient.listPrompts();
  assert.ok(prompts.prompts.some((prompt) => prompt.name === 'launch-project'));
});
