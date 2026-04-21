import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createConversation, listConversations } from '../api/conversations';

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
