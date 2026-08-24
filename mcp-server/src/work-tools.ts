import { McpServer, type ToolCallback } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ToolAnnotations } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { PandataskClient } from './client.js';
import { handled, toolOutputSchema } from './result.js';
import { dryRunField, idempotencyKey, isoDate, positiveId } from './schemas.js';
import { toolEnabledForServer } from './tool-profile.js';

const readOnly: ToolAnnotations = { readOnlyHint: true, openWorldHint: false, destructiveHint: false, idempotentHint: true };
const write: ToolAnnotations = { readOnlyHint: false, openWorldHint: false, destructiveHint: false, idempotentHint: false };

const activityType = z.enum([
  'meeting', 'call', 'correspondence', 'research', 'writing', 'development',
  'planning', 'administration', 'event', 'travel', 'other',
]);
const residualHandling = z.enum(['refine_residual', 'additional']);
const workAllocation = z.object({
  task_id: positiveId.optional().describe('Task receiving this portion of the work entry.'),
  board_name: z.string().min(1).max(191).regex(/^[\w-]+$/).optional().describe('Board receiving this portion of standalone/ad-hoc work without inventing a task.'),
  seconds: z.number().int().positive().describe('Seconds allocated to this target.'),
  residual_handling: residualHandling.optional().describe('For task allocations only, required when the task already has unitemised residual time: refine it or count this as additional work.'),
}).superRefine((value, context) => {
  if (Boolean(value.task_id) === Boolean(value.board_name)) {
    context.addIssue({ code: 'custom', message: 'Provide exactly one of task_id or board_name.' });
  }
  if (value.board_name && value.residual_handling) {
    context.addIssue({ code: 'custom', path: ['residual_handling'], message: 'residual_handling applies only to task allocations.' });
  }
});
const workLogInput = z.object({
  title: z.string().min(1).max(255),
  notes: z.string().optional(),
  activity_type: activityType,
  work_date: isoDate,
  duration_seconds: z.number().int().positive(),
  capacity: z.enum(['paid', 'volunteer', 'other']).optional(),
  task_id: positiveId.optional().describe('Convenience shorthand: allocate the full duration to one task. Do not combine with board_name or allocations.'),
  board_name: z.string().min(1).max(191).regex(/^[\w-]+$/).optional().describe('Convenience shorthand: allocate the full duration to one board without a task. Do not combine with task_id or allocations.'),
  allocations: z.array(workAllocation).max(50).optional().describe('Optional split task/board allocations. Their sum may be less than the entry duration; the remainder stays unallocated.'),
  dry_run: dryRunField,
  idempotency_key: idempotencyKey,
}).superRefine((value, context) => {
  const shorthandCount = Number(Boolean(value.task_id)) + Number(Boolean(value.board_name));
  if (shorthandCount > 1 || (shorthandCount > 0 && value.allocations?.length)) {
    context.addIssue({ code: 'custom', path: ['allocations'], message: 'Use one shorthand target (task_id or board_name) or allocations, not both.' });
  }
  const allocations = value.allocations ?? [];
  const targetKeys = allocations.map((allocation) => allocation.task_id ? `task:${allocation.task_id}` : `board:${allocation.board_name}`);
  if (new Set(targetKeys).size !== targetKeys.length) {
    context.addIssue({ code: 'custom', path: ['allocations'], message: 'A task or board can appear only once in a work entry.' });
  }
  const allocated = allocations.reduce((sum, allocation) => sum + allocation.seconds, 0);
  if (allocated > value.duration_seconds) {
    context.addIssue({ code: 'custom', path: ['allocations'], message: 'Allocated seconds cannot exceed duration_seconds.' });
  }
});

const taskTimeLogInput = z.object({
  task_id: positiveId.describe('Task receiving the incremental time entry.'),
  duration_seconds: z.number().int().positive().describe('Additional time worked, in seconds. Repeated calls accumulate through separate work entries.'),
  activity_type: activityType,
  work_date: isoDate.optional().describe('Work date in YYYY-MM-DD form. Defaults to the WordPress site-local current date.'),
  title: z.string().min(1).max(255).optional().describe('Optional work-entry title. Defaults to the activity label.'),
  notes: z.string().optional(),
  capacity: z.enum(['paid', 'volunteer', 'other']).optional(),
  dry_run: dryRunField,
  idempotency_key: idempotencyKey,
});

function register(
  server: McpServer,
  name: string,
  title: string,
  description: string,
  inputSchema: z.ZodType<Record<string, unknown>>,
  annotations: ToolAnnotations,
  operation: (input: Record<string, unknown>, extra: Parameters<ToolCallback<z.ZodType<Record<string, unknown>>>>[1]) => Promise<unknown>,
): void {
  if (!toolEnabledForServer(server, name)) return;
  const callback = (async (input: unknown, extra) => handled(() => operation(inputSchema.parse(input), extra))) as ToolCallback<z.ZodType<Record<string, unknown>>>;
  server.registerTool(name, {
    title,
    description: `${description} Returns {ok:true,data} on success or {ok:false,error:{code,message,http_status?,details?}} on failure.`,
    inputSchema,
    outputSchema: toolOutputSchema,
    annotations,
  }, callback);
}

