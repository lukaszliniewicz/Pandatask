export interface PandataskConfig {
  siteUrl: string;
  apiBaseUrl: string;
  username: string;
  appPassword: string;
  defaultDryRun: boolean;
  timeoutMs: number;
  allowInsecureHttp: boolean;
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

  const timeoutRaw = env.PANDATASK_TIMEOUT_MS?.trim() || '30000';
  const timeoutMs = Number.parseInt(timeoutRaw, 10);
  if (!Number.isInteger(timeoutMs) || timeoutMs < 1000 || timeoutMs > 120000) {
    throw new ConfigurationError('PANDATASK_TIMEOUT_MS must be an integer from 1000 to 120000.');
  }

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
  };
}

export function publicConfig(config: PandataskConfig): Record<string, unknown> {
  return {
    site_url: config.siteUrl,
    api_base_url: config.apiBaseUrl,
    username: config.username,
    authentication: 'WordPress Application Password (HTTP Basic over HTTPS)',
    default_dry_run: config.defaultDryRun,
    timeout_ms: config.timeoutMs,
  };
}
