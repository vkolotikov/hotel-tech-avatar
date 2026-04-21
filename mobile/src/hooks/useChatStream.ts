import { useMutation, useQueryClient } from '@tanstack/react-query';
import { sendMessage } from '../api/messages';
import { messagesKey } from './useMessages';
import type { Message, SendMessageResponse } from '../types/models';

type SendArgs = { conversationId: number; text: string };

export function useChatStream() {
  const qc = useQueryClient();

  return useMutation<SendMessageResponse, Error, SendArgs>({
    mutationFn: ({ conversationId, text }) => sendMessage(conversationId, text),
    onSuccess: (response, { conversationId }) => {
      qc.setQueryData<{ data: Message[] } | undefined>(messagesKey(conversationId), (prev) => {
        const prevData = prev?.data ?? [];
        return {
          data: [...prevData, response.user_message, response.agent_message],
        };
      });
    },
  });
}
