import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { ConfigProvider } from '../../src/context/ConfigContext';
import { CompletionProvider } from '../../src/context/CompletionContext';
import { queryKeys } from '../../src/query/queryKeys';
import TaskForm from '../../src/components/TaskForm';
import TaskChecklist from '../../src/components/task-detail/TaskChecklist';
import TaskRecurrenceCard from '../../src/components/task-detail/TaskRecurrenceCard';
import CalendarView from '../../src/components/CalendarView';
import RecurringDeleteModal from '../../src/components/RecurringDeleteModal';

const copy = value => JSON.parse(JSON.stringify(value));
const mode = new URLSearchParams(window.location.search).get('mode');
const date = new Date();
const scheduled = day => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
const base = { board_name: 'newsletter', name: 'Publish newsletter', status: 'pending', priority: 5, description: '', task_type: 'task', is_recurring: 1, recurrence_series_id: 7, recurrence_frequency: 'weekly', recurrence_interval: 1, recurrence_days: '', checklist_version: 1, can_edit_checklist: true, assigned_user_ids: [], supervisor_user_ids: [], predecessor_ids: [] };
let tasks = [
    {...base, id: 42, status: 'done', recurrence_sequence: 1, start_date: scheduled(1), deadline: scheduled(1), recurrence_scheduled_start: scheduled(1), checklist: [{id:'review',text:'Review newsletter',checked:true}]},
    {...base, id: 43, recurrence_sequence: 2, start_date: scheduled(8), deadline: scheduled(8), recurrence_scheduled_start: scheduled(8), checklist: [{id:'review',text:'Review newsletter',checked:false}]},
];
let series = { id: 7, version: 3, active: true, current_task_id: 43, next_start_date: scheduled(15), can_edit: true, template: {checklist:[{id:'review',text:'Review newsletter',checked:false}]} };
if (mode === 'stopped') series.active = false;
const requests = [];
const apiClient = {
    get: async path => {
        if (/^tasks\/\d+\/recurrence$/.test(path)) return copy({series,occurrences:tasks.map(t=>({...t,checklist_total:t.checklist.length,checklist_checked:t.checklist.filter(i=>i.checked).length})).reverse(),has_more:false});
        if (/^tasks\/\d+$/.test(path)) return {task: copy(tasks.find(t=>t.id===Number(path.split('/')[1])))};
        if (path.endsWith('/projects')) return {projects:[]};
        if (path.endsWith('/categories')) return {categories:[]};
        if (path.endsWith('/tasks')) return {tasks:copy(tasks)};
        if (path === 'users/me/boards') return {boards:[]};
        if (path === 'users') return {users:[]};
        throw new Error(`Unexpected GET ${path}`);
    },
    post: async (path, body) => {
        requests.push({path,body:copy(body)});
        const task = tasks.find(t=>t.id===Number(path.split('/')[1]));
        if (body.recurrence_scope === 'future' && body.expected_series_version !== series.version) throw Object.assign(new Error('Series changed'),{status:409});
        if (path.endsWith('/checklist')) {
            task.checklist=copy(body.items); task.checklist_version++;
            if (body.recurrence_scope === 'future') {series.template.checklist=body.items.map(i=>({...i,checked:false})); series.version++;}
            return copy(task);
        }
        Object.assign(task,copy(body));
        if (body.recurrence_scope === 'future') series.version++;
        return {task:copy(task)};
    },
};
window.recurrenceFixture={requests, tasks:()=>copy(tasks), series:()=>copy(series), externalChange:()=>{series.version++;}};
const queryClient = new QueryClient({defaultOptions:{queries:{retry:false,refetchOnWindowFocus:false}}});
const Fixture = () => {
    const [id,setId]=useState(mode==='historical'?42:43);
    const [editing,setEditing]=useState(mode==='form');
    const [deleting,setDeleting]=useState(false);
    const {data:task}=useQuery({queryKey:queryKeys.task(id),queryFn:async()=>(await apiClient.get(`tasks/${id}`)).task});
    if (!task) return <p>Loading…</p>;
    return <main className="pandat69-root pandat69-container">
        <h1>{task.name}</h1>
        <p aria-label="Selected task">Task #{task.id}</p>
        {editing ? <TaskForm key={id} task={task} onClose={()=>setEditing(false)} /> : <>
            <button type="button" onClick={()=>setEditing(true)}>Edit occurrence</button>
            <button type="button" onClick={()=>setDeleting(true)}>Manage series</button>
            <TaskRecurrenceCard key={id} task={task} onNavigate={setId}/>
            <TaskChecklist key={`checklist-${id}`} task={task}/>
            {mode==='calendar' && <CalendarView tasks={tasks} onTaskAction={(_,selected)=>setId(selected.id)}/>}
        </>}
        <RecurringDeleteModal isOpen={deleting} onClose={()=>setDeleting(false)} onConfirm={scope=>{requests.push({scope});setDeleting(false);}}/>
    </main>;
};
createRoot(document.getElementById('root')).render(<ConfigProvider config={{apiClient,boardName:'newsletter',currentUser:{id:1},text:{},features:{workLog:false}}}><QueryClientProvider client={queryClient}><CompletionProvider><Fixture/></CompletionProvider></QueryClientProvider></ConfigProvider>);
