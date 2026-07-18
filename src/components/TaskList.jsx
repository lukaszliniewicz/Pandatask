import React from 'react';
import TaskItem from './TaskItem';

const TaskList = ({ tasks, onTaskAction }) => {
    if (!tasks || tasks.length === 0) {
        return (
            <div className="pandat69-task-list-container">
                <ul className="pandat69-task-list">
                    <li className="pandat69-no-tasks">No tasks found.</li>
                </ul>
            </div>
        );
    }

    // Organize tasks (Parent/Child)
    // We create a map to easily find parents
    const taskMap = {};
    tasks.forEach(task => {
        taskMap[task.id] = { ...task, children: [] };
    });

    const rootTasks = [];
    tasks.forEach(task => {
        if (task.parent_task_id && taskMap[task.parent_task_id]) {
            taskMap[task.parent_task_id].children.push(taskMap[task.id]);
        } else {
            rootTasks.push(taskMap[task.id]);
        }
    });

    const renderTaskTree = (task) => (
        <React.Fragment key={task.id}>
            <TaskItem task={task} onAction={onTaskAction} />
            {task.children.map(renderTaskTree)}
        </React.Fragment>
    );

    return (
        <div className="pandat69-task-list-container">
            <ul className="pandat69-task-list">
                {rootTasks.map(renderTaskTree)}
            </ul>
        </div>
    );
};

export default TaskList;
