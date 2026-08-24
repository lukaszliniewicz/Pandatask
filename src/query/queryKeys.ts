import type { TaskFilters } from '../types/pandatask';

const root = [ 'pandatask' ] as const;

export const queryKeys = {
	root,
	tasks: {
		all: () => [ ...root, 'tasks' ] as const,
		board: ( boardName: string ) =>
			[ ...root, 'tasks', boardName ] as const,
		list: ( boardName: string, filters: TaskFilters ) =>
			[ ...root, 'tasks', boardName, filters ] as const,
	},
	task: ( taskId: number | string ) =>
		[ ...root, 'task', Number( taskId ) ] as const,
	taskHistory: ( taskId: number | string ) =>
		[ ...root, 'task-history', Number( taskId ) ] as const,
	taskWork: ( taskId: number | string ) =>
		[ ...root, 'task-work', Number( taskId ) ] as const,
	work: {
		all: () => [ ...root, 'work' ] as const,
		entries: ( filters: Record< string, unknown > = {} ) =>
			[ ...root, 'work', 'entries', filters ] as const,
		suggestions: ( filters: Record< string, unknown > = {} ) =>
			[ ...root, 'work', 'suggestions', filters ] as const,
		report: ( filters: Record< string, unknown > = {} ) =>
			[ ...root, 'work', 'report', filters ] as const,
		boardReport: (
			boardName: string,
			filters: Record< string, unknown > = {}
		) => [ ...root, 'work', 'board-report', boardName, filters ] as const,
		activityTypes: () => [ ...root, 'work', 'activity-types' ] as const,
	},
	categories: ( boardName: string ) =>
		[ ...root, 'categories', boardName ] as const,
	projects: {
		all: () => [ ...root, 'projects' ] as const,
		board: ( boardName: string ) =>
			[ ...root, 'projects', boardName ] as const,
		list: ( boardName: string, privateOnly: boolean ) =>
			[ ...root, 'projects', boardName, { privateOnly } ] as const,
	},
	users: (
		boardName: string,
		search: string,
		includeUserIds: readonly number[]
	) =>
		[
			...root,
			'users',
			boardName,
			search,
			Array.from( includeUserIds ).sort(
				( left, right ) => left - right
			),
		] as const,
	reports: {
		board: ( boardName: string ) =>
			[ ...root, 'reports', boardName ] as const,
		detail: ( boardName: string, filters: Record< string, unknown > ) =>
			[ ...root, 'reports', boardName, filters ] as const,
	},
	userBoards: ( userId: number | string ) =>
		[ ...root, 'user-boards', Number( userId ) ] as const,
};
