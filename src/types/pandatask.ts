import type { ApiClient } from '../api/client';

export interface PandataskUser {
	id: number;
	name: string;
	avatar_url?: string;
}

export interface PandataskTask {
	id: number;
	board_name: string;
	name: string;
	description?: string;
	checklist?: { id: string; text: string; checked: boolean }[];
	checklist_version?: number;
	checklist_total?: number;
	checklist_checked?: number;
	can_edit_checklist?: boolean;
	status: 'pending' | 'in-progress' | 'done';
	priority: number;
	start_date?: string | null;
	deadline?: string | null;
	assigned_user_ids?: number[];
	supervisor_user_ids?: number[];
	attachment_url?: string;
	attachment_protected?: boolean;
	attachment_public_source_retained?: boolean;
	[ key: string ]: unknown;
}

export interface PandataskApiSettings {
	root: string;
	nonce: string;
	apiClient?: ApiClient;
	text?: Record< string, string >;
	current_user_id?: number;
	current_user_display_name?: string;
}

export interface TaskFilters {
	search?: string;
	sort?: string;
	status?: string;
	project?: string | number;
	onlyMyTasks?: boolean;
	archived?: boolean;
	task_type_filter?: string;
}
