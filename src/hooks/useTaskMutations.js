import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useTaskMutations = () => {
    const { apiClient, boardName } = useConfig();
    const queryClient = useQueryClient();

    const createTask = useMutation({
        mutationFn: async (data) => {
            // Ensure board_name is present
            const payload = { ...data, board_name: data.board_name || boardName };
            const response = await apiClient.post(`boards/${boardName}/tasks`, payload);
            return response.task;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['tasks', boardName] });
        },
    });

    const updateTask = useMutation({
        mutationFn: async ({ id, data }) => {
            const response = await apiClient.post(`tasks/${id}`, data);
            return response.task;
        },
        onMutate: async ({ id, data }) => {
            await queryClient.cancelQueries({ queryKey: ['tasks', boardName] });
            const previousTasks = queryClient.getQueriesData({ queryKey: ['tasks', boardName] });

            queryClient.setQueriesData({ queryKey: ['tasks', boardName] }, (old) => {
                if (!Array.isArray(old)) return old;
                return old.map((task) => (task.id === id ? { ...task, ...data } : task));
            });

            return { previousTasks };
        },
        onError: (err, variables, context) => {
            if (context?.previousTasks) {
                context.previousTasks.forEach(([queryKey, data]) => {
                    queryClient.setQueryData(queryKey, data);
                });
            }
        },
        onSettled: () => {
            queryClient.invalidateQueries({ queryKey: ['tasks', boardName] });
        },
    });

    const deleteTask = useMutation({
        mutationFn: async ({ id, scope }) => {
            const config = scope ? { params: { delete_scope: scope } } : {};
            await apiClient.delete(`tasks/${id}`, config);
        },
        onMutate: async ({ id, scope }) => {
            // Only optimistically delete if it's a full delete or delete all instances
            if (scope && scope !== 'all') return { previousTasks: null };

            await queryClient.cancelQueries({ queryKey: ['tasks', boardName] });
            const previousTasks = queryClient.getQueriesData({ queryKey: ['tasks', boardName] });

            queryClient.setQueriesData({ queryKey: ['tasks', boardName] }, (old) => {
                if (!Array.isArray(old)) return old;
                return old.filter((task) => task.id !== id);
            });

            return { previousTasks };
        },
        onError: (err, variables, context) => {
            if (context?.previousTasks) {
                context.previousTasks.forEach(([queryKey, data]) => {
                    queryClient.setQueryData(queryKey, data);
                });
            }
        },
        onSettled: () => {
            queryClient.invalidateQueries({ queryKey: ['tasks', boardName] });
        },
    });

    return { createTask, updateTask, deleteTask };
};
