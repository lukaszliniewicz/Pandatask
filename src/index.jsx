import React, { useMemo } from 'react';
import { createRoot } from 'react-dom/client';
import TaskBoard from './components/TaskBoard';
import BugTracker from './components/BugTracker';
import FloatingBugReporter from './components/FloatingBugReporter';
import { ConfigProvider } from './context/ConfigContext';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createApiClient } from './api/client';
import '../assets/scss/main.scss';

const applyPandataskBoundary = (root) => {
    if (!root) return;

    root.classList.add(
        'pandat69-root',
        'iarf-app',
        'iarf-app--pandatask',
        'iarf-plugin',
        'iarf-plugin--pandatask'
    );
    root.setAttribute('data-iarf-product', 'pandatask');
    root.setAttribute('data-iarf-app', 'pandatask');
    root.setAttribute('data-iarf-plugin', 'pandatask');
    root.setAttribute('data-iarf-product-kind', 'react-plugin');
};

// Wrapper for simple components that need API context but not full TaskBoard
const AppWrapper = ({ apiSettings, currentUser, children, boardName }) => {
    const queryClient = useMemo(() => new QueryClient(), []);
    const apiClient = useMemo(() => createApiClient(apiSettings), [apiSettings]);
    
    const config = useMemo(() => ({
        boardName,
        apiClient,
        currentUser,
        isStandalone: true,
        text: apiSettings.text || {}
    }), [apiClient, apiSettings.text, boardName, currentUser]);

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

        applyPandataskBoundary(container);

        container.replaceChildren();
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
    },
    mountFloatingBugReporter: (container, props = {}) => {
        const {
            boardName,
            defaultAssigneeId,
            apiSettings,
            currentUser,
            initialOpen = false
        } = props;
        const settings = apiSettings || window.pandatask_api_settings || {};
        const resolvedBoardName = boardName || container?.dataset?.boardName;

        applyPandataskBoundary(container);

        container.replaceChildren();
        const root = createRoot(container);
        root.render(
            <AppWrapper
                apiSettings={settings}
                currentUser={currentUser || {
                    id: settings.current_user_id,
                    name: settings.current_user_display_name
                }}
                boardName={resolvedBoardName}
            >
                <FloatingBugReporter
                    boardName={resolvedBoardName}
                    defaultAssigneeId={defaultAssigneeId || container?.dataset?.defaultAssigneeId}
                    initialOpen={initialOpen}
                />
            </AppWrapper>
        );
        container.dataset.reactMounted = "true";
        return () => root.unmount();
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
            applyPandataskBoundary(root);
            root.replaceChildren();
            const reactRoot = createRoot(root);
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
            applyPandataskBoundary(root);
            root.replaceChildren();
            const reactRoot = createRoot(root);
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
        applyPandataskBoundary(floatRoot);
        floatRoot.replaceChildren();
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
