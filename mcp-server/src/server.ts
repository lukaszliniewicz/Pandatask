import { createHash } from 'node:crypto';
import { McpServer, type ToolCallback } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ToolAnnotations } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { PandataskApiError, PandataskClient, type JsonRecord, type MutationPreview, type RequestOptions } from './client.js';
import { publicConfig } from './config.js';
import { handled, PandataskWorkflowError, toolOutputSchema } from './result.js';
import {
  batchAction,
  boardName,
  clearableDate,
  dryRunField,
  idList,
  idempotencyKey,
  isoDate,
  plannedTaskData,
  periodSchema,
  positiveId,
  projectCreateData,
  projectUpdateData,
  taskCreateData,
  taskListInput,
  taskListQuery,
  taskMutableFields,
  taskUpdateData,
} from './schemas.js';
import { collection, deadlineReview, numberIds, summarizeTasks, workload } from './summaries.js';

const VERSION = '1.1.0';

const readOnly: ToolAnnotations = {
  readOnlyHint: true,
  openWorldHint: false,
  destructiveHint: false,
  idempotentHint: true,
};
const write: ToolAnnotations = {
  readOnlyHint: false,
  openWorldHint: false,
  destructiveHint: false,
  idempotentHint: false,
};
const destructive: ToolAnnotations = {
  readOnlyHint: false,
  openWorldHint: false,
  destructiveHint: true,
  idempotentHint: true,
};
const destructiveBatch: ToolAnnotations = {
  readOnlyHint: false,
  openWorldHint: false,
  destructiveHint: true,
  idempotentHint: false,
};

type ZodToolSchema = z.ZodType<Record<string, unknown>>;
type ToolExtra = Parameters<ToolCallback<ZodToolSchema>>[1];
type ToolProfile = 'core' | 'full' | 'admin';

const serverProfiles = new WeakMap<McpServer, ToolProfile>();
const adminTools = new Set(['board_list', 'batch_execute']);
const coreTools = new Set([
  'connection_check',
  'board_list_writable',
  'board_get_context',
  'board_get_summary',
  'board_deadline_review',
  'board_get_workload',
  'daily_briefing',
  'user_search',
  'task_list',
  'task_get',
  'task_create',
  'task_update',
  'task_set_status',
  'project_list',
  'project_get',
  'project_create',
  'project_update',
  'project_plan',
  'report_get',
]);

function toolEnabled(profile: ToolProfile, name: string): boolean {
  if (profile === 'admin') return true;
  if (adminTools.has(name)) return false;
  return profile === 'full' || coreTools.has(name);
}

function register<T extends ZodToolSchema>(
  server: McpServer,
  name: string,
  title: string,
  description: string,
  inputSchema: T,
  annotations: ToolAnnotations,
  operation: (input: z.output<T>, extra: ToolExtra) => Promise<unknown>,
): void {
  const profile = serverProfiles.get(server) ?? 'full';
  if (!toolEnabled(profile, name)) return;
  const callback = (async (input: unknown, extra: ToolExtra) =>
    handled(() => operation(inputSchema.parse(input), extra))) as ToolCallback<T>;
  server.registerTool(
    name,
    {
      title,
      description: `${description} Returns {ok:true,data} on success or {ok:false,error:{code,message,http_status?,details?}} on failure.`,
      inputSchema,
      outputSchema: toolOutputSchema,
      annotations,
    },
    callback,
  );
}

function boardPath(board: string, suffix: string): string {
  return `/boards/${encodeURIComponent(board)}${suffix}`;
}

function mutationBody(input: Record<string, unknown>, excluded: readonly string[]): JsonRecord {
  return Object.fromEntries(Object.entries(input).filter(([key, value]) => !excluded.includes(key) && value !== undefined));
}

function effectiveDryRun(client: PandataskClient, input: { dry_run?: boolean }): boolean {
  return client.isDryRun(input.dry_run ?? false);
}

function mutationPlan(client: PandataskClient, options: RequestOptions): MutationPreview {
  return client.preview(options);
}

function taskObject(payload: unknown): Record<string, unknown> {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return {};
  const record = payload as Record<string, unknown>;
  const task = record.task;
  return task && typeof task === 'object' && !Array.isArray(task) ? (task as Record<string, unknown>) : record;
}

function projectId(payload: unknown): number | null {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return null;
  const data = payload as Record<string, unknown>;
  const project = data.project;
  if (project && typeof project === 'object' && !Array.isArray(project)) {
    const id = Number((project as Record<string, unknown>).id);
    return Number.isInteger(id) && id > 0 ? id : null;
  }
  const id = Number(data.id);
  return Number.isInteger(id) && id > 0 ? id : null;
}

function taskId(payload: unknown): number | null {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return null;
  const data = payload as Record<string, unknown>;
  const task = data.task;
  if (task && typeof task === 'object' && !Array.isArray(task)) {
    const id = Number((task as Record<string, unknown>).id);
    return Number.isInteger(id) && id > 0 ? id : null;
  }
  const id = Number(data.id);
  return Number.isInteger(id) && id > 0 ? id : null;
}

interface CollectedTasks {
  tasks: Record<string, unknown>[];
  truncated: boolean;
  pages: number;
}

interface SiteMetadata extends Record<string, unknown> {
  today: string;
  timezone: string;
}

async function siteMetadata(client: PandataskClient, signal?: AbortSignal): Promise<SiteMetadata> {
  const payload = await client.request({ path: '/meta', signal });
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    throw new PandataskApiError('Pandatask metadata response was invalid.', 502, 'pandatask_invalid_metadata');
  }
  const metadata = payload as Record<string, unknown>;
  const date = isoDate.safeParse(metadata.today);
  if (!date.success || typeof metadata.timezone !== 'string' || !metadata.timezone) {
    throw new PandataskApiError('Pandatask metadata did not include a valid site date and timezone.', 502, 'pandatask_invalid_metadata');
  }
  return { ...metadata, today: date.data, timezone: metadata.timezone };
}

