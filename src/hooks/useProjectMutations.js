import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useProjectMutations = () => {
    const { apiClient, boardName } = useConfig();
    const queryClient = useQueryClient();

    const createProject = useMutation({
        mutationFn: async (data) => {
            // Ensure board_name is present
            const payload = { ...data, board_name: data.board_name || boardName };
            const response = await apiClient.post(`boards/${boardName}/projects`, payload);
            return response.project;
        },
        onSuccess: () => {
            queryClient.invalidateQueries(['projects', boardName]);
        },
    });

    const updateProject = useMutation({
        mutationFn: async ({ id, data }) => {
            const response = await apiClient.post(`projects/${id}`, data);
            return response.project;
        },
        onSuccess: () => {
            queryClient.invalidateQueries(['projects', boardName]);
        },
    });

    const deleteProject = useMutation({
        mutationFn: async (id) => {
            await apiClient.delete(`projects/${id}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries(['projects', boardName]);
        },
    });

    return { createProject, updateProject, deleteProject };
};
