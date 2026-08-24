import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';

export type ToolProfile = 'core' | 'full' | 'admin';

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
  'task_complete',
  'task_time_resolve',
  'work_log',
  'work_list',
  'work_report',
]);

export function setServerToolProfile(server: McpServer, profile: ToolProfile): void {
  serverProfiles.set(server, profile);
}

export function toolEnabledForServer(server: McpServer, name: string): boolean {
  const profile = serverProfiles.get(server) ?? 'full';
  if (profile === 'admin') return true;
  if (adminTools.has(name)) return false;
  return profile === 'full' || coreTools.has(name);
}
