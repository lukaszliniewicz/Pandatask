import { z } from 'zod';

export const boardName = z
  .string()
  .min(1)
  .max(191)
  .regex(/^[\w-]+$/, 'Use a Pandatask board identifier containing only letters, numbers, underscores, or hyphens.')
  .describe('Pandatask board identifier, for example project_alpha, group_42, or user_7.');

export const positiveId = z.number().int().positive().describe('Positive numeric WordPress/Pandatask record ID.');
export const idList = z
  .array(positiveId)
  .max(500)
  .refine((values) => new Set(values).size === values.length, 'Record IDs must be unique.')
  .describe('Unique numeric record IDs. Supply an empty list to clear the relationship.');
export const isoDate = z.iso.date().describe('Calendar date in YYYY-MM-DD form.');
export const clearableDate = z.union([isoDate, z.literal('')]).describe('YYYY-MM-DD date, or an empty string to clear the date.');
export const idempotencyKey = z
  .string()
  .min(8)
  .max(128)
  .regex(/^[A-Za-z0-9._:-]+$/, 'Use 8-128 letters, numbers, dots, underscores, colons, or hyphens.')
  .optional()
  .describe('Stable unique key for safely retrying this mutation for up to 24 hours.');

export const dryRunField = z
  .boolean()
  .optional()
  .default(false)
  .describe('When true, perform local schema and workflow preflight only, then return the exact planned requests without sending mutations.');

function validateDateOrder(
  value: {
    start_date?: string | undefined;
    deadline?: string | undefined;
    recurrence_ends_on?: string | undefined;
  },
  context: z.RefinementCtx,
): void {
  if (value.start_date && value.deadline && value.start_date > value.deadline) {
    context.addIssue({
      code: 'custom',
      path: ['deadline'],
      message: 'deadline must be on or after start_date.',
    });
  }
  if (value.start_date && value.recurrence_ends_on && value.start_date > value.recurrence_ends_on) {
    context.addIssue({
      code: 'custom',
      path: ['recurrence_ends_on'],
      message: 'recurrence_ends_on must be on or after start_date.',
    });
  }
}

export const taskMutableFields = {
  name: z.string().min(1).max(255).optional().describe('Short task title.'),
  description: z.string().optional().describe('Detailed task description; HTML accepted by Pandatask is sanitized server-side.'),
  status: z.enum(['pending', 'in-progress', 'done']).optional().describe('Task workflow status.'),
  priority: z.number().int().min(1).max(10).optional().describe('Priority from 1 (lowest) to 10 (highest).'),
  task_type: z.enum(['task', 'bug']).optional().describe('Standard task or bug-tracking item.'),
  bug_url: z.union([z.url(), z.literal('')]).optional().describe('Related bug URL, or empty string to clear it.'),
  start_date: clearableDate.optional().describe('Planned start date.'),
  deadline: clearableDate.optional().describe('Due date.'),
  deadline_days_after_start: z.number().int().positive().optional().describe('Relative deadline offset from start_date in days.'),
  category_id: z.number().int().nonnegative().optional().describe('Board category ID; use 0 to remove the category.'),
  project_id: z.number().int().nonnegative().optional().describe('Board project ID; use 0 to remove the project.'),
  parent_task_id: z.number().int().nonnegative().optional().describe('Parent task ID on the same board; use 0 to remove the parent.'),
  predecessors: idList.optional().describe('Predecessor task IDs on the same board.'),
  is_recurring: z.boolean().optional().describe('Whether this record is a recurring task template.'),
  recurrence_frequency: z
    .enum(['weekly', 'bi-weekly', 'monthly', 'custom_weekly'])
    .optional()
    .describe('Recurrence schedule type when is_recurring is true.'),
  recurrence_interval: z.number().int().positive().optional().describe('Number of recurrence units between generated tasks.'),
  recurrence_days: z.string().optional().describe('Comma-separated weekday numbers for custom_weekly recurrence.'),
  recurrence_ends_on: clearableDate.optional().describe('Last date on which the recurrence may generate work.'),
  notify_deadline: z.boolean().optional().describe('Enable deadline notification for assigned users.'),
  notify_days_before: z.number().int().min(1).max(30).optional().describe('Days before the deadline to notify.'),
  archived: z.boolean().optional().describe('Reversible archive state.'),
  attachment_type: z.enum(['', 'file', 'link']).optional().describe('Attachment mode, or empty string for none.'),
  attachment_url: z.union([z.url(), z.literal('')]).optional().describe('Link attachment URL, or empty string to clear it.'),
  attachment_post_id: z.number().int().nonnegative().optional().describe('WordPress Media Library attachment post ID.'),
  attachment_filename: z.string().max(255).optional().describe('Display filename for the attachment.'),
  assigned_persons: idList.optional().describe('Eligible WordPress user IDs assigned to do the task.'),
  supervisor_persons: idList.optional().describe('Eligible WordPress user IDs supervising the task.'),
  change_comment: z.string().max(2000).optional().describe('Audit-history explanation for the change.'),
};

export const taskCreateData = z
  .object({
    ...taskMutableFields,
    name: z.string().min(1).max(255).describe('Short task title.'),
  })
  .superRefine(validateDateOrder);

export const taskUpdateData = z
  .object(taskMutableFields)
  .refine((value) => Object.values(value).some((item) => item !== undefined), 'Provide at least one task field to update.')
  .superRefine(validateDateOrder);

