export interface PandataskConfig {
  siteUrl: string;
  apiBaseUrl: string;
  username: string;
  appPassword: string;
  defaultDryRun: boolean;
  timeoutMs: number;
  allowInsecureHttp: boolean;
  toolProfile: 'core' | 'full' | 'admin';
  maxConcurrency: number;
  maxCollectionItems: number;
}

export class ConfigurationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ConfigurationError';
  }
}

function required(env: NodeJS.ProcessEnv, name: string): string {
  const value = env[name]?.trim();
  if (!value) {
    throw new ConfigurationError(`Missing required environment variable: ${name}`);
  }
  return value;
}

function booleanValue(value: string | undefined, fallback: boolean): boolean {
  if (value === undefined || value.trim() === '') {
    return fallback;
  }

  const normalized = value.trim().toLowerCase();
  if (['1', 'true', 'yes', 'on'].includes(normalized)) {
    return true;
  }
  if (['0', 'false', 'no', 'off'].includes(normalized)) {
    return false;
  }
  throw new ConfigurationError(`Invalid boolean value: ${value}`);
}

function boundedInteger(env: NodeJS.ProcessEnv, name: string, fallback: number, minimum: number, maximum: number): number {
  const raw = env[name]?.trim() || String(fallback);
  const value = Number.parseInt(raw, 10);
  if (!Number.isInteger(value) || value < minimum || value > maximum) {
    throw new ConfigurationError(`${name} must be an integer from ${minimum} to ${maximum}.`);
  }
  return value;
}

function toolProfile(value: string | undefined): PandataskConfig['toolProfile'] {
  const normalized = value?.trim().toLowerCase() || 'full';
  if (normalized === 'core' || normalized === 'full' || normalized === 'admin') {
    return normalized;
  }
  throw new ConfigurationError('PANDATASK_TOOL_PROFILE must be core, full, or admin.');
}

function absoluteHttpUrl(value: string, name: string): URL {
  let parsed: URL;
  try {
    parsed = new URL(value);
  } catch {
    throw new ConfigurationError(`${name} must be an absolute HTTP(S) URL.`);
  }

  if (!['http:', 'https:'].includes(parsed.protocol)) {
    throw new ConfigurationError(`${name} must use HTTP or HTTPS.`);
  }
  if (parsed.username || parsed.password) {
    throw new ConfigurationError(`${name} must not contain credentials.`);
  }
  parsed.hash = '';
  parsed.search = '';
  return parsed;
}

function withoutTrailingSlash(value: string): string {
  return value.replace(/\/+$/, '');
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): PandataskConfig {
  const siteUrl = absoluteHttpUrl(required(env, 'PANDATASK_URL'), 'PANDATASK_URL');
  const allowInsecureHttp = booleanValue(env.PANDATASK_ALLOW_INSECURE_HTTP, false);
  if (siteUrl.protocol !== 'https:' && !allowInsecureHttp) {
    throw new ConfigurationError(
      'PANDATASK_URL must use HTTPS. Set PANDATASK_ALLOW_INSECURE_HTTP=true only for a trusted local development site.',
    );
  }

  const apiOverride = env.PANDATASK_API_BASE_URL?.trim();
  const apiBase = apiOverride
    ? absoluteHttpUrl(apiOverride, 'PANDATASK_API_BASE_URL')
    : new URL(`${withoutTrailingSlash(siteUrl.pathname)}/wp-json/pandatask/v1`, siteUrl.origin);
  if (apiBase.protocol !== 'https:' && !allowInsecureHttp) {
    throw new ConfigurationError('PANDATASK_API_BASE_URL must use HTTPS.');
  }

  const timeoutMs = boundedInteger(env, 'PANDATASK_TIMEOUT_MS', 30000, 1000, 120000);

  const appPassword = required(env, 'PANDATASK_APP_PASSWORD').replace(/\s+/g, '');
  if (!appPassword) {
    throw new ConfigurationError('PANDATASK_APP_PASSWORD is empty after removing display spaces.');
  }

  return {
    siteUrl: withoutTrailingSlash(siteUrl.toString()),
    apiBaseUrl: withoutTrailingSlash(apiBase.toString()),
    username: required(env, 'PANDATASK_USERNAME'),
    appPassword,
    defaultDryRun: booleanValue(env.PANDATASK_DRY_RUN, false),
    timeoutMs,
    allowInsecureHttp,
    toolProfile: toolProfile(env.PANDATASK_TOOL_PROFILE),
    maxConcurrency: boundedInteger(env, 'PANDATASK_MAX_CONCURRENCY', 5, 1, 20),
    maxCollectionItems: boundedInteger(env, 'PANDATASK_MAX_COLLECTION_ITEMS', 1000, 50, 5000),
  };
}

export function publicConfig(config: PandataskConfig): Record<string, unknown> {
  return {
    site_url: config.siteUrl,
    api_base_url: config.apiBaseUrl,
    authentication: 'WordPress Application Password (HTTP Basic over HTTPS)',
    default_dry_run: config.defaultDryRun,
    timeout_ms: config.timeoutMs,
    tool_profile: config.toolProfile,
    max_concurrency: config.maxConcurrency,
    max_collection_items: config.maxCollectionItems,
  };
}
