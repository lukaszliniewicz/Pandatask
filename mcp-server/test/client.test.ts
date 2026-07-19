import assert from 'node:assert/strict';
import test from 'node:test';
import { PandataskApiError, PandataskClient } from '../src/client.js';
import type { PandataskConfig } from '../src/config.js';

const config: PandataskConfig = {
  siteUrl: 'https://example.com',
  apiBaseUrl: 'https://example.com/wp-json/pandatask/v1',
  username: 'wp-agent',
  appPassword: 'app-password',
  defaultDryRun: false,
  timeoutMs: 30000,
  allowInsecureHttp: false,
};

test('mutate returns an exact preview without performing a fetch', async () => {
  let called = false;
  const client = new PandataskClient(config, async () => {
    called = true;
    return new Response('{}');
  });

  const result = await client.mutate(
    { method: 'PATCH', path: '/tasks/7', query: { include: [1, 2] }, body: { status: 'done' } },
    true,
  );

  assert.equal(called, false);
  assert.deepEqual(result, {
    dry_run: true,
    would_execute: {
      method: 'PATCH',
      url: 'https://example.com/wp-json/pandatask/v1/tasks/7?include%5B%5D=1&include%5B%5D=2',
      body: { status: 'done' },
    },
  });
});

test('request uses WordPress Application Password basic authentication and JSON', async () => {
  let capturedUrl = '';
  let capturedInit: RequestInit | undefined;
  const client = new PandataskClient(config, async (input, init) => {
    capturedUrl = String(input);
    capturedInit = init;
    return new Response(JSON.stringify({ task: { id: 7 } }), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    });
  });

  const result = await client.request({ method: 'PATCH', path: '/tasks/7', body: { status: 'done' } });
  assert.deepEqual(result, { task: { id: 7 } });
  assert.equal(capturedUrl, 'https://example.com/wp-json/pandatask/v1/tasks/7');
  assert.equal(capturedInit?.method, 'PATCH');
  assert.equal(capturedInit?.redirect, 'error');
  assert.equal((capturedInit?.headers as Record<string, string>).Authorization, `Basic ${Buffer.from('wp-agent:app-password').toString('base64')}`);
  assert.equal(capturedInit?.body, JSON.stringify({ status: 'done' }));
});

test('request converts WordPress errors into a safe typed error', async () => {
  const client = new PandataskClient(config, async () =>
    new Response(JSON.stringify({ code: 'rest_forbidden', message: 'No access', data: { status: 403 } }), {
      status: 403,
      headers: { 'content-type': 'application/json' },
    }),
  );

  await assert.rejects(
    () => client.request({ path: '/boards' }),
    (error: unknown) => {
      assert.ok(error instanceof PandataskApiError);
      assert.equal(error.status, 403);
      assert.equal(error.code, 'rest_forbidden');
      assert.equal(error.message, 'No access');
      return true;
    },
  );
});
