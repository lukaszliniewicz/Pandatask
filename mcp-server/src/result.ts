import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { PandataskApiError } from './client.js';

const MAX_TEXT_CONTENT_CHARS = 64 * 1024;

export type ResponseMode = 'minimal' | 'full';

export interface ToolResultOptions {
  responseMode?: ResponseMode | undefined;
  operation?: string;
  input?: Record<string, unknown>;
}

const MINIMAL_RESULT_KEYS = new Set([
  'message',
  'id',
  'task_id',
  'project_id',
  'reference_id',
  'reference_key',
  'task_key',
  'predecessor_key',
  'successor_key',
  'relationship_id',
  'entry_id',
  'category_id',
  'comment_id',
  'owner_user_id',
  'user_id',
  'board_name',
  'frontend_url',
  'source_board',
  'destination_board',
  'key',
  'name',
  'title',
  'label',
  'role',
  'status',
  'state',
  'task_type',
  'archived',
  'deleted',
  'created',
  'skipped',
  'updated',
  'complete',
  'success',
  'is_active',
  'resumable',
  'rolled_back',
  'idempotent',
  'replayed',
  'index',
  'duration_seconds',
  'actual_seconds',
  'seconds',
  'work_date',
  'capacity',
  'occurrence_id',
  'specific_seconds',
  'declared_actual_seconds',
  'residual_entry_id',
  'resolved_by',
  'sequence_number',
  'occurrence_key',
  'opened_at',
  'skipped_at',
  'cancelled_at',
  'tombstoned_at',
  'action',
  'action_description',
  'type',
  'matched',
  'truncated',
  'requested',
  'succeeded',
  'failed',
  'count',
  'requested_tasks',
  'created_tasks',
  'error',
  'errors',
  'relation_type',
  'predecessor_task_id',
  'successor_task_id',
  'restricted',
  'is_restricted',
  'is_external',
  'code',
  'http_status',
]);

const MINIMAL_STRUCTURED_KEYS = new Set([
  'task',
  'project',
  'reference',
  'references',
  'entry',
  'category',
  'comment',
  'time',
  'work_type',
  'activity_type',
  'occurrence',
  'resolution',
  'resource',
  'results',
  'task_results',
  'rollback_results',
  'failed_step',
  'warnings',
  'warning',
  'next_action',
  'delegates',
]);

const INPUT_IDENTITY_KEYS = [
  'task_id',
  'project_id',
  'entry_id',
  'category_id',
  'comment_id',
  'owner_user_id',
  'board_name',
  'destination_board',
  'key',
  'reference_key',
] as const;

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

function compactMutationValue(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(compactMutationValue);
  }
  if (value === null || typeof value !== 'object') {
    return value;
  }

  const compact: Record<string, unknown> = {};
  for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
    if (MINIMAL_RESULT_KEYS.has(key)) {
      compact[key] = item;
    } else if (MINIMAL_STRUCTURED_KEYS.has(key)) {
      compact[key] = compactMutationValue(item);
    }
  }
  return compact;
}

function minimalMutationResult(value: unknown, options: ToolResultOptions): unknown {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return {
      operation: options.operation ?? 'mutation',
      message: 'Pandatask mutation completed.',
      result: value,
    };
  }

  const record = value as Record<string, unknown>;
  if (record.dry_run === true) return value;

  const compact = compactMutationValue(value) as Record<string, unknown>;
  const result: Record<string, unknown> = {
    operation: options.operation ?? 'mutation',
    ...compact,
  };

  for (const key of INPUT_IDENTITY_KEYS) {
    if (result[key] === undefined && options.input?.[key] !== undefined) {
      result[key] = options.input[key];
    }
  }

  if (typeof result.message !== 'string' || !result.message.trim()) {
    result.message = 'Pandatask mutation completed.';
  }
  return result;
}

export function toolResult(value: unknown, options: ToolResultOptions = {}): CallToolResult {
  const responseValue = options.responseMode === 'minimal' ? minimalMutationResult(value, options) : value;
  const payload = { ok: true as const, data: responseValue };
  return {
    content: [{ type: 'text', text: compatibleText(payload, `${conciseSuccess(responseValue)} Full data is available in structuredContent.`) }],
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

export async function handled(operation: () => Promise<unknown>, options: ToolResultOptions = {}): Promise<CallToolResult> {
  try {
    return toolResult(await operation(), options);
  } catch (error) {
    return toolError(error);
  }
}
