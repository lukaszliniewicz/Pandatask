import test from 'node:test';
import assert from 'node:assert/strict';
import {
	buildTaskListHierarchy,
	countTaskTree,
	groupTaskRoots,
} from '../src/taskListModel.mjs';

const tasks = [
	{
		id: 1,
		name: 'Project B parent',
		project_id: 20,
		project_name: 'Project B',
		board_name: 'group_10',
		board_display_name: 'Trustees',
	},
	{
		id: 2,
		name: 'Project B child',
		parent_task_id: 1,
		project_id: 20,
		project_name: 'Project B',
		board_name: 'group_10',
		board_display_name: 'Trustees',
	},
	{
		id: 3,
		name: 'Project A task',
		project_id: 10,
		project_name: 'Project A',
		board_name: 'group_10',
		board_display_name: 'Trustees',
	},
	{
		id: 4,
		name: 'Unassigned task',
		board_name: 'group_10',
		board_display_name: 'Trustees',
	},
];

test('task list hierarchy preserves children and project groups in source order', () => {
	const roots = buildTaskListHierarchy(tasks);
	const contexts = groupTaskRoots(roots);

	assert.deepEqual(
		roots.map((task) => task.id),
		[1, 3, 4]
	);
	assert.deepEqual(
		roots[0].children.map((task) => task.id),
		[2]
	);
	assert.deepEqual(
		contexts[0].projects.map((project) => project.label),
		['Project B', 'Project A', 'No project']
	);
	assert.equal(countTaskTree(contexts[0].projects[0].tasks), 2);
});

test('project grouping can be disabled without removing personal board headings', () => {
	const roots = buildTaskListHierarchy([
		{ ...tasks[0], assigned_user_ids: [7] },
		{ ...tasks[2], id: 5, creator_id: 7, assigned_user_ids: [] },
	]);
	const contexts = groupTaskRoots(roots, {
		isUserBoard: true,
		currentUserId: 7,
		groupByProject: false,
	});

	assert.deepEqual(
		contexts.map((context) => context.label),
		['Trustees', 'Added by me']
	);
	assert.ok(contexts.every((context) => context.projects.length === 1));
	assert.ok(contexts.every((context) => context.projects[0].label === ''));
});

test('legacy cyclic tasks remain reachable in the list model', () => {
	const roots = buildTaskListHierarchy([
		{ id: 8, parent_task_id: 9 },
		{ id: 9, parent_task_id: 8 },
	]);

	assert.deepEqual(
		roots.map((task) => task.id),
		[8, 9]
	);
});
