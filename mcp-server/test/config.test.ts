import assert from 'node:assert/strict';
import test from 'node:test';
import { ConfigurationError, loadConfig, publicConfig } from '../src/config.js';

test('loadConfig builds the REST base URL and normalizes an application password', () => {
  const config = loadConfig({
    PANDATASK_URL: 'https://example.com/community/',
    PANDATASK_USERNAME: 'agent',
    PANDATASK_APP_PASSWORD: 'abcd efgh ijkl',
    PANDATASK_DRY_RUN: 'yes',
    PANDATASK_TIMEOUT_MS: '45000',
    PANDATASK_TOOL_PROFILE: 'core',
    PANDATASK_MAX_CONCURRENCY: '3',
    PANDATASK_MAX_COLLECTION_ITEMS: '750',
  });

  assert.equal(config.siteUrl, 'https://example.com/community');
  assert.equal(config.apiBaseUrl, 'https://example.com/community/wp-json/pandatask/v1');
  assert.equal(config.appPassword, 'abcdefghijkl');
  assert.equal(config.defaultDryRun, true);
  assert.equal(config.timeoutMs, 45000);
  assert.equal(config.toolProfile, 'core');
  assert.equal(config.maxConcurrency, 3);
  assert.equal(config.maxCollectionItems, 750);
  assert.equal(JSON.stringify(publicConfig(config)).includes('abcdefghijkl'), false);
  assert.equal(JSON.stringify(publicConfig(config)).includes('"agent"'), false);
});

test('loadConfig rejects HTTP unless explicitly enabled', () => {
  assert.throws(
    () =>
      loadConfig({
        PANDATASK_URL: 'http://localhost',
        PANDATASK_USERNAME: 'agent',
        PANDATASK_APP_PASSWORD: 'secret',
      }),
    ConfigurationError,
  );

  const config = loadConfig({
    PANDATASK_URL: 'http://localhost',
    PANDATASK_USERNAME: 'agent',
    PANDATASK_APP_PASSWORD: 'secret',
    PANDATASK_ALLOW_INSECURE_HTTP: 'true',
  });
  assert.equal(config.allowInsecureHttp, true);
});

test('loadConfig rejects credentials embedded in the URL', () => {
  assert.throws(
    () =>
      loadConfig({
        PANDATASK_URL: 'https://agent:secret@example.com',
        PANDATASK_USERNAME: 'agent',
        PANDATASK_APP_PASSWORD: 'secret',
      }),
    /must not contain credentials/,
  );
});

test('loadConfig rejects invalid tool profiles and workflow bounds', () => {
  const base = {
    PANDATASK_URL: 'https://example.com',
    PANDATASK_USERNAME: 'agent',
    PANDATASK_APP_PASSWORD: 'secret',
  };
  assert.throws(() => loadConfig({ ...base, PANDATASK_TOOL_PROFILE: 'everything' }), /must be core, full, or admin/);
  assert.throws(() => loadConfig({ ...base, PANDATASK_MAX_CONCURRENCY: '0' }), /must be an integer from 1 to 20/);
  assert.throws(() => loadConfig({ ...base, PANDATASK_MAX_COLLECTION_ITEMS: '10' }), /must be an integer from 50 to 5000/);
});
