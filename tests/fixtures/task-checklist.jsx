import React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { ConfigProvider } from '../../src/context/ConfigContext';
import { queryKeys } from '../../src/query/queryKeys';
import TaskChecklist from '../../src/components/task-detail/TaskChecklist';
import KanbanCard from '../../src/components/KanbanCard';
import { renderHistoryItem } from '../../src/components/task-detail/taskDetailFormatters';

const initialItems = [
	{ id: 'links', text: 'Check links', checked: true },
	{ id: 'test-email', text: 'Send test email', checked: false },
	{ id: 'schedule', text: 'Schedule delivery', checked: false },
];
const mode = new URLSearchParams( window.location.search ).get( 'mode' );
let task = {
	id: 42,
	name: 'Publish the newsletter',
	board_name: 'newsletter',
	status: 'pending',
	priority: 5,
	checklist: mode === 'empty' ? [] : initialItems,
	checklist_version: 1,
	can_edit_checklist: mode !== 'readonly',
};
let failure = null;
let completedRequests = 0;
const requests = [];
const copy = ( value ) => JSON.parse( JSON.stringify( value ) );
const fields = () => ( {
	checklist: copy( task.checklist ),
	checklist_version: task.checklist_version,
	checklist_total: task.checklist.length,
	checklist_checked: task.checklist.filter( ( item ) => item.checked ).length,
	can_edit_checklist: task.can_edit_checklist,
} );
const apiClient = {
	get: async ( path ) => {
		if ( path === 'tasks/42' ) {
			return { task: { ...copy( task ), ...fields() } };
		}
		if ( path === 'boards/newsletter/tasks' ) {
			return { tasks: [ { ...copy( task ), ...fields() } ] };
		}
		throw new Error( `Unexpected GET: ${ path }` );
	},
	post: async ( path, body ) => {
		requests.push( { path, body: copy( body ) } );
		try {
		await new Promise( ( resolve ) => setTimeout( resolve, 80 ) );
		if ( path !== 'tasks/42/checklist' ) {
			throw new Error( `Unexpected POST: ${ path }` );
		}
		if ( body.expected_version !== task.checklist_version ) {
			throw Object.assign( new Error( 'Checklist conflict' ), { status: 409 } );
		}
		if ( failure === 'before' ) {
			failure = null;
			throw new Error( 'Connection interrupted. Please try again.' );
		}
		task.checklist = copy( body.items );
		task.checklist_version += 1;
		if ( failure === 'after' ) {
			failure = null;
			throw new Error( 'The response was interrupted. Check the latest checklist before retrying.' );
		}
		return fields();
		} finally {
			completedRequests += 1;
		}
	},
};
window.checklistFixture = {
	requests,
	getTask: () => copy( task ),
	completedRequests: () => completedRequests,
	failNext: ( when = 'before' ) => { failure = when; },
	externalDelete: ( id ) => {
		task.checklist = task.checklist.filter( ( item ) => item.id !== id );
		task.checklist_version += 1;
	},
	externalEdit: () => {
		task.checklist = [ ...task.checklist, { id: 'external', text: 'Check accessibility', checked: false } ];
		task.checklist_version += 1;
	},
};
const queryClient = new QueryClient( { defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } } } );
const Fixture = () => {
	const { data } = useQuery( { queryKey: queryKeys.task( 42 ), queryFn: async () => ( await apiClient.get( 'tasks/42' ) ).task } );
	const { data: board } = useQuery( { queryKey: queryKeys.tasks.board( 'newsletter' ), queryFn: async () => ( await apiClient.get( 'boards/newsletter/tasks' ) ).tasks } );
	if ( ! data || ! board ) {
		return <p>Loading…</p>;
	}
	return (
		<main className="pandat69-root pandat69-container">
			<h1>Publish the newsletter</h1>
			<p>Small steps for the next issue.</p>
			<div className="fixture-layout">
				<article className="pandat69-task-detail-view"><TaskChecklist task={ data } /></article>
				<aside aria-label="Board preview"><KanbanCard task={ board[ 0 ] } onAction={ () => {} } /></aside>
			</div>
			<section aria-label="Previous occurrence">
				{ renderHistoryItem( { field_changed: 'checklist_reset', old_value: JSON.stringify( initialItems ), new_value: '[]', change_comment: 'Checklist from occurrence starting 2026-09-01.' } ) }
			</section>
		</main>
	);
};

createRoot( document.getElementById( 'root' ) ).render(
	<ConfigProvider config={ { apiClient, boardName: 'newsletter' } }>
		<QueryClientProvider client={ queryClient }><Fixture /></QueryClientProvider>
	</ConfigProvider>
);
