import { McpServer, type ToolCallback } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ToolAnnotations } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { PandataskClient } from './client.js';
import { handled, toolOutputSchema } from './result.js';
import { dryRunField, idempotencyKey, isoDate, periodSchema, positiveId } from './schemas.js';
import { toolEnabledForServer } from './tool-profile.js';

const readOnly: ToolAnnotations = { readOnlyHint: true, openWorldHint: false, destructiveHint: false, idempotentHint: true };
const write: ToolAnnotations = { readOnlyHint: false, openWorldHint: false, destructiveHint: false, idempotentHint: false };
const destructive: ToolAnnotations = { readOnlyHint: false, openWorldHint: false, destructiveHint: true, idempotentHint: true };

const activityType = z
  .string()
  .regex(/^[a-z0-9_-]{1,32}$/, 'Use a lowercase activity type key from work_type_list (letters, numbers, underscores, or hyphens).')
  .describe('Activity type key. Call work_type_list first to discover built-in and custom keys; labels are not keys.');
const residualHandling = z.enum(['refine_residual', 'additional']);
const workCapacity = z.enum(['paid', 'volunteer', 'other']);
const completionWorkItem = z.object({
  duration_seconds: z.number().int().positive().describe('Seconds of previously unlogged work represented by this item.'),
  activity_type: activityType,
  capacity: workCapacity.optional(),
  title: z.string().min(1).max(255).optional(),
  notes: z.string().optional(),
});
const residualClassification = z.object({
  activity_type: activityType.optional().describe('Optional classification for any remaining residual/unitemised time.'),
  capacity: workCapacity.optional(),
  title: z.string().min(1).max(255).optional(),
  notes: z.string().optional(),
});
const workAllocation = z.object({
  task_id: positiveId.optional().describe('Task receiving this portion of the work entry.'),
  board_name: z.string().min(1).max(191).regex(/^[\w-]+$/).optional().describe('Board receiving this portion of standalone/ad-hoc work without inventing a task.'),
  seconds: z.number().int().positive().describe('Seconds allocated to this target.'),
  residual_handling: residualHandling.optional().describe('For occurrence-context task allocations only, required when the task already has unitemised residual time: refine it or count this as additional work.'),
  context: z.enum(['occurrence', 'post_completion']).optional().describe('Use post_completion only for small factual work performed after a task was already completed; omitted means ordinary occurrence work.'),
}).superRefine((value, context) => {
  if (Boolean(value.task_id) === Boolean(value.board_name)) {
    context.addIssue({ code: 'custom', message: 'Provide exactly one of task_id or board_name.' });
  }
  if (value.board_name && value.residual_handling) {
    context.addIssue({ code: 'custom', path: ['residual_handling'], message: 'residual_handling applies only to task allocations.' });
  }
  if (value.board_name && value.context && value.context !== 'occurrence') {
    context.addIssue({ code: 'custom', path: ['context'], message: 'Task work context applies only to task allocations.' });
  }
  if (value.context === 'post_completion' && value.residual_handling) {
    context.addIssue({ code: 'custom', path: ['residual_handling'], message: 'Post-completion work is outside occurrence reconciliation and must not use residual_handling.' });
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

const workUpdateInput = z.object({
  entry_id: positiveId,
  title: z.string().min(1).max(255).optional().describe('Replacement work-entry title.'),
  notes: z.string().optional().describe('Replacement notes; use an empty string to clear notes.'),
  activity_type: activityType.optional(),
  work_date: isoDate.optional(),
  duration_seconds: z.number().int().positive().optional().describe('Replacement total duration in seconds.'),
  capacity: z.enum(['paid', 'volunteer', 'other']).nullable().optional().describe('Capacity classification, or null to clear it.'),
  allocations: z
    .array(workAllocation)
    .max(50)
    .optional()
    .describe('Whole-set replacement for allocations. Supply [] to detach the entry from every task and board; residual_handling keeps current task-time semantics.'),
  dry_run: dryRunField,
  idempotency_key: idempotencyKey,
}).superRefine((value, context) => {
  const mutableFields = ['title', 'notes', 'activity_type', 'work_date', 'duration_seconds', 'capacity', 'allocations'] as const;
  if (!mutableFields.some((field) => value[field] !== undefined)) {
    context.addIssue({ code: 'custom', message: 'Provide at least one work-entry field to update.' });
  }
  if (value.allocations === undefined) return;

  const targetKeys = value.allocations.map((allocation) => allocation.task_id ? `task:${allocation.task_id}` : `board:${allocation.board_name}`);
  if (new Set(targetKeys).size !== targetKeys.length) {
    context.addIssue({ code: 'custom', path: ['allocations'], message: 'A task or board can appear only once in a work entry.' });
  }
  if (value.duration_seconds !== undefined) {
    const allocated = value.allocations.reduce((sum, allocation) => sum + allocation.seconds, 0);
    if (allocated > value.duration_seconds) {
      context.addIssue({ code: 'custom', path: ['allocations'], message: 'Allocated seconds cannot exceed duration_seconds.' });
    }
  }
});

const workTypeKey = activityType.describe('Work type key. Call work_type_list first to discover available keys.');
const workTypeUpdateInput = z.object({
  key: workTypeKey,
  label: z.string().min(1).max(80).optional().describe('Replacement label shown to people using this work type.'),
  is_active: z.boolean().optional().describe('Whether this work type can be selected for new entries.'),
  dry_run: dryRunField,
  idempotency_key: idempotencyKey,
}).refine((value) => value.label !== undefined || value.is_active !== undefined, 'Provide label or is_active to update the work type.');

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

function mutationBody(input: Record<string, unknown>, excluded: readonly string[]): Record<string, unknown> {
  const excludedKeys = new Set(excluded);
  return Object.fromEntries(Object.entries(input).filter(([key, value]) => !excludedKeys.has(key) && value !== undefined));
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
      work_items: z.array(completionWorkItem).max(20).optional().describe('Optional itemisation of the previously unlogged portion of actual time. Existing detailed work is not repeated here.'),
      residual: residualClassification.optional().describe('Optional classification for any remaining residual time after detailed work and work_items.'),
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
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
        ...(input.work_items ? { work_items: input.work_items } : {}),
        ...(input.residual ? { residual: input.residual } : {}),
      },
      idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
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
      work_items: z.array(completionWorkItem).max(20).optional().describe('Optional itemisation of previously unlogged work for this occurrence.'),
      residual: residualClassification.optional().describe('Optional classification for any remaining residual time.'),
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
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
        ...(input.work_items ? { work_items: input.work_items } : {}),
        ...(input.residual ? { residual: input.residual } : {}),
      },
      idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
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
    'task_work_get',
    'Get task work',
    'Gets work entries and time summaries for a task the authenticated user may read, including direct and descendant aggregates.',
    z.object({ task_id: positiveId }),
    readOnly,
    async (input, extra) => client.request({
      path: `/tasks/${Number(input.task_id)}/work`,
      signal: extra.signal,
    }),
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
    'work_get',
    'Get work entry',
    'Gets one work entry, including its allocation snapshots. Pandatask enforces whether the authenticated user may read it.',
    z.object({ entry_id: positiveId }),
    readOnly,
    async (input, extra) => client.request({
      path: `/work-entries/${Number(input.entry_id)}`,
      signal: extra.signal,
    }),
  );

  register(
    server,
    'work_update',
    'Update work entry',
    'Updates one factual work entry. Call work_get first before changing allocations: allocations replaces the complete allocation set, so preserve every allocation that should remain, use [] to detach from every task and board, and use residual_handling when refining or adding task time with existing residuals. Use work_type_list to discover valid activity_type keys.',
    workUpdateInput,
    write,
    async (input, extra) => client.mutate({
      method: 'PATCH',
      path: `/work-entries/${Number(input.entry_id)}`,
      body: mutationBody(input, ['entry_id', 'dry_run', 'idempotency_key']),
      idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
      signal: extra.signal,
    }, Boolean(input.dry_run)),
  );

  register(
    server,
    'work_delete',
    'Delete work entry',
    'Deletes one factual work entry from active logs and reports. This cannot delete residual time; use task_time_resolve for residuals. Confirm the entry ID before executing.',
    z.object({
      entry_id: positiveId,
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
    }),
    destructive,
    async (input, extra) => client.mutate({
      method: 'DELETE',
      path: `/work-entries/${Number(input.entry_id)}`,
      idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
      signal: extra.signal,
    }, Boolean(input.dry_run)),
  );

  register(
    server,
    'work_type_list',
    'List work types',
    'Lists the built-in and personal work type keys available for activity_type. Call this before logging or editing work when the key is not already known.',
    z.object({}),
    readOnly,
    async (_input, extra) => client.request({ path: '/work/activity-types', signal: extra.signal }),
  );

  register(
    server,
    'work_type_create',
    'Create work type',
    'Creates a personal work type category. The server generates its lowercase key; use work_type_list to retrieve it before logging work.',
    z.object({
      label: z.string().min(1).max(80).describe('Human-readable work type label.'),
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
    }),
    write,
    async (input, extra) => client.mutate({
      method: 'POST',
      path: '/work/activity-types',
      body: { label: input.label },
      idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
      signal: extra.signal,
    }, Boolean(input.dry_run)),
  );

  register(
    server,
    'work_type_update',
    'Update work type',
    'Updates a personal or built-in work type label or active state. Use work_type_list to discover keys; archived types remain available on historical entries.',
    workTypeUpdateInput,
    write,
    async (input, extra) => client.mutate({
      method: 'PATCH',
      path: `/work/activity-types/${encodeURIComponent(String(input.key))}`,
      body: mutationBody(input, ['key', 'dry_run', 'idempotency_key']),
      idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
      signal: extra.signal,
    }, Boolean(input.dry_run)),
  );

  register(
    server,
    'work_type_archive',
    'Archive work type',
    'Archives a work type by deactivating it for new entries while preserving it for historical reporting. This is reversible through work_type_update with is_active=true.',
    z.object({
      key: workTypeKey,
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
    }),
    destructive,
    async (input, extra) => client.mutate({
      method: 'DELETE',
      path: `/work/activity-types/${encodeURIComponent(String(input.key))}`,
      idempotencyKey: typeof input.idempotency_key === 'string' ? input.idempotency_key : undefined,
      signal: extra.signal,
    }, Boolean(input.dry_run)),
  );

  register(
    server,
    'work_report',
    'Get my work report',
    'Returns personal work totals without double-counting split allocations, including task-linked, board-only, unallocated and residual subsets plus activity/task/board/project/category/capacity breakdowns.',
    z
      .object({
        period: periodSchema.optional().describe('Named period. When omitted, Pandatask uses last_30_days.'),
        start_date: isoDate.optional().describe('Custom period start date.'),
        end_date: isoDate.optional().describe('Custom period end date.'),
      })
      .superRefine((value, context) => {
        if (value.period === 'custom' && (!value.start_date || !value.end_date)) {
          context.addIssue({ code: 'custom', path: ['period'], message: 'Custom reports require start_date and end_date.' });
        }
        if (value.start_date && value.end_date && value.start_date > value.end_date) {
          context.addIssue({ code: 'custom', path: ['end_date'], message: 'end_date must be on or after start_date.' });
        }
      }),
    readOnly,
    async (input, extra) => client.request({
      path: '/users/me/work-report',
      query: {
        period: input.period as string | undefined,
        start_date: input.start_date as string | undefined,
        end_date: input.end_date as string | undefined,
      },
      signal: extra.signal,
    }),
  );
}
