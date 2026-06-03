import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useCategoryMutations = () => {
    const { apiClient, boardName } = useConfig();
    const queryClient = useQueryClient();

    const createCategory = useMutation({
        mutationFn: async (name) => {
            const response = await apiClient.post(`boards/${boardName}/categories`, { name });
            return response.category;
        },
        onSuccess: () => {
            queryClient.invalidateQueries(['categories', boardName]);
        },
    });

    const deleteCategory = useMutation({
        mutationFn: async (id) => {
            await apiClient.delete(`categories/${id}`, { params: { board_name: boardName } });
        },
        onSuccess: () => {
            queryClient.invalidateQueries(['categories', boardName]);
        },
    });

    return { createCategory, deleteCategory };
};
