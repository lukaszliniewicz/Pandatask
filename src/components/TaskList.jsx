import React from 'react';
import { useConfig } from '../context/ConfigContext';
import {
    buildTaskListHierarchy,
    countTaskTree,
    groupTaskRoots,
} from '../taskListModel.mjs';
import TaskItem from './TaskItem';

const TaskList = ({ tasks, onTaskAction, groupByProject = true }) => {
    const { boardName, currentUser } = useConfig();

    if (!tasks || tasks.length === 0) {
        return (
            <div className="pandat69-task-list-container">
                <ul className="pandat69-task-list">
                    <li className="pandat69-no-tasks">No tasks found.</li>
                </ul>
            </div>
        );
    }

    const rootTasks = buildTaskListHierarchy(tasks);
    const groups = groupTaskRoots(rootTasks, {
        isUserBoard: boardName?.startsWith('user_'),
        currentUserId: currentUser?.id,
        groupByProject,
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
                {groups.map((context) => (
                    <React.Fragment key={context.key}>
                        {context.label && (
                            <li className="pandat69-task-context-heading">
                                {context.label}
                            </li>
                        )}
                        {context.projects.map((project) => (
                            <React.Fragment key={`${context.key}-${project.key}`}>
                                {project.label && (
                                    <li className="pandat69-task-project-heading">
                                        <span>{project.label}</span>
                                        <span className="pandat69-task-group-count">
                                            {countTaskTree(project.tasks)}
                                        </span>
                                    </li>
                                )}
                                {project.tasks.map(renderTaskTree)}
                            </React.Fragment>
                        ))}
                    </React.Fragment>
                ))}
            </ul>
        </div>
    );
};

export default TaskList;
