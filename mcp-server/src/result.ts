import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { PandataskApiError } from './client.js';

function structured(value: unknown): Record<string, unknown> {
  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    return value as Record<string, unknown>;
  }
  return { data: value };
}

export function toolResult(value: unknown): CallToolResult {
  const payload = structured(value);
  return {
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload,
  };
}

export function toolError(error: unknown): CallToolResult {
  if (error instanceof PandataskApiError) {
    const payload: Record<string, unknown> = {
      error: error.code,
      message: error.message,
      http_status: error.status,
    };
    if (error.details !== undefined) {
      payload.details = error.details;
    }
    return {
      isError: true,
      content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
      structuredContent: payload,
    };
  }

  const payload = {
    error: 'pandatask_mcp_error',
    message: error instanceof Error ? error.message : String(error),
  };
  return {
    isError: true,
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
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
