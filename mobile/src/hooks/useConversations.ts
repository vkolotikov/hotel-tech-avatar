import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createConversation,
  deleteConversation,
  listConversations,
  updateConversation,
} from '../api/conversations';

export const conversationsKey = ['conversations'] as const;

export function useConversations() {
  return useQuery({
    queryKey: conversationsKey,
    queryFn: listConversations,
  });
}

export function useCreateConversation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ agentId, title }: { agentId: number; title: string | null }) =>
      createConversation(agentId, title),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: conversationsKey });
    },
  });
}

export function useDeleteConversation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteConversation(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: conversationsKey });
    },
  });
}

export function useRenameConversation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, title }: { id: number; title: string }) =>
      updateConversation(id, title),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: conversationsKey });
    },
  });
}
