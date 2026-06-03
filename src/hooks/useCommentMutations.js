import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useConfig } from '../context/ConfigContext';

export const useCommentMutations = (taskId) => {
    const { apiClient } = useConfig();
    const queryClient = useQueryClient();

    const addComment = useMutation({
        mutationFn: async (commentText) => {
            const response = await apiClient.post(`tasks/${taskId}/comments`, { comment_text: commentText });
            return response.comment;
        },
        onSuccess: () => {
            queryClient.invalidateQueries(['task', taskId]);
        },
    });

    const deleteComment = useMutation({
        mutationFn: async (commentId) => {
            await apiClient.delete(`comments/${commentId}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries(['task', taskId]);
        },
    });

    const updateComment = useMutation({
        mutationFn: async ({ commentId, commentText }) => {
            const response = await apiClient.put(`comments/${commentId}`, { comment_text: commentText });
            return response.comment;
        },
        onSuccess: () => {
            queryClient.invalidateQueries(['task', taskId]);
        },
    });

    return { addComment, deleteComment, updateComment };
};
