import assert from 'node:assert/strict';
import test from 'node:test';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

test('fresh stdio core profile advertises the required tools and response contracts', async (t) => {
  const testDirectory = dirname(fileURLToPath(import.meta.url));
  const packageDirectory = resolve(testDirectory, '..');
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [resolve(packageDirectory, 'src/index.js')],
    cwd: packageDirectory,
    stderr: 'ignore',
    env: {
      PANDATASK_URL: 'https://discovery.example.test',
      PANDATASK_USERNAME: 'discovery-user',
      PANDATASK_APP_PASSWORD: 'discovery-password',
      PANDATASK_TOOL_PROFILE: 'core',
    },
  });
  const client = new Client({ name: 'pandatask-discovery-test', version: '1.0.0' });
  t.after(async () => {
    await client.close();
  });

  await client.connect(transport);
  const listed = await client.listTools();
  const names = new Set(listed.tools.map((tool) => tool.name));
  for (const required of [
    'task_complete',
    'task_time_log',
    'task_time_resolve',
    'work_log',
    'work_list',
    'work_report',
  ]) {
    assert.ok(names.has(required), `Core profile is missing ${required}.`);
  }

  for (const tool of listed.tools) {
    const properties = (tool.inputSchema as { properties?: Record<string, unknown> }).properties ?? {};
    if (tool.annotations?.readOnlyHint === false) {
      assert.ok('response_mode' in properties, `Write tool ${tool.name} must advertise response_mode.`);
    }
    if (tool.annotations?.readOnlyHint === true) {
      assert.ok(!('response_mode' in properties), `Read tool ${tool.name} must not advertise response_mode.`);
    }
  }

  const taskList = listed.tools.find((tool) => tool.name === 'task_list');
  assert.ok(taskList, 'Core profile must advertise task_list.');
  const taskListProperties = (taskList.inputSchema as { properties?: Record<string, unknown> }).properties ?? {};
  const fieldsSchema = taskListProperties.fields as { items?: { enum?: readonly unknown[] } } | undefined;
  assert.ok(fieldsSchema?.items?.enum?.includes('frontend_url'), 'task_list fields must include frontend_url.');
});
