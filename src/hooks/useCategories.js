import { useQuery } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useCategories = (overrideBoardName) => {
    const { apiClient, boardName: contextBoardName } = useConfig();
    const activeBoard = overrideBoardName || contextBoardName;

    return useQuery({
        queryKey: ['categories', activeBoard],
        queryFn: async () => {
            if (!activeBoard) return [];
            const response = await apiClient.get(`boards/${activeBoard}/categories`);
            return response.categories;
        },
        staleTime: 60000, // 1 minute
        enabled: !!activeBoard,
    });
};
