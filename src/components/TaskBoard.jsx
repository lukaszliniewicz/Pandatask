import React, { useMemo } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider } from '../context/ConfigContext';
import Layout from './Layout';
import { createApiClient } from '../api/client';

const TaskBoard = ({ boardName, apiSettings, currentUser, isStandalone = false }) => {
    // Initialize QueryClient
    // We create it inside useMemo to ensure it persists across re-renders but is unique to this instance if needed.
    // In integrated mode, the parent app might provide one, but TaskBoard can wrap itself in one if it wants isolated cache.
    const queryClient = useMemo(() => new QueryClient({
        defaultOptions: {
            queries: {
                refetchOnWindowFocus: false,
                retry: 1,
            },
        },
    }), []);

    // Create API Client
    const apiClient = useMemo(() => {
        // If apiSettings provides an axios instance (apiClient), use it.
        // This supports the 'Integrated Mode' where the host app passes its own client.
        if (apiSettings.apiClient) {
            return apiSettings.apiClient;
        }
        // Otherwise create one from root/nonce (Standalone Mode)
        return createApiClient(apiSettings);
    }, [apiSettings]);

    const config = useMemo(() => ({
        boardName,
        apiClient,
        currentUser,
        isStandalone,
        // Pass specific settings like text strings if they exist
        text: apiSettings.text || {}
    }), [boardName, apiClient, currentUser, isStandalone, apiSettings]);

    return (
        <ConfigProvider config={config}>
            <QueryClientProvider client={queryClient}>
                <Layout />
            </QueryClientProvider>
        </ConfigProvider>
    );
};

export default TaskBoard;
