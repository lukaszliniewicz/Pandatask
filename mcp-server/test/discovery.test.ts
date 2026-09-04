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
    'project_workspace_get',
    'project_reference_list',
    'project_reference_add',
    'project_reference_update',
    'project_reference_remove',
    'project_reference_export',
    'project_reference_import',
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
  assert.ok(fieldsSchema?.items?.enum?.includes('recurrence_month_week'), 'task_list fields must include recurrence_month_week.');

  const taskCreate = listed.tools.find((tool) => tool.name === 'task_create');
  assert.ok(taskCreate, 'Core profile must advertise task_create.');
  const taskCreateProperties = (taskCreate.inputSchema as { properties?: Record<string, unknown> }).properties ?? {};
  const recurrenceFrequency = taskCreateProperties.recurrence_frequency as { enum?: readonly unknown[] } | undefined;
  const recurrenceMonthWeek = taskCreateProperties.recurrence_month_week as { enum?: readonly unknown[] } | undefined;
  const recurrenceDays = taskCreateProperties.recurrence_days as { description?: string } | undefined;
  assert.ok(recurrenceFrequency?.enum?.includes('monthly_weekday'), 'task_create must advertise monthly_weekday recurrence.');
  assert.deepEqual(recurrenceMonthWeek?.enum, ['first', 'second', 'third', 'fourth', 'last']);
  assert.match(recurrenceDays?.description ?? '', /Sunday is 7, never 0/);

  for (const name of ['project_workspace_get', 'project_reference_list', 'project_reference_export']) {
    const tool = listed.tools.find((item) => item.name === name);
    assert.ok(tool, `Core profile must advertise ${name}.`);
    assert.equal(tool.annotations?.readOnlyHint, true);
    const properties = (tool.inputSchema as { properties?: Record<string, unknown> }).properties ?? {};
    assert.ok(!('response_mode' in properties), `${name} must not advertise response_mode.`);
  }

  for (const name of ['project_reference_add', 'project_reference_update', 'project_reference_remove', 'project_reference_import']) {
    const tool = listed.tools.find((item) => item.name === name);
    assert.ok(tool, `Core profile must advertise ${name}.`);
    assert.equal(tool.annotations?.readOnlyHint, false);
    const properties = (tool.inputSchema as { properties?: Record<string, unknown> }).properties ?? {};
    assert.ok('response_mode' in properties, `${name} must advertise response_mode.`);
    assert.ok('dry_run' in properties, `${name} must advertise dry_run.`);
    assert.ok('idempotency_key' in properties, `${name} must advertise idempotency_key.`);
  }

  const add = listed.tools.find((item) => item.name === 'project_reference_add');
  const addProperties = (add?.inputSchema as { properties?: Record<string, unknown> } | undefined)?.properties ?? {};
  const relationType = addProperties.relation_type as { enum?: readonly unknown[] } | undefined;
  assert.deepEqual(relationType?.enum, ['included', 'related', 'dependency']);
  assert.match(String((relationType as { description?: string } | undefined)?.description), /dependency requires/);
});
