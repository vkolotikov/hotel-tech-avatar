import { apiFetch, apiFetchBlob } from './client';

export type Agent = {
  id: number;
  slug: string;
  name: string;
  role: string;
  description: string;
  avatar_image_url: string | null;
  chat_background_url: string | null;
  created_at: string;
  updated_at: string;
};

export type AgentAttachment = {
  name: string;
  path: string;
};

export type AgentAttachmentsResponse = {
  agent_id: number;
  attachments: AgentAttachment[];
};

export type Conversation = {
  id: number;
  agent_id: number;
  title: string | null;
  created_at: string;
  updated_at: string;
};

export type ConversationAttachment = {
  id: number;
  conversation_id: number;
  file_path: string;
  file_name: string;
  mime_type: string | null;
  size_bytes: number | null;
  created_at: string;
};

export type Message = {
  id: number;
  conversation_id: number;
  role: 'user' | 'agent';
  content: string;
  ui?: {
    quick_replies?: string[];
    follow_up_question?: string | null;
    sources?: Array<{ label: string; path?: string }>;
  } | null;
  retrieval_used?: 0 | 1 | null;
  retrieval_source_count?: number | null;
  created_at: string;
};

export type AgentReplyResult = {
  status: 'created' | 'skipped';
  reason?: string;
  message?: Message;
};

export function getAgents() {
  return apiFetch<Agent[]>('/api/v1/agents');
}

export function getAgent(id: number | string, signal?: AbortSignal) {
  return apiFetch<Agent>(`/api/v1/agents/${id}`, { signal });
}

export function getAgentAttachments(id: number | string, signal?: AbortSignal) {
  return apiFetch<AgentAttachmentsResponse>(`/api/v1/agents/${id}/attachments`, { signal });
}

export function getConversationForAgent(agentId: number | string, signal?: AbortSignal) {
  return apiFetch<Conversation>(`/api/v1/agents/${agentId}/conversation`, { signal });
}

export function getConversationsForAgent(agentId: number | string, signal?: AbortSignal) {
  return apiFetch<Conversation[]>(`/api/v1/agents/${agentId}/conversations`, { signal });
}

export function createConversationForAgent(agentId: number | string, signal?: AbortSignal) {
  return apiFetch<Conversation>(`/api/v1/agents/${agentId}/conversations`, {
    method: 'POST',
    signal,
  });
}

export function deleteConversation(conversationId: number | string, signal?: AbortSignal) {
  return apiFetch<{ ok: boolean }>(`/api/v1/conversations/${conversationId}`, {
    method: 'DELETE',
    signal,
  });
}

export function renameConversation(
  conversationId: number | string,
  title: string,
  signal?: AbortSignal
) {
  return apiFetch<Conversation>(`/api/v1/conversations/${conversationId}`, {
    method: 'PUT',
    body: JSON.stringify({ title }),
    signal,
  });
}

export function getMessages(conversationId: number | string, signal?: AbortSignal) {
  return apiFetch<Message[]>(`/api/v1/conversations/${conversationId}/messages`, { signal });
}

export function createMessage(
  conversationId: number | string,
  role: 'user' | 'agent',
  content: string,
  autoReply = true,
  signal?: AbortSignal
) {
  return apiFetch<Message>(`/api/v1/conversations/${conversationId}/messages`, {
    method: 'POST',
    body: JSON.stringify({ role, content, auto_reply: autoReply }),
    signal,
  });
}

export function createAgentReply(conversationId: number | string, signal?: AbortSignal) {
  return apiFetch<AgentReplyResult>(`/api/v1/conversations/${conversationId}/agent-reply`, {
    method: 'POST',
    signal,
  });
}

export function getConversationAttachments(conversationId: number | string, signal?: AbortSignal) {
  return apiFetch<ConversationAttachment[]>(`/api/v1/conversations/${conversationId}/attachments`, { signal });
}

export function uploadConversationAttachments(
  conversationId: number | string,
  files: File[],
  signal?: AbortSignal
) {
  const form = new FormData();

  files.forEach((file) => {
    form.append('files[]', file);
  });

  return apiFetch<ConversationAttachment[]>(`/api/v1/conversations/${conversationId}/attachments`, {
    method: 'POST',
    body: form,
    signal,
  });
}

export function transcribeConversationAudio(
  conversationId: number | string,
  file: File,
  signal?: AbortSignal
) {
  const form = new FormData();
  form.append('file', file);

  return apiFetch<{ text: string; model?: string }>(`/api/v1/conversations/${conversationId}/voice/transcribe`, {
    method: 'POST',
    body: form,
    signal,
  });
}

export function speakConversationMessage(
  conversationId: number | string,
  payload: { message_id?: number; text?: string },
  signal?: AbortSignal
) {
  return apiFetchBlob(`/api/v1/conversations/${conversationId}/voice/speak`, {
    method: 'POST',
    body: JSON.stringify(payload),
    signal,
  });
}

export function health() {
  return apiFetch<{ ok: boolean; service: string; time: string }>('/api/v1/health');
}

export type HeygenToken = {
  token: string;
  config: {
    avatar_name: string | null;
    voice_id: string | null;
    quality: string | null;
  };
};

export function createHeygenToken(signal?: AbortSignal) {
  return apiFetch<HeygenToken>('/api/v1/heygen/token', {
    method: 'POST',
    signal,
  });
}
