import React from 'react';
import { createRoot } from 'react-dom/client';
import TaskBoard from './components/TaskBoard';
import BugTracker from './components/BugTracker';
import FloatingBugReporter from './components/FloatingBugReporter';
import { ConfigProvider } from './context/ConfigContext';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createApiClient } from './api/client';
import '../assets/scss/main.scss';

// Wrapper for simple components that need API context but not full TaskBoard
const AppWrapper = ({ apiSettings, currentUser, children, boardName }) => {
    const queryClient = new QueryClient();
    const apiClient = createApiClient(apiSettings);
    
    const config = {
        boardName,
        apiClient,
        currentUser,
        isStandalone: true,
        text: apiSettings.text || {}
    };

    return (
        <ConfigProvider config={config}>
            <QueryClientProvider client={queryClient}>
                {children}
            </QueryClientProvider>
        </ConfigProvider>
    );
};

// Expose Global API for React Integration
window.Pandatask = {
    mountBoard: (container, props) => {
        const { boardName, apiSettings, currentUser } = props;
        // Merge passed settings with global defaults if needed
        const settings = apiSettings || window.pandatask_api_settings || {};

        // Ensure container is clean
        const root = createRoot(container);
        root.render(
            <TaskBoard
                boardName={boardName}
                apiSettings={settings}
                currentUser={currentUser || {
                    id: settings.current_user_id,
                    name: settings.current_user_display_name
                }}
                isStandalone={true}
            />
        );
        return () => root.unmount(); // Return cleanup function
    }
};

// Mode A: Standalone WordPress Mount
document.addEventListener('DOMContentLoaded', () => {
    const apiSettings = window.pandatask_api_settings || {};
    const currentUser = {
        id: apiSettings.current_user_id,
        name: apiSettings.current_user_display_name
    };

    // 1. Task Board Shortcode
    const boardRoots = document.querySelectorAll('.pandat69-container');
    boardRoots.forEach(root => {
        if (!root.dataset.reactMounted) {
            const { boardName } = root.dataset;
            const reactRoot = createRoot(root);
            root.innerHTML = '';
            reactRoot.render(
                <TaskBoard 
                    boardName={boardName} 
                    apiSettings={apiSettings}
                    currentUser={currentUser}
                    isStandalone={true}
                />
            );
            root.dataset.reactMounted = "true";
        }
    });

    // 2. Bug Tracker Shortcode
    const bugRoots = document.querySelectorAll('.pandat69-bug-tracker-container');
    bugRoots.forEach(root => {
        if (!root.dataset.reactMounted) {
            const { boardName, defaultAssigneeId } = root.dataset;
            const reactRoot = createRoot(root);
            root.innerHTML = '';
            reactRoot.render(
                <AppWrapper apiSettings={apiSettings} currentUser={currentUser} boardName={boardName}>
                    <BugTracker boardName={boardName} defaultAssigneeId={defaultAssigneeId} />
                </AppWrapper>
            );
            root.dataset.reactMounted = "true";
        }
    });

    // 3. Floating Bug Reporter
    const floatRoot = document.getElementById('pandat69-floating-bug-reporter-root');
    if (floatRoot && !floatRoot.dataset.reactMounted) {
        const { boardName, defaultAssigneeId } = floatRoot.dataset;
        const reactRoot = createRoot(floatRoot);
        // Do not clear innerHTML here as it's likely empty or hidden
        reactRoot.render(
            <AppWrapper apiSettings={apiSettings} currentUser={currentUser} boardName={boardName}>
                <FloatingBugReporter boardName={boardName} defaultAssigneeId={defaultAssigneeId} />
            </AppWrapper>
        );
        floatRoot.dataset.reactMounted = "true";
    }
});
