import { request } from './index';
import type { Message, SendMessageResponse } from '../types/models';

export async function listMessages(conversationId: number): Promise<Message[]> {
  return request<Message[]>(
    `/api/v1/conversations/${conversationId}/messages`,
    { auth: true },
  );
}

export async function sendMessage(
  conversationId: number,
  content: string,
): Promise<SendMessageResponse> {
  return request<SendMessageResponse>(
    `/api/v1/conversations/${conversationId}/messages`,
    {
      method: 'POST',
      auth: true,
      body: JSON.stringify({ content, auto_reply: true }),
    },
  );
}