async function getAllTasks(
  client: PandataskClient,
  board: string,
  options: {
    archived?: boolean;
    includeTemplates?: boolean;
    privateOnly?: boolean;
    maximum?: number;
    status?: string;
    signal?: AbortSignal;
  } = {},
): Promise<CollectedTasks> {
  const maximum = Math.min(options.maximum ?? client.config.maxCollectionItems, client.config.maxCollectionItems);
  const pageSize = Math.min(200, maximum);
  const tasks: Record<string, unknown>[] = [];
  let offset = 0;
  let pages = 0;
  let hasMore = true;

  while (hasMore && tasks.length < maximum) {
    const limit = Math.min(pageSize, maximum - tasks.length);
    const payload = await client.request({
      path: boardPath(board, '/tasks'),
      query: {
        status_filter: options.status ?? '',
        archived: options.archived ? 1 : 0,
        include_templates: String(options.includeTemplates ?? false),
        private_only: options.privateOnly === undefined ? undefined : String(options.privateOnly),
        sort: 'deadline_asc',
        limit,
        offset,
      },
      signal: options.signal,
    });
    const page = collection(payload, 'tasks');
    tasks.push(...page);
    pages += 1;
    hasMore = page.length === limit;
    offset += page.length;
    if (page.length === 0) break;
  }

  return { tasks, truncated: hasMore, pages };
}

async function settledMutations(
  operations: { id: number; request: RequestOptions }[],
  client: PandataskClient,
  extra: ToolExtra,
): Promise<Record<string, unknown>> {
  const results = await mapWithConcurrency(operations, client.config.maxConcurrency, async (item, index) => {
    try {
      const value = await client.request({ ...item.request, signal: extra.signal });
      await sendProgress(extra, index + 1, operations.length, `Completed operation ${index + 1} of ${operations.length}.`);
      return { status: 'fulfilled' as const, value };
    } catch (reason) {
      await sendProgress(extra, index + 1, operations.length, `Operation ${index + 1} failed.`);
      return { status: 'rejected' as const, reason };
    }
  });
  return {
    requested: operations.length,
    succeeded: results.filter((item) => item.status === 'fulfilled').length,
    failed: results.filter((item) => item.status === 'rejected').length,
    results: results.map((result, index) =>
      result.status === 'fulfilled'
        ? { id: operations[index]?.id, success: true, response: result.value }
        : {
            id: operations[index]?.id,
            success: false,
            error: result.reason instanceof Error ? result.reason.message : String(result.reason),
          },
    ),
  };
}

async function mapWithConcurrency<T, R>(
  items: readonly T[],
  concurrency: number,
  worker: (item: T, index: number) => Promise<R>,
): Promise<R[]> {
  const results = new Array<R>(items.length);
  let nextIndex = 0;
  const workers = Array.from({ length: Math.min(concurrency, Math.max(items.length, 1)) }, async () => {
    while (nextIndex < items.length) {
      const index = nextIndex;
      nextIndex += 1;
      const item = items[index];
      if (item !== undefined) results[index] = await worker(item, index);
    }
  });
  await Promise.all(workers);
  return results;
}

async function sendProgress(extra: ToolExtra, progress: number, total: number, message: string): Promise<void> {
  const progressToken = extra._meta?.progressToken;
  if (progressToken === undefined) return;
  await extra.sendNotification({
    method: 'notifications/progress',
    params: { progressToken, progress, total, message },
  });
}

function scopedIdempotencyKey(key: string | undefined, scope: string): string | undefined {
  if (!key) return undefined;
  const scoped = `${key}:${scope}`;
  if (scoped.length <= 128) return scoped;
  return `pandatask:${createHash('sha256').update(scoped).digest('hex')}`;
}

function validatePlanDependencies(tasks: z.infer<typeof plannedTaskData>[]): void {
  tasks.forEach((task, index) => {
    const dependencies = task.depends_on_task_indexes ?? [];
    if (new Set(dependencies).size !== dependencies.length) {
      throw new Error(`Task ${index} contains duplicate dependency indexes.`);
    }
    const invalid = dependencies.find((dependency) => dependency >= index);
    if (invalid !== undefined) {
      throw new Error(`Task ${index} dependency index ${invalid} must reference an earlier task.`);
    }
  });
}

