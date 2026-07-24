import type { PandataskConfig } from './config.js';

export type QueryValue = string | number | boolean | readonly (string | number)[] | null | undefined;
export type JsonRecord = Record<string, unknown>;

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  path: string;
  query?: Record<string, QueryValue>;
  body?: JsonRecord;
  idempotencyKey?: string | undefined;
  signal?: AbortSignal | undefined;
}

export interface MutationPreview {
  dry_run: true;
  validation_scope: 'local_schema';
  would_execute: {
    method: string;
    url: string;
    body?: JsonRecord;
    idempotency_key?: string;
  };
}

export class PandataskApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details?: unknown;

  constructor(message: string, status: number, code = 'pandatask_api_error', details?: unknown) {
    super(message);
    this.name = 'PandataskApiError';
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

type FetchImplementation = (input: string | URL | Request, init?: RequestInit) => Promise<Response>;
const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

function apiErrorPayload(payload: unknown): { code?: string; message?: string } {
  if (!payload || typeof payload !== 'object') {
    return {};
  }
  const record = payload as Record<string, unknown>;
  const result: { code?: string; message?: string } = {};
  if (typeof record.code === 'string') result.code = record.code;
  if (typeof record.message === 'string') result.message = record.message;
  return result;
}

function transportError(error: unknown, timeoutSignal: AbortSignal, requestSignal?: AbortSignal): PandataskApiError {
  if (requestSignal?.aborted) {
    return new PandataskApiError('Pandatask request was cancelled by the MCP client.', 0, 'pandatask_request_cancelled');
  }
  if (timeoutSignal.aborted) {
    return new PandataskApiError('Pandatask request exceeded the configured timeout.', 0, 'pandatask_request_timeout');
  }
  const message = error instanceof Error ? error.message : String(error);
  return new PandataskApiError(`Unable to reach Pandatask: ${message}`, 0, 'pandatask_network_error');
}

export class PandataskClient {
  constructor(
    readonly config: PandataskConfig,
    private readonly fetchImplementation: FetchImplementation = fetch,
  ) {}

  isDryRun(requested = false): boolean {
    return this.config.defaultDryRun || requested;
  }

  buildUrl(path: string, query: Record<string, QueryValue> = {}): URL {
    if (!path.startsWith('/')) {
      throw new Error('Pandatask API paths must begin with /.');
    }
    const url = new URL(`${this.config.apiBaseUrl}${path}`);
    for (const [name, value] of Object.entries(query)) {
      if (value === undefined || value === null || value === '') {
        continue;
      }
      if (Array.isArray(value)) {
        for (const item of value) {
          url.searchParams.append(`${name}[]`, String(item));
        }
      } else {
        url.searchParams.set(name, String(value));
      }
    }
    return url;
  }

  preview(options: RequestOptions): MutationPreview {
    const method = options.method ?? 'POST';
    const preview: MutationPreview = {
      dry_run: true,
      validation_scope: 'local_schema',
      would_execute: {
        method,
        url: this.buildUrl(options.path, options.query).toString(),
      },
    };
    if (options.body !== undefined) {
      preview.would_execute.body = options.body;
    }
    if (options.idempotencyKey !== undefined) {
      preview.would_execute.idempotency_key = options.idempotencyKey;
    }
    return preview;
  }

  async mutate(options: RequestOptions, requestedDryRun = false): Promise<unknown> {
    if (this.isDryRun(requestedDryRun)) {
      return this.preview(options);
    }
    return this.request(options);
  }

  async request(options: RequestOptions): Promise<unknown> {
    const method = options.method ?? 'GET';
    const url = this.buildUrl(options.path, options.query);
    const authorization = Buffer.from(`${this.config.username}:${this.config.appPassword}`, 'utf8').toString('base64');
    const headers: Record<string, string> = {
      Accept: 'application/json',
      Authorization: `Basic ${authorization}`,
      'User-Agent': 'Pandatask-MCP/1.1 (+https://github.com/lukaszliniewicz/Pandatask)',
    };
    if (options.idempotencyKey !== undefined) {
      headers['Idempotency-Key'] = options.idempotencyKey;
    }
    const timeoutSignal = AbortSignal.timeout(this.config.timeoutMs);
    const init: RequestInit = {
      method,
      headers,
      redirect: 'error',
      signal: options.signal ? AbortSignal.any([options.signal, timeoutSignal]) : timeoutSignal,
    };
    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }

    let response: Response;
    try {
      response = await this.fetchImplementation(url, init);
    } catch (error) {
      throw transportError(error, timeoutSignal, options.signal);
    }

    const declaredLength = Number(response.headers.get('content-length') ?? 0);
    if (declaredLength > MAX_RESPONSE_BYTES) {
      throw new PandataskApiError(
        `Pandatask response exceeded the ${MAX_RESPONSE_BYTES}-byte safety limit.`,
        response.status,
        'pandatask_response_too_large',
      );
    }

    let text: string;
    try {
      text = await readBoundedText(response, MAX_RESPONSE_BYTES);
    } catch (error) {
      if (error instanceof PandataskApiError) throw error;
      throw transportError(error, timeoutSignal, options.signal);
    }
    let payload: unknown = null;
    if (text) {
      try {
        payload = JSON.parse(text) as unknown;
      } catch {
        payload = { message: text.slice(0, 2000) };
      }
    }

    if (!response.ok) {
      const errorPayload = apiErrorPayload(payload);
      throw new PandataskApiError(
        errorPayload.message || `Pandatask returned HTTP ${response.status}.`,
        response.status,
        errorPayload.code || `http_${response.status}`,
        payload,
      );
    }

    return payload;
  }
}

async function readBoundedText(response: Response, maximumBytes: number): Promise<string> {
  if (!response.body) return '';

  const reader = response.body.getReader();
  const chunks: Uint8Array[] = [];
  let received = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    received += value.byteLength;
    if (received > maximumBytes) {
      await reader.cancel();
      throw new PandataskApiError(
        `Pandatask response exceeded the ${maximumBytes}-byte safety limit.`,
        response.status,
        'pandatask_response_too_large',
      );
    }
    chunks.push(value);
  }

  const merged = new Uint8Array(received);
  let offset = 0;
  for (const chunk of chunks) {
    merged.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return new TextDecoder().decode(merged);
}
