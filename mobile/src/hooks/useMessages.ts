import { useQuery } from '@tanstack/react-query';
import { listMessages } from '../api/messages';

export const messagesKey = (conversationId: number) =>
  ['conversations', conversationId, 'messages'] as const;

export function useMessages(conversationId: number) {
  return useQuery({
    queryKey: messagesKey(conversationId),
    queryFn: () => listMessages(conversationId),
  });
}
