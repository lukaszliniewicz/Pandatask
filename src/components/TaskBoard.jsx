import React, { useMemo } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { ConfigProvider } from '../context/ConfigContext';
import Layout from './Layout';
import { createApiClient } from '../api/client';
import { createPandataskQueryClient } from '../query/createQueryClient';
import AppErrorBoundary from './AppErrorBoundary';

const TaskBoard = ({ boardName, apiSettings, currentUser, isStandalone = false }) => {
    // Initialize QueryClient
    // We create it inside useMemo to ensure it persists across re-renders but is unique to this instance if needed.
    // In integrated mode, the parent app might provide one, but TaskBoard can wrap itself in one if it wants isolated cache.
    const queryClient = useMemo(() => createPandataskQueryClient(), []);

    // Create API Client
    const suppliedApiClient = apiSettings?.apiClient;
    const apiRoot = apiSettings?.root;
    const apiNonce = apiSettings?.nonce;
    const localizedText = apiSettings?.text;
    const apiClient = useMemo(() => {
        // If apiSettings provides an axios instance (apiClient), use it.
        // This supports the 'Integrated Mode' where the host app passes its own client.
        if (suppliedApiClient) {
            return suppliedApiClient;
        }
        // Otherwise create one from root/nonce (Standalone Mode)
        return createApiClient({ root: apiRoot, nonce: apiNonce });
    }, [suppliedApiClient, apiNonce, apiRoot]);

    const config = useMemo(() => ({
        boardName,
        apiClient,
        currentUser,
        isStandalone,
        // Pass specific settings like text strings if they exist
        text: localizedText || {}
    }), [boardName, apiClient, currentUser, isStandalone, localizedText]);

    return (
        <ConfigProvider config={config}>
            <QueryClientProvider client={queryClient}>
                <AppErrorBoundary key={boardName}>
                    <Layout />
                </AppErrorBoundary>
            </QueryClientProvider>
        </ConfigProvider>
    );
};

export default TaskBoard;
