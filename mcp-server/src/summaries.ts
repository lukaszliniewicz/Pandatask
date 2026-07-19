type UnknownRecord = Record<string, unknown>;

function record(value: unknown): UnknownRecord {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as UnknownRecord) : {};
}

export function collection(value: unknown, key: string): UnknownRecord[] {
  const payload = record(value);
  const items = Array.isArray(payload[key]) ? payload[key] : Array.isArray(value) ? value : [];
  return items.filter((item): item is UnknownRecord => item !== null && typeof item === 'object' && !Array.isArray(item));
}

export function numberIds(value: unknown): number[] {
  if (Array.isArray(value)) {
    return value.map(Number).filter((item) => Number.isInteger(item) && item > 0);
  }
  if (typeof value === 'string' && value.trim()) {
    return value.split(',').map(Number).filter((item) => Number.isInteger(item) && item > 0);
  }
  return [];
}

function enabled(value: unknown): boolean {
  return value === true || value === 1 || value === '1';
}

function dateValue(value: unknown): string | null {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10) : null;
}

function compactTask(task: UnknownRecord): UnknownRecord {
  return {
    id: task.id,
    name: task.name,
    status: task.status,
    priority: task.priority,
    deadline: task.deadline ?? null,
    project_id: task.project_id ?? null,
    project_name: task.project_name ?? null,
    assigned_user_ids: numberIds(task.assigned_user_ids),
    supervisor_user_ids: numberIds(task.supervisor_user_ids),
    is_blocked: enabled(task.is_blocked),
  };
}

function addDays(date: string, days: number): string {
  const parsed = new Date(`${date}T00:00:00Z`);
  parsed.setUTCDate(parsed.getUTCDate() + days);
  return parsed.toISOString().slice(0, 10);
}

export function todayIso(now: Date = new Date()): string {
  return now.toISOString().slice(0, 10);
}

export function summarizeTasks(tasks: UnknownRecord[], today = todayIso()): UnknownRecord {
  const open = tasks.filter((task) => task.status !== 'done');
  const overdue = open.filter((task) => {
    const deadline = dateValue(task.deadline);
    return deadline !== null && deadline < today;
  });
  const dueToday = open.filter((task) => dateValue(task.deadline) === today);
  const horizon = addDays(today, 7);
  const dueNext7Days = open.filter((task) => {
    const deadline = dateValue(task.deadline);
    return deadline !== null && deadline > today && deadline <= horizon;
  });
  const highPriority = open.filter((task) => Number(task.priority) >= 8);
  const unassigned = open.filter((task) => numberIds(task.assigned_user_ids).length === 0);
  const blocked = open.filter((task) => enabled(task.is_blocked));

  return {
    total: tasks.length,
    by_status: {
      pending: tasks.filter((task) => task.status === 'pending').length,
      in_progress: tasks.filter((task) => task.status === 'in-progress').length,
      done: tasks.filter((task) => task.status === 'done').length,
    },
    overdue: overdue.length,
    due_today: dueToday.length,
    due_next_7_days: dueNext7Days.length,
    high_priority_open: highPriority.length,
    unassigned_open: unassigned.length,
    blocked_open: blocked.length,
    recurring_templates: tasks.filter((task) => enabled(task.is_recurring)).length,
    attention: {
      overdue: overdue.slice(0, 25).map(compactTask),
      due_today: dueToday.slice(0, 25).map(compactTask),
      due_next_7_days: dueNext7Days.slice(0, 25).map(compactTask),
      high_priority: highPriority.slice(0, 25).map(compactTask),
      blocked: blocked.slice(0, 25).map(compactTask),
      unassigned: unassigned.slice(0, 25).map(compactTask),
    },
  };
}

export function workload(tasks: UnknownRecord[]): UnknownRecord[] {
  const users = new Map<number, { user_id: number; open: number; overdue: number; high_priority: number; task_ids: number[] }>();
  const today = todayIso();
  for (const task of tasks.filter((item) => item.status !== 'done')) {
    for (const userId of numberIds(task.assigned_user_ids)) {
      const current = users.get(userId) ?? { user_id: userId, open: 0, overdue: 0, high_priority: 0, task_ids: [] };
      current.open += 1;
      if ((dateValue(task.deadline) ?? '9999-12-31') < today) current.overdue += 1;
      if (Number(task.priority) >= 8) current.high_priority += 1;
      if (current.task_ids.length < 50) current.task_ids.push(Number(task.id));
      users.set(userId, current);
    }
  }
  return [...users.values()].sort((left, right) => right.open - left.open || right.overdue - left.overdue);
}

export function deadlineReview(tasks: UnknownRecord[], days: number, today = todayIso()): UnknownRecord {
  const horizon = addDays(today, days);
  const relevant = tasks
    .filter((task) => task.status !== 'done')
    .filter((task) => {
      const deadline = dateValue(task.deadline);
      return deadline !== null && deadline <= horizon;
    })
    .sort((left, right) => String(left.deadline).localeCompare(String(right.deadline)) || Number(right.priority) - Number(left.priority));
  return {
    today,
    horizon,
    days,
    count: relevant.length,
    tasks: relevant.map(compactTask),
  };
}