export function createPandataskServer(client: PandataskClient): McpServer {
  const server = new McpServer(
    { name: 'pandatask', version: VERSION },
    {
      instructions:
        'Pandatask manages WordPress-backed tasks. Start with connection_check and board_list_writable, then prefer board_get_context, board_get_summary, daily_briefing, or project_plan for multi-step workflows. Use granular tools when precise control is needed. Every result uses an {ok,data} or {ok,error} envelope. dry_run performs local schema/workflow preflight and sends no mutation; WordPress remains authoritative for permissions and references. Supply a stable idempotency_key when executing create or mixed batch operations. Board IDs are scopes: standard boards become discoverable after their first task, while group_* and user_* boards follow BuddyPress/user ownership.',
    },
  );
  serverProfiles.set(server, client.config.toolProfile);

  register(
    server,
    'connection_check',
    'Check Pandatask connection',
    'Verifies the WordPress Application Password and Pandatask REST access without changing data.',
    z.object({}),
    readOnly,
    async (_input, extra) => {
      const started = Date.now();
      const [response, metadata] = await Promise.all([
        client.request({ path: '/users/me/boards', signal: extra.signal }),
        siteMetadata(client, extra.signal),
      ]);
      return {
        latency_ms: Date.now() - started,
        server_version: VERSION,
        configuration: publicConfig(client.config),
        site: metadata,
        writable_boards: collection(response, 'boards'),
      };
    },
  );

  register(
    server,
    'board_list',
    'List all boards',
    'Lists every known Pandatask board. This endpoint requires a WordPress administrator.',
    z.object({ search: z.string().max(500).optional().describe('Optional case-insensitive board-name search.') }),
    readOnly,
    async ({ search }, extra) => client.request({ path: '/boards', query: { search }, signal: extra.signal }),
  );

  register(
    server,
    'board_list_writable',
    'List writable boards',
    'Lists boards the authenticated WordPress user may write to. Prefer this over board_list for least privilege.',
    z.object({}),
    readOnly,
    async (_input, extra) => client.request({ path: '/users/me/boards', signal: extra.signal }),
  );

  register(
    server,
    'board_get_context',
    'Get board context',
    'Optimized planning workflow: returns tasks, projects, categories, eligible users, and a computed summary in one MCP call.',
    z.object({
      board_name: boardName,
      include_completed: z.boolean().optional().default(false).describe('Include completed actionable tasks.'),
      include_archived: z.boolean().optional().default(false).describe('Include a bounded archived-task collection.'),
      include_templates: z.boolean().optional().default(false).describe('Include recurring templates in the returned records, but not actionable totals.'),
      task_limit: z.number().int().min(1).max(500).optional().default(100).describe('Maximum active and archived tasks returned per collection.'),
      today: isoDate.optional().describe('Override the site-local date for deterministic historical analysis.'),
      user_board_scope: z
        .enum(['private', 'across_boards'])
        .optional()
        .default('private')
        .describe('For user_ID boards, return only private-board work or the user’s cross-board aggregate.'),
    }),
    readOnly,
    async ({ board_name, include_completed, include_archived, include_templates, task_limit, today, user_board_scope }, extra) => {
      const privateOnly = /^user_\d+$/.test(board_name) && user_board_scope === 'private';
      const [taskCollection, projectPayload, categoryPayload, userPayload, archivedCollection, metadata] = await Promise.all([
        getAllTasks(client, board_name, {
          status: include_completed ? '' : 'pending_in-progress',
          includeTemplates: include_templates,
          privateOnly,
          maximum: task_limit,
          signal: extra.signal,
        }),
        client.request({ path: boardPath(board_name, '/projects'), signal: extra.signal }),
        client.request({ path: boardPath(board_name, '/categories'), signal: extra.signal }),
        client.request({ path: '/users', query: { board_name }, signal: extra.signal }),
        include_archived
          ? getAllTasks(client, board_name, {
              archived: true,
              includeTemplates: include_templates,
              privateOnly,
              maximum: task_limit,
              signal: extra.signal,
            })
          : Promise.resolve({ tasks: [], truncated: false, pages: 0 }),
        siteMetadata(client, extra.signal),
      ]);
      const generatedFor = today ?? metadata.today;
      return {
        board_name,
        generated_for: generatedFor,
        timezone: metadata.timezone,
        user_board_scope: privateOnly ? 'private' : user_board_scope,
        summary: summarizeTasks(taskCollection.tasks, generatedFor),
        tasks: taskCollection.tasks,
        archived_tasks: archivedCollection.tasks,
        projects: collection(projectPayload, 'projects').slice(0, client.config.maxCollectionItems),
        categories: collection(categoryPayload, 'categories').slice(0, client.config.maxCollectionItems),
        users: collection(userPayload, 'users').slice(0, client.config.maxCollectionItems),
        pagination: {
          tasks_truncated: taskCollection.truncated,
          archived_tasks_truncated: archivedCollection.truncated,
          task_pages: taskCollection.pages,
          archived_task_pages: archivedCollection.pages,
        },
      };
    },
  );

  register(
    server,
    'board_get_summary',
    'Summarize a board',
    'Returns status counts and focused attention lists for overdue, due-soon, blocked, high-priority, and unassigned tasks.',
    z.object({ board_name: boardName, today: isoDate.optional() }),
    readOnly,
    async ({ board_name, today }, extra) => {
      const [taskCollection, metadata] = await Promise.all([
        getAllTasks(client, board_name, { signal: extra.signal }),
        siteMetadata(client, extra.signal),
      ]);
      const generatedFor = today ?? metadata.today;
      return {
        board_name,
        generated_for: generatedFor,
        timezone: metadata.timezone,
        summary: summarizeTasks(taskCollection.tasks, generatedFor),
        truncated: taskCollection.truncated,
        pages: taskCollection.pages,
      };
    },
  );

  register(
    server,
    'board_deadline_review',
    'Review board deadlines',
    'Returns overdue and upcoming open tasks ordered by deadline and priority.',
    z.object({
      board_name: boardName,
      days: z.number().int().min(0).max(365).optional().default(14).describe('Number of site-local calendar days to look ahead.'),
      today: isoDate.optional().describe('Override the site-local date for deterministic historical analysis.'),
    }),
    readOnly,
    async ({ board_name, days, today }, extra) => {
      const [taskCollection, metadata] = await Promise.all([
        getAllTasks(client, board_name, { signal: extra.signal }),
        siteMetadata(client, extra.signal),
      ]);
      return {
        board_name,
        timezone: metadata.timezone,
        truncated: taskCollection.truncated,
        ...deadlineReview(taskCollection.tasks, days, today ?? metadata.today),
      };
    },
  );

  register(
    server,
    'board_get_workload',
    'Get board workload',
    'Computes open, overdue, and high-priority task counts per assignee.',
    z.object({ board_name: boardName, today: isoDate.optional().describe('Override the site-local date for deterministic analysis.') }),
    readOnly,
    async ({ board_name, today }, extra) => {
      const [taskCollection, metadata] = await Promise.all([
        getAllTasks(client, board_name, { signal: extra.signal }),
        siteMetadata(client, extra.signal),
      ]);
      const generatedFor = today ?? metadata.today;
      return {
        board_name,
        generated_for: generatedFor,
        timezone: metadata.timezone,
        workload: workload(taskCollection.tasks, generatedFor),
        truncated: taskCollection.truncated,
      };
    },
  );

  register(
    server,
    'daily_briefing',
    'Get daily briefing',
    'Optimized cross-board workflow: summarizes every writable board and highlights work needing attention.',
    z.object({ today: isoDate.optional() }),
    readOnly,
    async ({ today }, extra) => {
      const [boardsPayload, metadata] = await Promise.all([
        client.request({ path: '/users/me/boards', signal: extra.signal }),
        siteMetadata(client, extra.signal),
      ]);
      const allBoards = collection(boardsPayload, 'boards');
      const boards = allBoards.slice(0, client.config.maxCollectionItems);
      const generatedFor = today ?? metadata.today;
      const summaries = await mapWithConcurrency(
        boards,
        client.config.maxConcurrency,
        async (board, index) => {
          const id = String(board.id ?? '');
          try {
            const privateOnly = /^user_\d+$/.test(id);
            const taskCollection = await getAllTasks(client, id, { privateOnly, signal: extra.signal });
            await sendProgress(extra, index + 1, boards.length, `Summarized board ${index + 1} of ${boards.length}.`);
            return {
              board,
              source_scope: privateOnly ? 'private_board' : 'board',
              summary: summarizeTasks(taskCollection.tasks, generatedFor),
              truncated: taskCollection.truncated,
            };
          } catch (error) {
            return { board, error: error instanceof Error ? error.message : String(error) };
          }
        },
      );
      return {
        generated_for: generatedFor,
        timezone: metadata.timezone,
        boards: summaries,
        boards_truncated: allBoards.length > boards.length,
      };
    },
  );

  register(
    server,
    'user_search',
    'Search eligible users',
    'Finds users eligible for assignment. Supply board_name to respect group/private board scope.',
    z.object({
      board_name: boardName.optional(),
      search: z.string().max(500).optional().describe('Optional case-insensitive user name or email search.'),
      include_ids: idList.optional(),
    }),
    readOnly,
    async ({ board_name, search, include_ids }, extra) =>
      client.request({ path: '/users', query: { board_name, search, include: include_ids }, signal: extra.signal }),
  );

  register(
    server,
    'task_list',
    'List tasks',
    'Lists tasks on one board with search, status, project, archive, assignee, type, template, and sorting filters.',
    taskListInput,
    readOnly,
    async (input, extra) =>
      client.request({ path: boardPath(input.board_name, '/tasks'), query: taskListQuery(input), signal: extra.signal }),
  );

  register(
    server,
    'task_get',
    'Get task',
    'Gets full details for one task, including assignments, project, category, parent, predecessors, and rendered description.',
    z.object({ task_id: positiveId }),
    readOnly,
    async ({ task_id }, extra) => client.request({ path: `/tasks/${task_id}`, signal: extra.signal }),
  );

  register(
    server,
    'task_get_history',
    'Get task history',
    'Gets the task audit trail with field changes, old/new values, user, and timestamp.',
    z.object({ task_id: positiveId }),
    readOnly,
    async ({ task_id }, extra) => client.request({ path: `/tasks/${task_id}/history`, signal: extra.signal }),
  );

  register(
    server,
    'task_list_potential_parents',
    'List potential parent tasks',
    'Lists valid parents while excluding the current task and descendants to prevent hierarchy cycles.',
    z.object({ board_name: boardName, current_task_id: positiveId.optional() }),
    readOnly,
    async ({ board_name, current_task_id }, extra) =>
      client.request({ path: boardPath(board_name, '/potential-parents'), query: { current_task_id }, signal: extra.signal }),
  );

  register(
    server,
    'task_create',
    'Create task',
    'Creates a fully specified task, bug, recurring template, or top-level work item on a board.',
    taskCreateData.safeExtend({ board_name: boardName, dry_run: dryRunField, idempotency_key: idempotencyKey }),
    write,
    async (input, extra) =>
      client.mutate(
        {
          method: 'POST',
          path: boardPath(input.board_name, '/tasks'),
          body: mutationBody(input, ['board_name', 'dry_run', 'idempotency_key']),
          idempotencyKey: input.idempotency_key,
          signal: extra.signal,
        },
        input.dry_run,
      ),
  );

  register(
    server,
    'task_update',
    'Update task',
    'Updates any supplied task fields. Use the focused assignment, schedule, status, archive, dependency, or move tools when possible.',
    taskUpdateData.safeExtend({ task_id: positiveId, dry_run: dryRunField }),
    write,
    async (input, extra) => {
      const body = mutationBody(input, ['task_id', 'dry_run']);
      if (Object.keys(body).length === 0) throw new Error('Provide at least one task field to update.');
      return client.mutate({ method: 'PATCH', path: `/tasks/${input.task_id}`, body, signal: extra.signal }, input.dry_run);
    },
  );

  register(
    server,
    'task_delete',
    'Delete task',
    'Permanently deletes a task. Recurring tasks support this, following, or all scope.',
    z.object({
      task_id: positiveId,
      delete_scope: z
        .enum(['this', 'following', 'all'])
        .optional()
        .describe('For recurring work: delete only this occurrence, this and later occurrences, or the full series.'),
      dry_run: dryRunField,
    }),
    destructive,
    async ({ task_id, delete_scope, dry_run }, extra) =>
      client.mutate({ method: 'DELETE', path: `/tasks/${task_id}`, query: { delete_scope }, signal: extra.signal }, dry_run),
  );

  register(
    server,
    'task_set_status',
    'Set task status',
    'Moves a task to pending, in-progress, or done and optionally records a change comment.',
    z.object({
      task_id: positiveId,
      status: z.enum(['pending', 'in-progress', 'done']).describe('New workflow status.'),
      change_comment: z.string().max(2000).optional().describe('Audit-history explanation for the status change.'),
      dry_run: dryRunField,
    }),
    write,
    async ({ task_id, status, change_comment, dry_run }, extra) =>
      client.mutate(
        {
          method: 'PATCH',
          path: `/tasks/${task_id}`,
          body: { status, ...(change_comment ? { change_comment } : {}) },
          signal: extra.signal,
        },
        dry_run,
      ),
  );

  register(
    server,
    'task_set_archived',
    'Archive or unarchive task',
    'Sets the reversible archived flag without permanently deleting the task.',
    z.object({
      task_id: positiveId,
      archived: z.boolean().describe('True to archive the task; false to restore it.'),
      dry_run: dryRunField,
    }),
    write,
    async ({ task_id, archived, dry_run }, extra) =>
      client.mutate({ method: 'PATCH', path: `/tasks/${task_id}`, body: { archived }, signal: extra.signal }, dry_run),
  );

  register(
    server,
    'task_set_assignments',
    'Set task assignments',
    'Replaces, adds, or removes assignees and supervisors. Existing assignment state is read before add/remove operations.',
    z.object({
      task_id: positiveId,
      assignee_ids: idList.optional(),
      supervisor_ids: idList.optional(),
      mode: z
        .enum(['replace', 'add', 'remove'])
        .optional()
        .default('replace')
        .describe('Replace the supplied assignment groups, add IDs to them, or remove IDs from them.'),
      dry_run: dryRunField,
    }),
    write,
    async ({ task_id, assignee_ids, supervisor_ids, mode, dry_run }, extra) => {
      if (assignee_ids === undefined && supervisor_ids === undefined) {
        throw new Error('Provide assignee_ids, supervisor_ids, or both.');
      }
      const body: JsonRecord = {};
      if (mode === 'replace') {
        if (assignee_ids !== undefined) body.assigned_persons = assignee_ids;
        if (supervisor_ids !== undefined) body.supervisor_persons = supervisor_ids;
      } else {
        const current = taskObject(await client.request({ path: `/tasks/${task_id}`, signal: extra.signal }));
        const merge = (existing: number[], changes: number[]): number[] =>
          mode === 'add'
            ? [...new Set([...existing, ...changes])]
            : existing.filter((id) => !new Set(changes).has(id));
        if (assignee_ids !== undefined) body.assigned_persons = merge(numberIds(current.assigned_user_ids), assignee_ids);
        if (supervisor_ids !== undefined) body.supervisor_persons = merge(numberIds(current.supervisor_user_ids), supervisor_ids);
      }
      return client.mutate({ method: 'PATCH', path: `/tasks/${task_id}`, body, signal: extra.signal }, dry_run);
    },
  );

  register(
    server,
    'task_set_schedule',
    'Set task schedule',
    'Sets or clears start/deadline dates, relative deadline rules, and deadline notification settings.',
    z
      .object({
        task_id: positiveId,
        start_date: clearableDate.optional(),
        deadline: clearableDate.optional(),
        deadline_days_after_start: z
          .number()
          .int()
          .positive()
          .optional()
          .describe('Set the deadline this many days after start_date.'),
        notify_deadline: z.boolean().optional().describe('Enable or disable deadline notification for assigned users.'),
        notify_days_before: z.number().int().min(1).max(30).optional().describe('Days before the deadline to notify.'),
        change_comment: z.string().max(2000).optional().describe('Audit-history explanation for the schedule change.'),
        dry_run: dryRunField,
      })
      .superRefine((value, context) => {
        if (value.start_date && value.deadline && value.start_date > value.deadline) {
          context.addIssue({ code: 'custom', path: ['deadline'], message: 'deadline must be on or after start_date.' });
        }
      }),
    write,
    async (input, extra) => {
      const body = mutationBody(input, ['task_id', 'dry_run']);
      if (Object.keys(body).length === 0) throw new Error('Provide at least one schedule field.');
      return client.mutate({ method: 'PATCH', path: `/tasks/${input.task_id}`, body, signal: extra.signal }, input.dry_run);
    },
  );

  register(
    server,
    'task_set_dependencies',
    'Set task dependencies',
    'Replaces the task predecessor list. Pandatask validates same-board references and dependency cycles.',
    z.object({
      task_id: positiveId,
      predecessor_ids: idList,
      change_comment: z.string().max(2000).optional().describe('Audit-history explanation for the dependency change.'),
      dry_run: dryRunField,
    }),
    write,
    async ({ task_id, predecessor_ids, change_comment, dry_run }, extra) =>
      client.mutate(
        {
          method: 'PATCH',
          path: `/tasks/${task_id}`,
          body: { predecessors: predecessor_ids, ...(change_comment ? { change_comment } : {}) },
          signal: extra.signal,
        },
        dry_run,
      ),
  );

  register(
    server,
    'task_create_subtask',
    'Create subtask',
    'Creates a task beneath an existing parent on the specified board.',
    taskCreateData.safeExtend({
      board_name: boardName,
      parent_task_id: positiveId.describe('Existing parent task ID on the same board.'),
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
    }),
    write,
    async (input, extra) =>
      client.mutate(
        {
          method: 'POST',
          path: boardPath(input.board_name, '/tasks'),
          body: mutationBody(input, ['board_name', 'dry_run', 'idempotency_key']),
          idempotencyKey: input.idempotency_key,
          signal: extra.signal,
        },
        input.dry_run,
      ),
  );

  register(
    server,
    'task_move',
    'Move task to another board',
    'Moves one task to a destination board. Assignment and relationship validation is enforced by Pandatask.',
    z.object({
      task_id: positiveId,
      destination_board: boardName,
      change_comment: z.string().max(2000).optional().describe('Audit-history explanation for moving the task.'),
      dry_run: dryRunField,
    }),
    write,
    async ({ task_id, destination_board, change_comment, dry_run }, extra) =>
      client.mutate(
        {
          method: 'PATCH',
          path: `/tasks/${task_id}`,
          body: { board_name: destination_board, ...(change_comment ? { change_comment } : {}) },
          signal: extra.signal,
        },
        dry_run,
      ),
  );

  const bulkUpdateItem = z.object({ task_id: positiveId, changes: taskUpdateData });
  register(
    server,
    'task_bulk_update',
    'Bulk update tasks',
    'Updates multiple tasks concurrently and returns per-task success or failure. Dry-run previews every request.',
    z.object({
      updates: z
        .array(bulkUpdateItem)
        .min(1)
        .max(100)
        .describe('One to 100 task IDs with validated partial task changes; each result is reported independently.'),
      dry_run: dryRunField,
    }),
    write,
    async ({ updates, dry_run }, extra) => {
      const operations = updates.map(({ task_id, changes }) => ({
        id: task_id,
        request: { method: 'PATCH' as const, path: `/tasks/${task_id}`, body: changes as JsonRecord },
      }));
      if (effectiveDryRun(client, { dry_run })) {
        return { dry_run: true, requests: operations.map((item) => mutationPlan(client, item.request)) };
      }
      return settledMutations(operations, client, extra);
    },
  );

  register(
    server,
    'task_archive_completed',
    'Archive completed tasks',
    'Optimized cleanup workflow: finds done tasks and archives those completed on or before an optional cutoff.',
    z.object({ board_name: boardName, completed_on_or_before: isoDate.optional(), dry_run: dryRunField }),
    write,
    async ({ board_name, completed_on_or_before, dry_run }, extra) => {
      const taskCollection = await getAllTasks(client, board_name, {
        status: 'done',
        includeTemplates: false,
        signal: extra.signal,
      });
      const tasks = taskCollection.tasks.filter((task) => {
        if (!completed_on_or_before) return true;
        return typeof task.completed_at === 'string' && task.completed_at.slice(0, 10) <= completed_on_or_before;
      });
      const operations = tasks.map((task) => ({
        id: Number(task.id),
        request: { method: 'PATCH' as const, path: `/tasks/${Number(task.id)}`, body: { archived: true } },
      }));
      if (effectiveDryRun(client, { dry_run })) {
        return {
          dry_run: true,
          validation_scope: 'local_preflight',
          matched: tasks.length,
          truncated: taskCollection.truncated,
          requests: operations.map((item) => mutationPlan(client, item.request)),
        };
      }
      return {
        board_name,
        matched: tasks.length,
        truncated: taskCollection.truncated,
        ...(await settledMutations(operations, client, extra)),
      };
    },
  );

  register(
    server,
    'project_list',
    'List projects',
    'Lists projects on a board with deadlines and project-level assignments.',
    z.object({ board_name: boardName }),
    readOnly,
    async ({ board_name }, extra) => client.request({ path: boardPath(board_name, '/projects'), signal: extra.signal }),
  );

  register(
    server,
    'project_get',
    'Get project',
    'Gets one project by numeric ID.',
    z.object({ project_id: positiveId }),
    readOnly,
    async ({ project_id }, extra) => client.request({ path: `/projects/${project_id}`, signal: extra.signal }),
  );

  register(
    server,
    'project_create',
    'Create project',
    'Creates a project with description, deadline, assignees, and supervisors.',
    projectCreateData.safeExtend({ board_name: boardName, dry_run: dryRunField, idempotency_key: idempotencyKey }),
    write,
    async (input, extra) =>
      client.mutate(
        {
          method: 'POST',
          path: boardPath(input.board_name, '/projects'),
          body: mutationBody(input, ['board_name', 'dry_run', 'idempotency_key']),
          idempotencyKey: input.idempotency_key,
          signal: extra.signal,
        },
        input.dry_run,
      ),
  );

  register(
    server,
    'project_update',
    'Update project',
    'Updates supplied project fields, including deadline and project-level assignments.',
    projectUpdateData.safeExtend({ project_id: positiveId, dry_run: dryRunField }),
    write,
    async (input, extra) => {
      const body = mutationBody(input, ['project_id', 'dry_run']);
      if (Object.keys(body).length === 0) throw new Error('Provide at least one project field to update.');
      return client.mutate({ method: 'PATCH', path: `/projects/${input.project_id}`, body, signal: extra.signal }, input.dry_run);
    },
  );

  register(
    server,
    'project_delete',
    'Delete project',
    'Permanently deletes a project; its tasks remain but become unassigned from the project.',
    z.object({ project_id: positiveId, dry_run: dryRunField }),
    destructive,
    async ({ project_id, dry_run }, extra) =>
      client.mutate({ method: 'DELETE', path: `/projects/${project_id}`, signal: extra.signal }, dry_run),
  );

  const projectPlanInput = z
    .object({
      board_name: boardName,
      project: projectCreateData.describe('Project created before the ordered task steps.'),
      tasks: z.array(plannedTaskData).max(100).describe('Ordered tasks; dependencies may reference only earlier indexes.'),
      rollback_on_failure: z
        .boolean()
        .optional()
        .default(true)
        .describe('Without an idempotency key, delete work created by this workflow if a later step fails.'),
      dry_run: dryRunField,
      idempotency_key: idempotencyKey.describe(
        'Stable workflow key. When supplied, partial work is preserved and the same call can safely resume using idempotent replays.',
      ),
    })
    .superRefine((value, context) => {
      value.tasks.forEach((task, index) => {
        const dependencies = task.depends_on_task_indexes ?? [];
        if (new Set(dependencies).size !== dependencies.length) {
          context.addIssue({
            code: 'custom',
            path: ['tasks', index, 'depends_on_task_indexes'],
            message: 'Dependency indexes must be unique.',
          });
        }
        dependencies.forEach((dependency) => {
          if (dependency >= index) {
            context.addIssue({
              code: 'custom',
              path: ['tasks', index, 'depends_on_task_indexes'],
              message: `Dependency index ${dependency} must reference an earlier task.`,
            });
          }
        });
      });
    });
  register(
    server,
    'project_plan',
    'Create project plan',
    'Optimized workflow: creates a project and an ordered set of tasks, resolving dependencies by zero-based task index. Reports partial progress if a later step fails.',
    projectPlanInput,
    write,
    async ({ board_name, project, tasks, rollback_on_failure, dry_run, idempotency_key }, extra) => {
      validatePlanDependencies(tasks);
      const projectRequest: RequestOptions = {
        method: 'POST',
        path: boardPath(board_name, '/projects'),
        body: project as JsonRecord,
        idempotencyKey: scopedIdempotencyKey(idempotency_key, 'project'),
      };
      if (effectiveDryRun(client, { dry_run })) {
        return {
          dry_run: true,
          validation_scope: 'local_schema_and_dependency_preflight',
          workflow: 'project_plan',
          resumable: Boolean(idempotency_key),
          rollback_on_failure: Boolean(rollback_on_failure && !idempotency_key),
          steps: [
            mutationPlan(client, projectRequest),
            ...tasks.map((task, index) => {
              const { depends_on_task_indexes, ...taskData } = task;
              const symbolicBody: JsonRecord = {
                ...taskData,
                project_id: '$project.id',
                predecessors: (depends_on_task_indexes ?? []).map((dependencyIndex) => `$tasks[${dependencyIndex}].id`),
              };
              return {
                step: index + 2,
                depends_on_task_indexes: depends_on_task_indexes ?? [],
                note: 'Symbolic IDs are resolved from successful earlier steps during execution.',
                ...mutationPlan(client, {
                  method: 'POST',
                  path: boardPath(board_name, '/tasks'),
                  body: symbolicBody,
                  idempotencyKey: scopedIdempotencyKey(idempotency_key, `task-${index}`),
                }),
              };
            }),
          ],
        };
      }

      const createdProject = await client.request({ ...projectRequest, signal: extra.signal });
      const createdProjectId = projectId(createdProject);
      if (!createdProjectId) throw new PandataskApiError('Project was created but its ID was missing from the response.', 500);
      await sendProgress(extra, 1, tasks.length + 1, 'Created or replayed the project.');

      const createdTaskIds: number[] = [];
      const taskResults: Record<string, unknown>[] = [];
      let taskFailure: { index: number; error: string } | null = null;
      for (const [index, task] of tasks.entries()) {
        const { depends_on_task_indexes = [], ...taskData } = task;
        const body: JsonRecord = {
          ...taskData,
          project_id: createdProjectId,
          predecessors: depends_on_task_indexes.map((dependencyIndex) => createdTaskIds[dependencyIndex] as number),
        };
        try {
          const response = await client.request({
            method: 'POST',
            path: boardPath(board_name, '/tasks'),
            body,
            idempotencyKey: scopedIdempotencyKey(idempotency_key, `task-${index}`),
            signal: extra.signal,
          });
          const createdId = taskId(response);
          if (!createdId) throw new Error('Task ID missing from create response.');
          createdTaskIds.push(createdId);
          taskResults.push({ index, success: true, task_id: createdId, response });
          await sendProgress(extra, index + 2, tasks.length + 1, `Created or replayed task ${index + 1} of ${tasks.length}.`);
        } catch (error) {
          const message = error instanceof Error ? error.message : String(error);
          taskFailure = { index, error: message };
          taskResults.push({ index, success: false, error: message });
          break;
        }
      }

      const result: Record<string, unknown> = {
        project: createdProject,
        project_id: createdProjectId,
        requested_tasks: tasks.length,
        created_tasks: createdTaskIds.length,
        complete: createdTaskIds.length === tasks.length,
        task_results: taskResults,
      };

      if (!taskFailure) {
        return { ...result, idempotent: Boolean(idempotency_key) };
      }

      if (idempotency_key) {
        throw new PandataskWorkflowError(
          'Project plan stopped after a task failed. Partial work was preserved; retry the same input and idempotency_key to resume safely.',
          'project_plan_resumable_failure',
          { ...result, resumable: true, failed_step: taskFailure },
        );
      }

      const rollbackResults: Record<string, unknown>[] = [];
      if (rollback_on_failure) {
        for (const createdTaskId of [...createdTaskIds].reverse()) {
          try {
            await client.request({ method: 'DELETE', path: `/tasks/${createdTaskId}` });
            rollbackResults.push({ type: 'task', id: createdTaskId, success: true });
          } catch (error) {
            rollbackResults.push({
              type: 'task',
              id: createdTaskId,
              success: false,
              error: error instanceof Error ? error.message : String(error),
            });
          }
        }
        try {
          await client.request({ method: 'DELETE', path: `/projects/${createdProjectId}` });
          rollbackResults.push({ type: 'project', id: createdProjectId, success: true });
        } catch (error) {
          rollbackResults.push({
            type: 'project',
            id: createdProjectId,
            success: false,
            error: error instanceof Error ? error.message : String(error),
          });
        }
      }

      const rolledBack = rollback_on_failure && rollbackResults.every((item) => item.success === true);
      throw new PandataskWorkflowError(
        rolledBack
          ? 'Project plan failed and all work created by this workflow was rolled back.'
          : 'Project plan failed with partial work remaining. Inspect rollback_results before retrying.',
        rolledBack ? 'project_plan_rolled_back' : 'project_plan_partial_failure',
        {
          ...result,
          resumable: false,
          failed_step: taskFailure,
          rolled_back: rolledBack,
          rollback_results: rollbackResults,
        },
      );
    },
  );

  register(
    server,
    'category_list',
    'List categories',
    'Lists task categories on a board.',
    z.object({ board_name: boardName }),
    readOnly,
    async ({ board_name }, extra) => client.request({ path: boardPath(board_name, '/categories'), signal: extra.signal }),
  );

  register(
    server,
    'category_create',
    'Create category',
    'Creates a board-scoped category with a unique name.',
    z.object({
      board_name: boardName,
      name: z.string().min(1).max(255).describe('New board category name.'),
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
    }),
    write,
    async ({ board_name, name, dry_run, idempotency_key }, extra) =>
      client.mutate(
        {
          method: 'POST',
          path: boardPath(board_name, '/categories'),
          body: { name },
          idempotencyKey: idempotency_key,
          signal: extra.signal,
        },
        dry_run,
      ),
  );

  register(
    server,
    'category_delete',
    'Delete category',
    'Permanently deletes a category; affected tasks keep their data but category_id is cleared.',
    z.object({ category_id: positiveId, board_name: boardName, dry_run: dryRunField }),
    destructive,
    async ({ category_id, board_name, dry_run }, extra) =>
      client.mutate({ method: 'DELETE', path: `/categories/${category_id}`, body: { board_name }, signal: extra.signal }, dry_run),
  );

  register(
    server,
    'comment_list',
    'List task comments',
    'Lists the complete comment thread for a task.',
    z.object({ task_id: positiveId }),
    readOnly,
    async ({ task_id }, extra) => client.request({ path: `/tasks/${task_id}/comments`, signal: extra.signal }),
  );

  register(
    server,
    'comment_create',
    'Create task comment',
    'Adds a task comment. Pandatask @mentions use @[Display Name](UserID).',
    z.object({
      task_id: positiveId,
      comment_text: z.string().min(1).describe('Comment body; mention syntax is @[Display Name](UserID).'),
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
    }),
    write,
    async ({ task_id, comment_text, dry_run, idempotency_key }, extra) =>
      client.mutate(
        {
          method: 'POST',
          path: `/tasks/${task_id}/comments`,
          body: { comment_text },
          idempotencyKey: idempotency_key,
          signal: extra.signal,
        },
        dry_run,
      ),
  );

  register(
    server,
    'comment_update',
    'Update task comment',
    'Updates a comment when the authenticated user is its author or may administer it.',
    z.object({
      comment_id: positiveId,
      comment_text: z.string().min(1).describe('Replacement comment body.'),
      dry_run: dryRunField,
    }),
    write,
    async ({ comment_id, comment_text, dry_run }, extra) =>
      client.mutate({ method: 'PATCH', path: `/comments/${comment_id}`, body: { comment_text }, signal: extra.signal }, dry_run),
  );

  register(
    server,
    'comment_delete',
    'Delete task comment',
    'Permanently deletes a comment when the authenticated user may administer it.',
    z.object({ comment_id: positiveId, dry_run: dryRunField }),
    destructive,
    async ({ comment_id, dry_run }, extra) =>
      client.mutate({ method: 'DELETE', path: `/comments/${comment_id}`, signal: extra.signal }, dry_run),
  );

  register(
    server,
    'report_get',
    'Get board report',
    'Gets Pandatask statistics for tasks added/completed, missed deadlines, and per-person workload over a standard or custom period.',
    z
      .object({
        board_name: boardName,
        period: periodSchema.optional().default('this_week'),
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
    async ({ board_name, period, start_date, end_date }, extra) =>
      client.request({
        path: boardPath(board_name, '/report'),
        query: { period, start_date, end_date },
        signal: extra.signal,
      }),
  );

  register(
    server,
    'batch_execute',
    'Execute admin batch',
    'Executes up to 100 mixed Pandatask actions through the administrator-only batch endpoint. Use dry-run to inspect the entire payload first.',
    z.object({
      actions: z.array(batchAction).min(1).max(100).describe('Typed actions executed in the supplied order.'),
      dry_run: dryRunField,
      idempotency_key: idempotencyKey,
    }),
    destructiveBatch,
    async ({ actions, dry_run, idempotency_key }, extra) =>
      client.mutate(
        { method: 'POST', path: '/batch', body: { actions }, idempotencyKey: idempotency_key, signal: extra.signal },
        dry_run,
      ),
  );

  server.registerResource(
    'pandatask-usage-guide',
    'pandatask://guide',
    { title: 'Pandatask MCP usage guide', description: 'Workflow and safety guidance for Pandatask MCP clients', mimeType: 'text/markdown' },
    async (uri) => ({
      contents: [
        {
          uri: uri.href,
          mimeType: 'text/markdown',
          text: [
            '# Pandatask MCP',
            '',
            '1. Call `connection_check`, then `board_list_writable`.',
            '2. Prefer `board_get_context`, `board_get_summary`, `daily_briefing`, and `project_plan` for efficient multi-step work.',
            '3. Use granular task/project/category/comment tools for exact changes.',
            '4. Every result uses `{ok:true,data}` or `{ok:false,error}`; inspect structured content.',
            '5. `dry_run` is local schema/workflow preflight and sends no mutation; WordPress validates permissions and references during execution.',
            '6. Supply a stable `idempotency_key` for creates, project plans, and administrator batches so retries do not duplicate work.',
            '7. Use archive for reversible cleanup and delete only when permanent removal is intended.',
            '8. `PANDATASK_TOOL_PROFILE=core|full|admin` bounds the advertised tool surface; admin exposes administrator-only tools.',
            '9. Standard boards are scopes and appear after their first task; `group_*` and `user_*` boards follow WordPress/BuddyPress ownership.',
          ].join('\n'),
        },
      ],
    }),
  );

  server.registerPrompt(
    'plan-my-day',
    { title: 'Plan my Pandatask day', description: 'Build a prioritized daily plan from writable Pandatask boards', argsSchema: { focus: z.string().optional() } },
    ({ focus }) => ({
      messages: [
        {
          role: 'user',
          content: {
            type: 'text',
            text: `Call daily_briefing, then propose a concise ordered plan emphasizing overdue, due-today, blocked, and high-priority work${focus ? `, with this focus: ${focus}` : ''}. Do not mutate tasks unless explicitly asked.`,
          },
        },
      ],
    }),
  );

  server.registerPrompt(
    'launch-project',
    { title: 'Launch a Pandatask project', description: 'Gather context and safely create a project plan', argsSchema: { board_name: boardName, goal: z.string().min(1) } },
    ({ board_name, goal }) => ({
      messages: [
        {
          role: 'user',
          content: {
            type: 'text',
            text: `Use board_get_context for ${board_name}. Design a project and dependency-aware task plan for: ${goal}. Preview it with project_plan dry_run=true. Before execution, ask for confirmation and generate a stable idempotency_key that will be reused unchanged if the workflow must be retried.`,
          },
        },
      ],
    }),
  );

  return server;
}

export const PANDATASK_MCP_VERSION = VERSION;
