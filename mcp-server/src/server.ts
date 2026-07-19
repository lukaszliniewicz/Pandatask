import { McpServer, type ToolCallback } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ToolAnnotations } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { PandataskApiError, PandataskClient, type JsonRecord, type MutationPreview, type RequestOptions } from './client.js';
import { publicConfig } from './config.js';
import { handled } from './result.js';
import {
  batchAction,
  boardName,
  clearableDate,
  dryRunField,
  idList,
  isoDate,
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
import { collection, deadlineReview, numberIds, summarizeTasks, todayIso, workload } from './summaries.js';

const VERSION = '1.0.0';

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

type ZodToolSchema = z.ZodType<Record<string, unknown>>;

function register<T extends ZodToolSchema>(
  server: McpServer,
  name: string,
  title: string,
  description: string,
  inputSchema: T,
  annotations: ToolAnnotations,
  operation: (input: z.output<T>) => Promise<unknown>,
): void {
  const callback = (async (input: unknown) => handled(() => operation(inputSchema.parse(input)))) as ToolCallback<T>;
  server.registerTool(
    name,
    { title, description, inputSchema, annotations },
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

async function getAllTasks(client: PandataskClient, board: string, archived = false): Promise<Record<string, unknown>[]> {
  const payload = await client.request({
    path: boardPath(board, '/tasks'),
    query: {
      status_filter: '',
      archived: archived ? 1 : 0,
      include_templates: 'true',
      sort: 'deadline_asc',
    },
  });
  return collection(payload, 'tasks');
}

async function settledMutations(
  operations: { id: number; request: RequestOptions }[],
  client: PandataskClient,
): Promise<Record<string, unknown>> {
  const results = await Promise.allSettled(operations.map((item) => client.request(item.request)));
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

export function createPandataskServer(client: PandataskClient): McpServer {
  const server = new McpServer(
    { name: 'pandatask', version: VERSION },
    {
      instructions:
        'Pandatask manages WordPress-backed tasks. Start with connection_check and board_list_writable, then prefer board_get_context, board_get_summary, daily_briefing, or project_plan for multi-step workflows. Use granular tools when precise control is needed. All mutations accept dry_run; when global PANDATASK_DRY_RUN is enabled, no mutation executes. Board IDs are scopes: standard boards become discoverable after their first task, while group_* and user_* boards follow BuddyPress/user ownership.',
    },
  );

  register(
    server,
    'connection_check',
    'Check Pandatask connection',
    'Verifies the WordPress Application Password and Pandatask REST access without changing data.',
    z.object({}),
    readOnly,
    async () => {
      const started = Date.now();
      const response = await client.request({ path: '/users/me/boards' });
      return {
        ok: true,
        latency_ms: Date.now() - started,
        server_version: VERSION,
        configuration: publicConfig(client.config),
        writable_boards: collection(response, 'boards'),
      };
    },
  );

  register(
    server,
    'board_list',
    'List all boards',
    'Lists every known Pandatask board. This endpoint requires a WordPress administrator.',
    z.object({ search: z.string().max(500).optional() }),
    readOnly,
    async ({ search }) => client.request({ path: '/boards', query: { search } }),
  );

  register(
    server,
    'board_list_writable',
    'List writable boards',
    'Lists boards the authenticated WordPress user may write to. Prefer this over board_list for least privilege.',
    z.object({}),
    readOnly,
    async () => client.request({ path: '/users/me/boards' }),
  );

  register(
    server,
    'board_get_context',
    'Get board context',
    'Optimized planning workflow: returns tasks, projects, categories, eligible users, and a computed summary in one MCP call.',
    z.object({
      board_name: boardName,
      include_completed: z.boolean().optional().default(false),
      include_archived: z.boolean().optional().default(false),
    }),
    readOnly,
    async ({ board_name, include_completed, include_archived }) => {
      const [taskPayload, projectPayload, categoryPayload, userPayload, archivedPayload] = await Promise.all([
        client.request({
          path: boardPath(board_name, '/tasks'),
          query: {
            status_filter: include_completed ? '' : 'pending_in-progress',
            archived: 0,
            include_templates: 'true',
            sort: 'deadline_asc',
          },
        }),
        client.request({ path: boardPath(board_name, '/projects') }),
        client.request({ path: boardPath(board_name, '/categories') }),
        client.request({ path: '/users', query: { board_name } }),
        include_archived
          ? client.request({
              path: boardPath(board_name, '/tasks'),
              query: { status_filter: '', archived: 1, include_templates: 'true', sort: 'deadline_asc' },
            })
          : Promise.resolve({ tasks: [] }),
      ]);
      const tasks = collection(taskPayload, 'tasks');
      return {
        board_name,
        summary: summarizeTasks(tasks),
        tasks,
        archived_tasks: collection(archivedPayload, 'tasks'),
        projects: collection(projectPayload, 'projects'),
        categories: collection(categoryPayload, 'categories'),
        users: collection(userPayload, 'users'),
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
    async ({ board_name, today }) => {
      const tasks = await getAllTasks(client, board_name);
      return { board_name, generated_for: today ?? todayIso(), summary: summarizeTasks(tasks, today ?? todayIso()) };
    },
  );

  register(
    server,
    'board_deadline_review',
    'Review board deadlines',
    'Returns overdue and upcoming open tasks ordered by deadline and priority.',
    z.object({ board_name: boardName, days: z.number().int().min(0).max(365).optional().default(14), today: isoDate.optional() }),
    readOnly,
    async ({ board_name, days, today }) => {
      const tasks = await getAllTasks(client, board_name);
      return { board_name, ...deadlineReview(tasks, days, today ?? todayIso()) };
    },
  );

  register(
    server,
    'board_get_workload',
    'Get board workload',
    'Computes open, overdue, and high-priority task counts per assignee.',
    z.object({ board_name: boardName }),
    readOnly,
    async ({ board_name }) => {
      const tasks = await getAllTasks(client, board_name);
      return { board_name, workload: workload(tasks) };
    },
  );

  register(
    server,
    'daily_briefing',
    'Get daily briefing',
    'Optimized cross-board workflow: summarizes every writable board and highlights work needing attention.',
    z.object({ today: isoDate.optional() }),
    readOnly,
    async ({ today }) => {
      const boardsPayload = await client.request({ path: '/users/me/boards' });
      const boards = collection(boardsPayload, 'boards');
      const summaries = await Promise.all(
        boards.map(async (board) => {
          const id = String(board.id ?? '');
          try {
            const tasks = await getAllTasks(client, id);
            return { board, summary: summarizeTasks(tasks, today ?? todayIso()) };
          } catch (error) {
            return { board, error: error instanceof Error ? error.message : String(error) };
          }
        }),
      );
      return { generated_for: today ?? todayIso(), boards: summaries };
    },
  );

  register(
    server,
    'user_search',
    'Search eligible users',
    'Finds users eligible for assignment. Supply board_name to respect group/private board scope.',
    z.object({
      board_name: boardName.optional(),
      search: z.string().max(500).optional(),
      include_ids: idList.optional(),
    }),
    readOnly,
    async ({ board_name, search, include_ids }) =>
      client.request({ path: '/users', query: { board_name, search, include: include_ids } }),
  );

  register(
    server,
    'task_list',
    'List tasks',
    'Lists tasks on one board with search, status, project, archive, assignee, type, template, and sorting filters.',
    taskListInput,
    readOnly,
    async (input) => client.request({ path: boardPath(input.board_name, '/tasks'), query: taskListQuery(input) }),
  );

  register(
    server,
    'task_get',
    'Get task',
    'Gets full details for one task, including assignments, project, category, parent, predecessors, and rendered description.',
    z.object({ task_id: positiveId }),
    readOnly,
    async ({ task_id }) => client.request({ path: `/tasks/${task_id}` }),
  );

  register(
    server,
    'task_get_history',
    'Get task history',
    'Gets the task audit trail with field changes, old/new values, user, and timestamp.',
    z.object({ task_id: positiveId }),
    readOnly,
    async ({ task_id }) => client.request({ path: `/tasks/${task_id}/history` }),
  );

  register(
    server,
    'task_list_potential_parents',
    'List potential parent tasks',
    'Lists valid parents while excluding the current task and descendants to prevent hierarchy cycles.',
    z.object({ board_name: boardName, current_task_id: positiveId.optional() }),
    readOnly,
    async ({ board_name, current_task_id }) =>
      client.request({ path: boardPath(board_name, '/potential-parents'), query: { current_task_id } }),
  );

  register(
    server,
    'task_create',
    'Create task',
    'Creates a fully specified task, bug, recurring template, or top-level work item on a board.',
    z.object({ board_name: boardName, ...taskCreateData.shape, dry_run: dryRunField }),
    write,
    async (input) =>
      client.mutate(
        { method: 'POST', path: boardPath(input.board_name, '/tasks'), body: mutationBody(input, ['board_name', 'dry_run']) },
        input.dry_run,
      ),
  );

  register(
    server,
    'task_update',
    'Update task',
    'Updates any supplied task fields. Use the focused assignment, schedule, status, archive, dependency, or move tools when possible.',
    z.object({ task_id: positiveId, ...taskMutableFields, dry_run: dryRunField }),
    write,
    async (input) => {
      const body = mutationBody(input, ['task_id', 'dry_run']);
      if (Object.keys(body).length === 0) throw new Error('Provide at least one task field to update.');
      return client.mutate({ method: 'PATCH', path: `/tasks/${input.task_id}`, body }, input.dry_run);
    },
  );

  register(
    server,
    'task_delete',
    'Delete task',
    'Permanently deletes a task. Recurring tasks support this, following, or all scope.',
    z.object({ task_id: positiveId, delete_scope: z.enum(['this', 'following', 'all']).optional(), dry_run: dryRunField }),
    destructive,
    async ({ task_id, delete_scope, dry_run }) =>
      client.mutate({ method: 'DELETE', path: `/tasks/${task_id}`, query: { delete_scope } }, dry_run),
  );

  register(
    server,
    'task_set_status',
    'Set task status',
    'Moves a task to pending, in-progress, or done and optionally records a change comment.',
    z.object({
      task_id: positiveId,
      status: z.enum(['pending', 'in-progress', 'done']),
      change_comment: z.string().max(2000).optional(),
      dry_run: dryRunField,
    }),
    write,
    async ({ task_id, status, change_comment, dry_run }) =>
      client.mutate(
        { method: 'PATCH', path: `/tasks/${task_id}`, body: { status, ...(change_comment ? { change_comment } : {}) } },
        dry_run,
      ),
  );

  register(
    server,
    'task_set_archived',
    'Archive or unarchive task',
    'Sets the reversible archived flag without permanently deleting the task.',
    z.object({ task_id: positiveId, archived: z.boolean(), dry_run: dryRunField }),
    write,
    async ({ task_id, archived, dry_run }) =>
      client.mutate({ method: 'PATCH', path: `/tasks/${task_id}`, body: { archived } }, dry_run),
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
      mode: z.enum(['replace', 'add', 'remove']).optional().default('replace'),
      dry_run: dryRunField,
    }),
    write,
    async ({ task_id, assignee_ids, supervisor_ids, mode, dry_run }) => {
      if (assignee_ids === undefined && supervisor_ids === undefined) {
        throw new Error('Provide assignee_ids, supervisor_ids, or both.');
      }
      const body: JsonRecord = {};
      if (mode === 'replace') {
        if (assignee_ids !== undefined) body.assigned_persons = assignee_ids;
        if (supervisor_ids !== undefined) body.supervisor_persons = supervisor_ids;
      } else {
        const current = taskObject(await client.request({ path: `/tasks/${task_id}` }));
        const merge = (existing: number[], changes: number[]): number[] =>
          mode === 'add'
            ? [...new Set([...existing, ...changes])]
            : existing.filter((id) => !new Set(changes).has(id));
        if (assignee_ids !== undefined) body.assigned_persons = merge(numberIds(current.assigned_user_ids), assignee_ids);
        if (supervisor_ids !== undefined) body.supervisor_persons = merge(numberIds(current.supervisor_user_ids), supervisor_ids);
      }
      return client.mutate({ method: 'PATCH', path: `/tasks/${task_id}`, body }, dry_run);
    },
  );

  register(
    server,
    'task_set_schedule',
    'Set task schedule',
    'Sets or clears start/deadline dates, relative deadline rules, and deadline notification settings.',
    z.object({
      task_id: positiveId,
      start_date: clearableDate.optional(),
      deadline: clearableDate.optional(),
      deadline_days_after_start: z.number().int().positive().optional(),
      notify_deadline: z.boolean().optional(),
      notify_days_before: z.number().int().min(1).max(30).optional(),
      change_comment: z.string().max(2000).optional(),
      dry_run: dryRunField,
    }),
    write,
    async (input) => {
      const body = mutationBody(input, ['task_id', 'dry_run']);
      if (Object.keys(body).length === 0) throw new Error('Provide at least one schedule field.');
      return client.mutate({ method: 'PATCH', path: `/tasks/${input.task_id}`, body }, input.dry_run);
    },
  );

  register(
    server,
    'task_set_dependencies',
    'Set task dependencies',
    'Replaces the task predecessor list. Pandatask validates same-board references and dependency cycles.',
    z.object({ task_id: positiveId, predecessor_ids: idList, change_comment: z.string().max(2000).optional(), dry_run: dryRunField }),
    write,
    async ({ task_id, predecessor_ids, change_comment, dry_run }) =>
      client.mutate(
        {
          method: 'PATCH',
          path: `/tasks/${task_id}`,
          body: { predecessors: predecessor_ids, ...(change_comment ? { change_comment } : {}) },
        },
        dry_run,
      ),
  );

  register(
    server,
    'task_create_subtask',
    'Create subtask',
    'Creates a task beneath an existing parent on the specified board.',
    z.object({ board_name: boardName, ...taskCreateData.shape, parent_task_id: positiveId, dry_run: dryRunField }),
    write,
    async (input) =>
      client.mutate(
        { method: 'POST', path: boardPath(input.board_name, '/tasks'), body: mutationBody(input, ['board_name', 'dry_run']) },
        input.dry_run,
      ),
  );

  register(
    server,
    'task_move',
    'Move task to another board',
    'Moves one task to a destination board. Assignment and relationship validation is enforced by Pandatask.',
    z.object({ task_id: positiveId, destination_board: boardName, change_comment: z.string().max(2000).optional(), dry_run: dryRunField }),
    write,
    async ({ task_id, destination_board, change_comment, dry_run }) =>
      client.mutate(
        {
          method: 'PATCH',
          path: `/tasks/${task_id}`,
          body: { board_name: destination_board, ...(change_comment ? { change_comment } : {}) },
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
    z.object({ updates: z.array(bulkUpdateItem).min(1).max(100), dry_run: dryRunField }),
    write,
    async ({ updates, dry_run }) => {
      const operations = updates.map(({ task_id, changes }) => ({
        id: task_id,
        request: { method: 'PATCH' as const, path: `/tasks/${task_id}`, body: changes as JsonRecord },
      }));
      if (effectiveDryRun(client, { dry_run })) {
        return { dry_run: true, requests: operations.map((item) => mutationPlan(client, item.request)) };
      }
      return settledMutations(operations, client);
    },
  );

  register(
    server,
    'task_archive_completed',
    'Archive completed tasks',
    'Optimized cleanup workflow: finds done tasks and archives those completed on or before an optional cutoff.',
    z.object({ board_name: boardName, completed_on_or_before: isoDate.optional(), dry_run: dryRunField }),
    write,
    async ({ board_name, completed_on_or_before, dry_run }) => {
      const payload = await client.request({
        path: boardPath(board_name, '/tasks'),
        query: { status_filter: 'done', archived: 0, include_templates: 'true', sort: 'created_at_asc' },
      });
      const tasks = collection(payload, 'tasks').filter((task) => {
        if (!completed_on_or_before) return true;
        return typeof task.completed_at === 'string' && task.completed_at.slice(0, 10) <= completed_on_or_before;
      });
      const operations = tasks.map((task) => ({
        id: Number(task.id),
        request: { method: 'PATCH' as const, path: `/tasks/${Number(task.id)}`, body: { archived: true } },
      }));
      if (effectiveDryRun(client, { dry_run })) {
        return { dry_run: true, matched: tasks.length, requests: operations.map((item) => mutationPlan(client, item.request)) };
      }
      return { board_name, matched: tasks.length, ...(await settledMutations(operations, client)) };
    },
  );

  register(
    server,
    'project_list',
    'List projects',
    'Lists projects on a board with deadlines and project-level assignments.',
    z.object({ board_name: boardName }),
    readOnly,
    async ({ board_name }) => client.request({ path: boardPath(board_name, '/projects') }),
  );

  register(
    server,
    'project_get',
    'Get project',
    'Gets one project by numeric ID.',
    z.object({ project_id: positiveId }),
    readOnly,
    async ({ project_id }) => client.request({ path: `/projects/${project_id}` }),
  );

  register(
    server,
    'project_create',
    'Create project',
    'Creates a project with description, deadline, assignees, and supervisors.',
    z.object({ board_name: boardName, ...projectCreateData.shape, dry_run: dryRunField }),
    write,
    async (input) =>
      client.mutate(
        { method: 'POST', path: boardPath(input.board_name, '/projects'), body: mutationBody(input, ['board_name', 'dry_run']) },
        input.dry_run,
      ),
  );

  register(
    server,
    'project_update',
    'Update project',
    'Updates supplied project fields, including deadline and project-level assignments.',
    z.object({ project_id: positiveId, ...projectUpdateData.shape, dry_run: dryRunField }),
    write,
    async (input) => {
      const body = mutationBody(input, ['project_id', 'dry_run']);
      if (Object.keys(body).length === 0) throw new Error('Provide at least one project field to update.');
      return client.mutate({ method: 'PATCH', path: `/projects/${input.project_id}`, body }, input.dry_run);
    },
  );

  register(
    server,
    'project_delete',
    'Delete project',
    'Permanently deletes a project; its tasks remain but become unassigned from the project.',
    z.object({ project_id: positiveId, dry_run: dryRunField }),
    destructive,
    async ({ project_id, dry_run }) => client.mutate({ method: 'DELETE', path: `/projects/${project_id}` }, dry_run),
  );

  const plannedTask = z.object({
    ...taskCreateData.shape,
    depends_on_task_indexes: z.array(z.number().int().nonnegative()).max(100).optional(),
  });
  register(
    server,
    'project_plan',
    'Create project plan',
    'Optimized workflow: creates a project and an ordered set of tasks, resolving dependencies by zero-based task index. Reports partial progress if a later step fails.',
    z.object({
      board_name: boardName,
      project: projectCreateData,
      tasks: z.array(plannedTask).max(100),
      dry_run: dryRunField,
    }),
    write,
    async ({ board_name, project, tasks, dry_run }) => {
      const projectRequest: RequestOptions = { method: 'POST', path: boardPath(board_name, '/projects'), body: project as JsonRecord };
      if (effectiveDryRun(client, { dry_run })) {
        return {
          dry_run: true,
          workflow: 'project_plan',
          steps: [
            mutationPlan(client, projectRequest),
            ...tasks.map((task, index) => {
              const { depends_on_task_indexes, ...taskData } = task;
              return {
                step: index + 2,
                depends_on_task_indexes: depends_on_task_indexes ?? [],
                note: 'project_id and resolved predecessor IDs are inserted after earlier steps succeed.',
                ...mutationPlan(client, { method: 'POST', path: boardPath(board_name, '/tasks'), body: taskData as JsonRecord }),
              };
            }),
          ],
        };
      }

      const createdProject = await client.request(projectRequest);
      const createdProjectId = projectId(createdProject);
      if (!createdProjectId) throw new PandataskApiError('Project was created but its ID was missing from the response.', 500);

      const createdTaskIds: number[] = [];
      const taskResults: Record<string, unknown>[] = [];
      for (const [index, task] of tasks.entries()) {
        const { depends_on_task_indexes = [], ...taskData } = task;
        const invalidIndex = depends_on_task_indexes.find((dependencyIndex) => dependencyIndex >= index || createdTaskIds[dependencyIndex] === undefined);
        if (invalidIndex !== undefined) {
          taskResults.push({ index, success: false, error: `Dependency index ${invalidIndex} must reference an earlier successfully created task.` });
          break;
        }
        const body: JsonRecord = {
          ...taskData,
          project_id: createdProjectId,
          predecessors: depends_on_task_indexes.map((dependencyIndex) => createdTaskIds[dependencyIndex] as number),
        };
        try {
          const response = await client.request({ method: 'POST', path: boardPath(board_name, '/tasks'), body });
          const createdId = taskId(response);
          if (!createdId) throw new Error('Task ID missing from create response.');
          createdTaskIds.push(createdId);
          taskResults.push({ index, success: true, task_id: createdId, response });
        } catch (error) {
          taskResults.push({ index, success: false, error: error instanceof Error ? error.message : String(error) });
          break;
        }
      }

      return {
        project: createdProject,
        requested_tasks: tasks.length,
        created_tasks: createdTaskIds.length,
        complete: createdTaskIds.length === tasks.length,
        task_results: taskResults,
      };
    },
  );

  register(
    server,
    'category_list',
    'List categories',
    'Lists task categories on a board.',
    z.object({ board_name: boardName }),
    readOnly,
    async ({ board_name }) => client.request({ path: boardPath(board_name, '/categories') }),
  );

  register(
    server,
    'category_create',
    'Create category',
    'Creates a board-scoped category with a unique name.',
    z.object({ board_name: boardName, name: z.string().min(1).max(255), dry_run: dryRunField }),
    write,
    async ({ board_name, name, dry_run }) =>
      client.mutate({ method: 'POST', path: boardPath(board_name, '/categories'), body: { name } }, dry_run),
  );

  register(
    server,
    'category_delete',
    'Delete category',
    'Permanently deletes a category; affected tasks keep their data but category_id is cleared.',
    z.object({ category_id: positiveId, board_name: boardName, dry_run: dryRunField }),
    destructive,
    async ({ category_id, board_name, dry_run }) =>
      client.mutate({ method: 'DELETE', path: `/categories/${category_id}`, body: { board_name } }, dry_run),
  );

  register(
    server,
    'comment_list',
    'List task comments',
    'Lists the complete comment thread for a task.',
    z.object({ task_id: positiveId }),
    readOnly,
    async ({ task_id }) => client.request({ path: `/tasks/${task_id}/comments` }),
  );

  register(
    server,
    'comment_create',
    'Create task comment',
    'Adds a task comment. Pandatask @mentions use @[Display Name](UserID).',
    z.object({ task_id: positiveId, comment_text: z.string().min(1), dry_run: dryRunField }),
    write,
    async ({ task_id, comment_text, dry_run }) =>
      client.mutate({ method: 'POST', path: `/tasks/${task_id}/comments`, body: { comment_text } }, dry_run),
  );

  register(
    server,
    'comment_update',
    'Update task comment',
    'Updates a comment when the authenticated user is its author or may administer it.',
    z.object({ comment_id: positiveId, comment_text: z.string().min(1), dry_run: dryRunField }),
    write,
    async ({ comment_id, comment_text, dry_run }) =>
      client.mutate({ method: 'PATCH', path: `/comments/${comment_id}`, body: { comment_text } }, dry_run),
  );

  register(
    server,
    'comment_delete',
    'Delete task comment',
    'Permanently deletes a comment when the authenticated user may administer it.',
    z.object({ comment_id: positiveId, dry_run: dryRunField }),
    destructive,
    async ({ comment_id, dry_run }) => client.mutate({ method: 'DELETE', path: `/comments/${comment_id}` }, dry_run),
  );

  register(
    server,
    'report_get',
    'Get board report',
    'Gets Pandatask statistics for tasks added/completed, missed deadlines, and per-person workload over a standard or custom period.',
    z.object({ board_name: boardName, period: periodSchema.optional().default('this_week'), start_date: isoDate.optional(), end_date: isoDate.optional() }),
    readOnly,
    async ({ board_name, period, start_date, end_date }) => {
      if (period === 'custom' && (!start_date || !end_date)) throw new Error('Custom reports require start_date and end_date.');
      return client.request({ path: boardPath(board_name, '/report'), query: { period, start_date, end_date } });
    },
  );

  register(
    server,
    'batch_execute',
    'Execute admin batch',
    'Executes up to 100 mixed Pandatask actions through the administrator-only batch endpoint. Use dry-run to inspect the entire payload first.',
    z.object({ actions: z.array(batchAction).min(1).max(100), dry_run: dryRunField }),
    destructive,
    async ({ actions, dry_run }) => client.mutate({ method: 'POST', path: '/batch', body: { actions } }, dry_run),
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
            '4. Preview risky work with `dry_run: true` or set `PANDATASK_DRY_RUN=true` globally.',
            '5. Use archive for reversible cleanup and delete only when permanent removal is intended.',
            '6. Standard boards are scopes and appear after their first task; `group_*` and `user_*` boards follow WordPress/BuddyPress ownership.',
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
            text: `Use board_get_context for ${board_name}. Design a project and dependency-aware task plan for: ${goal}. Preview it with project_plan dry_run=true and ask for confirmation before executing.`,
          },
        },
      ],
    }),
  );

  return server;
}

export const PANDATASK_MCP_VERSION = VERSION;
