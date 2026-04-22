import { request } from './index';
import type { Conversation, Paginated } from '../types/models';

export async function listConversations(): Promise<Paginated<Conversation>> {
  return request<Paginated<Conversation>>('/api/v1/conversations', { auth: true });
}

export async function createConversation(
  agentId: number,
  title: string | null,
): Promise<Conversation> {
  return request<Conversation>('/api/v1/conversations', {
    method: 'POST',
    auth: true,
    body: JSON.stringify({ agent_id: agentId, title }),
  });
}

export async function getConversation(id: number): Promise<Conversation> {
  return request<Conversation>(`/api/v1/conversations/${id}`, { auth: true });
}

export async function deleteConversation(id: number): Promise<void> {
  await request<{ message: string }>(`/api/v1/conversations/${id}`, {
    method: 'DELETE',
    auth: true,
  });
}
