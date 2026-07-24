import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { PandataskApiError } from './client.js';

const MAX_TEXT_CONTENT_CHARS = 64 * 1024;

export const toolOutputSchema = z.object({
  ok: z.boolean().describe('True when the tool completed successfully; false for failures or partial workflows.'),
  data: z.unknown().optional().describe('Tool-specific result when ok is true.'),
  error: z
    .object({
      code: z.string().describe('Stable machine-readable error code.'),
      message: z.string().describe('Concise user-actionable error message.'),
      http_status: z.number().int().nonnegative().optional().describe('Pandatask HTTP status, or 0 for a network error.'),
      details: z.unknown().optional().describe('Bounded diagnostic or partial-workflow details.'),
    })
    .optional()
    .describe('Failure details when ok is false.'),
});

export class PandataskWorkflowError extends Error {
  readonly code: string;
  readonly details: unknown;

  constructor(message: string, code: string, details: unknown) {
    super(message);
    this.name = 'PandataskWorkflowError';
    this.code = code;
    this.details = details;
  }
}

function conciseSuccess(value: unknown): string {
  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    const data = value as Record<string, unknown>;
    if (data.dry_run === true) return 'Dry-run preview prepared; no mutation was sent.';
    if (typeof data.message === 'string' && data.message.trim()) return data.message.trim().slice(0, 500);
    if (data.complete === false) return 'Pandatask workflow completed only partially; inspect structured data.';
    if (typeof data.count === 'number') return `Pandatask returned ${data.count} result${data.count === 1 ? '' : 's'}.`;
    if (typeof data.requested === 'number' && typeof data.succeeded === 'number') {
      return `Pandatask completed ${data.succeeded} of ${data.requested} requested operations.`;
    }
  }
  return 'Pandatask tool completed successfully; inspect structured data for the result.';
}

function boundedDetails(value: unknown): unknown {
  if (value === undefined) return undefined;
  try {
    const serialized = JSON.stringify(value);
    if (serialized.length <= 12_000) return value;
    return {
      truncated: true,
      original_length: serialized.length,
      preview: serialized.slice(0, 12_000),
    };
  } catch {
    return { unavailable: true, message: 'Error details could not be serialized safely.' };
  }
}

function compatibleText(payload: Record<string, unknown>, largeResultSummary: string): string {
  const serialized = JSON.stringify(payload);
  if (serialized.length <= MAX_TEXT_CONTENT_CHARS) return serialized;
  return JSON.stringify({
    ok: payload.ok,
    truncated_text: true,
    serialized_characters: serialized.length,
    message: largeResultSummary,
  });
}

export function toolResult(value: unknown): CallToolResult {
  const payload = { ok: true as const, data: value };
  return {
    content: [{ type: 'text', text: compatibleText(payload, `${conciseSuccess(value)} Full data is available in structuredContent.`) }],
    structuredContent: payload,
  };
}

export function toolError(error: unknown): CallToolResult {
  let payload: {
    ok: false;
    error: {
      code: string;
      message: string;
      http_status?: number;
      details?: unknown;
    };
  };

  if (error instanceof PandataskApiError) {
    payload = {
      ok: false,
      error: {
        code: error.code,
        message: error.message,
        http_status: error.status,
      },
    };
    const details = boundedDetails(error.details);
    if (details !== undefined) payload.error.details = details;
  } else if (error instanceof PandataskWorkflowError) {
    payload = {
      ok: false,
      error: {
        code: error.code,
        message: error.message,
        details: boundedDetails(error.details),
      },
    };
  } else {
    payload = {
      ok: false,
      error: {
        code: 'pandatask_mcp_error',
        message: error instanceof Error ? error.message : String(error),
      },
    };
  }

  return {
    isError: true,
    content: [{ type: 'text', text: compatibleText(payload, `${payload.error.message} Full details are available in structuredContent.`) }],
    structuredContent: payload,
  };
}

export async function handled(operation: () => Promise<unknown>): Promise<CallToolResult> {
  try {
    return toolResult(await operation());
  } catch (error) {
    return toolError(error);
  }
}
