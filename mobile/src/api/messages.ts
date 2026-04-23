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
  attachmentIds?: number[],
): Promise<SendMessageResponse> {
  const payload: Record<string, unknown> = { content, auto_reply: true };
  if (attachmentIds && attachmentIds.length > 0) {
    payload.attachment_ids = attachmentIds;
  }
  return request<SendMessageResponse>(
    `/api/v1/conversations/${conversationId}/messages`,
    {
      method: 'POST',
      auth: true,
      body: JSON.stringify(payload),
    },
  );
}
