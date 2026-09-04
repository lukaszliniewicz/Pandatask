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
  'task_list_visible',
  'task_get',
  'task_create',
  'task_update',
  'task_set_status',
  'task_reopen',
  'task_move_preview',
  'task_move',
  'task_follow_up_list',
  'task_follow_up_create',
  'inbox_list',
  'inbox_capture',
  'inbox_set_state',
  'inbox_shared_with_me',
  'inbox_delegate_list',
  'inbox_delegate_set',
  'project_list',
  'project_get',
  'project_workspace_get',
  'project_reference_list',
  'project_reference_add',
  'project_reference_update',
  'project_reference_remove',
  'project_reference_export',
  'project_reference_import',
  'project_create',
  'project_update',
  'project_plan',
  'report_get',
  'task_complete',
  'task_time_resolve',
  'task_time_log',
  'task_work_get',
  'work_log',
  'work_list',
  'work_get',
  'work_update',
  'work_delete',
  'work_type_list',
  'work_type_create',
  'work_type_update',
  'work_type_archive',
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