export function registerWorkTools(server: McpServer, client: PandataskClient): void {
  register(
    server,
    'task_complete',
    'Complete task with time',
    'Completes a task through the first-class completion boundary. Resolve cumulative actual time, use not_tracked when it is unknown, or use the explicit supervisor mode when an eligible non-assignee is completing without claiming personal work.',
    z.object({
      task_id: positiveId,
      actual_seconds: z.number().int().nonnegative().nullable().optional().describe('Cumulative actual time for this occurrence in seconds.'),
      not_tracked: z.boolean().optional().default(false).describe('Explicitly records that actual time is unknown rather than zero.'),
      no_personal_work: z.boolean().optional().default(false).describe('Eligible non-assignee supervisors may complete without recording time for themselves.'),
      change_comment: z.string().max(2000).optional(),
      dry_run: dryRunField,
    }).refine((value) => Boolean(value.no_personal_work) || value.not_tracked || value.actual_seconds !== undefined && value.actual_seconds !== null, {
      message: 'Provide actual_seconds, use not_tracked, or select the supervisor completion mode.',
    }),
    write,
    async (input, extra) => client.mutate({
      method: 'POST',
      path: `/tasks/${Number(input.task_id)}/complete`,
      body: {
        actual_seconds: input.actual_seconds ?? null,
        not_tracked: Boolean(input.not_tracked),
        no_personal_work: Boolean(input.no_personal_work),
        ...(input.change_comment ? { change_comment: input.change_comment } : {}),
      },
      signal: extra.signal,
    }, Boolean(input.dry_run)),
  );

  register(
    server,
    'task_time_resolve',
    'Resolve task time',
    'Resolves the authenticated user’s time for the current task occurrence without changing task status. Use this after a supervisor completed the task and left assignee time unresolved.',
    z.object({
      task_id: positiveId,
      actual_seconds: z.number().int().nonnegative().nullable().optional().describe('Cumulative actual time for this occurrence in seconds.'),
      not_tracked: z.boolean().optional().default(false).describe('Explicitly records that actual time is unknown rather than zero.'),
      dry_run: dryRunField,
    }).refine((value) => value.not_tracked || value.actual_seconds !== undefined && value.actual_seconds !== null, {
      message: 'Provide actual_seconds or set not_tracked=true.',
    }),
    write,
    async (input, extra) => client.mutate({
      method: 'POST',
      path: `/tasks/${Number(input.task_id)}/time-resolution`,
      body: {
        actual_seconds: input.actual_seconds ?? null,
        not_tracked: Boolean(input.not_tracked),
      },
      signal: extra.signal,
    }, Boolean(input.dry_run)),
  );

  register(
    server,
    'task_time_log',
    'Log time to task',
    'Adds incremental time to the authenticated user’s current task occurrence without completing the task or resolving cumulative actual time. Use this while a task is pending or in progress; each call creates a normal factual work entry, and task completion can later reconcile the final cumulative actual.',
    taskTimeLogInput,
    write,
    async (input, extra) => client.mutate({
      method: 'POST',
      path: '/users/me/work-entries',
      body: {
        ...(input.title ? { title: input.title } : {}),
        ...(input.notes ? { notes: input.notes } : {}),
        activity_type: input.activity_type,
        ...(input.work_date ? { work_date: input.work_date } : {}),
        duration_seconds: input.duration_seconds,
        ...(input.capacity ? { capacity: input.capacity } : {}),
        allocations: [{
          task_id: Number(input.task_id),
          seconds: Number(input.duration_seconds),
        }],
      },
      idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
      signal: extra.signal,
    }, Boolean(input.dry_run)),
  );

  register(
    server,
    'work_log',
    'Log work',
    'Creates one factual work entry. Task and board allocations are optional, may be split, and may leave an explicitly unallocated remainder. activity_type describes how the work was done, not its project/category subject.',
    workLogInput,
    write,
    async (input, extra) => {
      const explicitAllocations = Array.isArray(input.allocations)
        ? input.allocations as Record<string, unknown>[]
        : [];
      const allocations = explicitAllocations.length > 0
        ? explicitAllocations
        : input.task_id
          ? [{ task_id: input.task_id, seconds: input.duration_seconds }]
          : input.board_name
            ? [{ board_name: input.board_name, seconds: input.duration_seconds }]
            : [];
      return client.mutate({
        method: 'POST',
        path: '/users/me/work-entries',
        body: {
          title: input.title,
          ...(input.notes ? { notes: input.notes } : {}),
          activity_type: input.activity_type,
          work_date: input.work_date,
          duration_seconds: input.duration_seconds,
          ...(input.capacity ? { capacity: input.capacity } : {}),
          allocations,
        },
        idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
        signal: extra.signal,
      }, Boolean(input.dry_run));
    },
  );

  register(
    server,
    'work_list',
    'List my work',
    'Lists the authenticated user’s work entries, including allocation snapshots.',
    z.object({
      start_date: isoDate.optional(),
      end_date: isoDate.optional(),
      limit: z.number().int().min(1).max(500).optional().default(200),
      offset: z.number().int().nonnegative().optional().default(0),
    }),
    readOnly,
    async (input, extra) => client.request({
      path: '/users/me/work-entries',
      query: {
        start_date: input.start_date as string | undefined,
        end_date: input.end_date as string | undefined,
        limit: Number(input.limit),
        offset: Number(input.offset),
      },
      signal: extra.signal,
    }),
  );

  register(
    server,
    'work_report',
    'Get my work report',
    'Returns personal work totals without double-counting split allocations, including task-linked, board-only, unallocated and residual subsets plus activity/task/board/project/category/capacity breakdowns.',
    z.object({ start_date: isoDate.optional(), end_date: isoDate.optional() }),
    readOnly,
    async (input, extra) => client.request({
      path: '/users/me/work-report',
      query: { start_date: input.start_date as string | undefined, end_date: input.end_date as string | undefined },
      signal: extra.signal,
    }),
  );
}
