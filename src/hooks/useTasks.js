import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useTasks = (filters = {}, overrideBoardName) => {
    const { apiClient, boardName: contextBoardName } = useConfig();
    const activeBoard = overrideBoardName || contextBoardName;

    return useQuery({
        queryKey: ['tasks', activeBoard, filters],
        queryFn: async () => {
            if (!activeBoard) return [];
            const params = new URLSearchParams();
            
            // Map filters to API params
            if (filters.search) params.append('search', filters.search);
            if (filters.sort) params.append('sort', filters.sort);
            if (filters.status !== undefined) params.append('status_filter', filters.status);
            if (filters.project && filters.project !== 'all') params.append('project_filter', filters.project);
            if (filters.onlyMyTasks) params.append('assigned_to_me', 'true');
            if (filters.archived) params.append('archived', '1');
            if (filters.task_type_filter) params.append('task_type_filter', filters.task_type_filter);
            
            params.append('include_templates', 'true');

            // API fetch
            const response = await apiClient.get(`boards/${activeBoard}/tasks`, { params });
            return response.tasks;
        },
        keepPreviousData: true,
        staleTime: 30000, // 30 seconds
        enabled: !!activeBoard,
    });
};