const { project_id: _projectId, predecessors: _predecessors, ...plannedTaskMutableFields } = taskMutableFields;
export const plannedTaskData = z
  .object({
    ...plannedTaskMutableFields,
    name: z.string().min(1).max(255).describe('Short task title.'),
    depends_on_task_indexes: z
      .array(z.number().int().nonnegative())
      .max(100)
      .optional()
      .describe('Zero-based indexes of earlier tasks in this same plan.'),
  })
  .strict()
  .superRefine(validateDateOrder);

export const projectMutableFields = {
  name: z.string().min(1).max(255).optional().describe('Short project name.'),
  description: z.string().optional().describe('Project purpose, scope, or implementation notes.'),
  deadline: clearableDate.optional().describe('Project due date, or empty string to clear it.'),
  assigned_persons: idList.optional().describe('Eligible WordPress user IDs assigned to the project.'),
  supervisor_persons: idList.optional().describe('Eligible WordPress user IDs supervising the project.'),
};

export const projectCreateData = z.object({
  ...projectMutableFields,
  name: z.string().min(1).max(255).describe('Short project name.'),
});

export const projectUpdateData = z
  .object(projectMutableFields)
  .refine((value) => Object.values(value).some((item) => item !== undefined), 'Provide at least one project field to update.');

export const periodSchema = z
  .enum(['this_week', 'last_week', 'last_7_days', 'this_month', 'last_month', 'last_30_days', 'custom'])
  .describe('Named reporting period; custom requires start_date and end_date.');

const batchTaskUpdateData = z
  .object({ ...taskMutableFields, id: positiveId.describe('Task ID to update.') })
  .refine(
    (value) => Object.entries(value).some(([key, item]) => key !== 'id' && item !== undefined),
    'Provide at least one task field to update.',
  )
  .superRefine(validateDateOrder);
const batchProjectUpdateData = z
  .object({ ...projectMutableFields, id: positiveId.describe('Project ID to update.') })
  .refine(
    (value) => Object.entries(value).some(([key, item]) => key !== 'id' && item !== undefined),
    'Provide at least one project field to update.',
  );

export const batchAction = z.discriminatedUnion('action', [
  z.object({
    action: z.literal('create_task'),
    board_name: boardName,
    data: taskCreateData.describe('Task fields for the new task.'),
  }),
  z.object({
    action: z.literal('update_task'),
    data: batchTaskUpdateData.describe('Task ID and fields to update.'),
  }),
  z.object({
    action: z.literal('delete_task'),
    data: z.object({
      id: positiveId.describe('Task ID to delete.'),
      delete_scope: z.enum(['this', 'following', 'all']).optional().describe('Recurring-task deletion scope.'),
    }),
  }),
  z.object({
    action: z.literal('create_project'),
    board_name: boardName,
    data: projectCreateData.describe('Project fields for the new project.'),
  }),
  z.object({
    action: z.literal('update_project'),
    data: batchProjectUpdateData.describe('Project ID and fields to update.'),
  }),
  z.object({
    action: z.literal('delete_project'),
    data: z.object({ id: positiveId.describe('Project ID to delete.') }),
  }),
  z.object({
    action: z.literal('create_category'),
    board_name: boardName,
    data: z.object({ name: z.string().min(1).max(255).describe('New board category name.') }),
  }),
  z.object({
    action: z.literal('delete_category'),
    board_name: boardName,
    data: z.object({ id: positiveId.describe('Category ID to delete.') }),
  }),
  z.object({
    action: z.literal('create_comment'),
    data: z.object({
      task_id: positiveId.describe('Task receiving the comment.'),
      comment_text: z.string().min(1).describe('Comment body; Pandatask mention syntax is @[Display Name](UserID).'),
    }),
  }),
  z.object({
    action: z.literal('update_comment'),
    data: z.object({
      id: positiveId.describe('Comment ID to update.'),
      comment_text: z.string().min(1).describe('Replacement comment body.'),
    }),
  }),
  z.object({
    action: z.literal('delete_comment'),
    data: z.object({ id: positiveId.describe('Comment ID to delete.') }),
  }),
]);

export const taskListInput = z.object({
  board_name: boardName,
  search: z.string().max(500).optional().describe('Case-insensitive search across task names and descriptions.'),
  status: z
    .enum(['all', 'pending', 'in-progress', 'done', 'missed_deadline', 'pending_in-progress'])
    .optional()
    .default('pending_in-progress')
    .describe('Task status filter.'),
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
    .default('deadline_asc')
    .describe('Stable server-side task ordering.'),
  project_id: z.union([positiveId, z.literal('none')]).optional().describe('Project ID, or none for tasks without a project.'),
  archived: z.boolean().optional().default(false).describe('Return archived rather than active tasks.'),
  assigned_to_me: z.boolean().optional().describe('Restrict to tasks assigned to the authenticated user.'),
  private_only: z.boolean().optional().describe('For user_ID boards, restrict the cross-board personal view to the private board itself.'),
  include_templates: z.boolean().optional().default(false).describe('Include recurring templates; false is recommended for actionable work.'),
  task_type: z.enum(['task', 'bug']).optional().describe('Restrict to tasks or bugs.'),
  limit: z.number().int().min(1).max(500).optional().default(100).describe('Maximum tasks returned in this page.'),
  offset: z.number().int().nonnegative().optional().default(0).describe('Zero-based task offset for pagination.'),
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
    limit: input.limit,
    offset: input.offset,
  };
}
