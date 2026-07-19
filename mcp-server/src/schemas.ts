import { z } from 'zod';

export const boardName = z
  .string()
  .min(1)
  .max(191)
  .regex(/^[\w-]+$/, 'Use a Pandatask board identifier containing only letters, numbers, underscores, or hyphens.')
  .describe('Pandatask board identifier, for example project_alpha, group_42, or user_7.');

export const positiveId = z.number().int().positive();
export const idList = z.array(positiveId).max(500);
export const isoDate = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD.');
export const clearableDate = z.union([isoDate, z.literal('')]);

export const dryRunField = z
  .boolean()
  .optional()
  .default(false)
  .describe('When true, validate and preview the mutation without sending it. Global dry-run mode always wins.');

export const taskMutableFields = {
  name: z.string().min(1).max(255).optional(),
  description: z.string().optional(),
  status: z.enum(['pending', 'in-progress', 'done']).optional(),
  priority: z.number().int().min(1).max(10).optional(),
  task_type: z.enum(['task', 'bug']).optional(),
  bug_url: z.union([z.url(), z.literal('')]).optional(),
  start_date: clearableDate.optional(),
  deadline: clearableDate.optional(),
  deadline_days_after_start: z.number().int().positive().optional(),
  category_id: z.number().int().nonnegative().optional(),
  project_id: z.number().int().nonnegative().optional(),
  parent_task_id: z.number().int().nonnegative().optional(),
  predecessors: idList.optional(),
  is_recurring: z.boolean().optional(),
  recurrence_frequency: z.enum(['weekly', 'bi-weekly', 'monthly', 'custom_weekly']).optional(),
  recurrence_interval: z.number().int().positive().optional(),
  recurrence_days: z.string().optional(),
  recurrence_ends_on: clearableDate.optional(),
  notify_deadline: z.boolean().optional(),
  notify_days_before: z.number().int().min(1).max(30).optional(),
  archived: z.boolean().optional(),
  attachment_type: z.enum(['', 'file', 'link']).optional(),
  attachment_url: z.union([z.url(), z.literal('')]).optional(),
  attachment_post_id: z.number().int().nonnegative().optional(),
  attachment_filename: z.string().max(255).optional(),
  assigned_persons: idList.optional(),
  supervisor_persons: idList.optional(),
  change_comment: z.string().max(2000).optional(),
};

export const taskCreateData = z.object({
  ...taskMutableFields,
  name: z.string().min(1).max(255),
});

export const taskUpdateData = z.object(taskMutableFields).refine(
  (value) => Object.values(value).some((item) => item !== undefined),
  'Provide at least one task field to update.',
);

export const projectMutableFields = {
  name: z.string().min(1).max(255).optional(),
  description: z.string().optional(),
  deadline: clearableDate.optional(),
  assigned_persons: idList.optional(),
  supervisor_persons: idList.optional(),
};

export const projectCreateData = z.object({
  ...projectMutableFields,
  name: z.string().min(1).max(255),
});

export const projectUpdateData = z.object(projectMutableFields).refine(
  (value) => Object.values(value).some((item) => item !== undefined),
  'Provide at least one project field to update.',
);

export const periodSchema = z.enum([
  'this_week',
  'last_week',
  'last_7_days',
  'this_month',
  'last_month',
  'last_30_days',
  'custom',
]);

export const batchAction = z.object({
  action: z.enum([
    'create_task',
    'update_task',
    'delete_task',
    'create_project',
    'update_project',
    'delete_project',
    'create_category',
    'delete_category',
    'create_comment',
    'update_comment',
    'delete_comment',
  ]),
  board_name: boardName.optional(),
  data: z.record(z.string(), z.unknown()),
});

export const taskListInput = z.object({
  board_name: boardName,
  search: z.string().max(500).optional(),
  status: z.enum(['all', 'pending', 'in-progress', 'done', 'missed_deadline', 'pending_in-progress']).optional().default('pending_in-progress'),
  sort: z
    .enum([
      'deadline_asc',
      'deadline_desc',
      'priority_asc',
      'priority_desc',
      'created_at_asc',
      'created_at_desc',
      'name_asc',
      'name_desc',
      'status_asc',
      'status_desc',
    ])
    .optional()
    .default('deadline_asc'),
  project_id: z.union([positiveId, z.literal('none')]).optional(),
  archived: z.boolean().optional().default(false),
  assigned_to_me: z.boolean().optional(),
  private_only: z.boolean().optional(),
  include_templates: z.boolean().optional().default(true),
  task_type: z.enum(['task', 'bug']).optional(),
});

export function taskListQuery(input: z.infer<typeof taskListInput>): Record<string, string | number> {
  return {
    ...(input.search ? { search: input.search } : {}),
    ...(input.status !== 'all' ? { status_filter: input.status } : {}),
    sort: input.sort,
    ...(input.project_id !== undefined ? { project_filter: input.project_id } : {}),
    archived: input.archived ? 1 : 0,
    ...(input.assigned_to_me !== undefined ? { assigned_to_me: String(input.assigned_to_me) } : {}),
    ...(input.private_only !== undefined ? { private_only: String(input.private_only) } : {}),
    include_templates: String(input.include_templates),
    ...(input.task_type ? { task_type_filter: input.task_type } : {}),
  };
}
