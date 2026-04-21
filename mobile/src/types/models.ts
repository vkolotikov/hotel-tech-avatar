export interface Avatar {
  id: number;
  slug: string;
  name: string;
  role: string;
  domain: string;
  description: string | null;
  vertical_slug: string;
  avatar_image_url: string | null;
}

export interface Conversation {
  id: number;
  agent_id: number;
  title: string | null;
  created_at: string;
  updated_at: string;
  agent?: Avatar;
  last_message?: Message;
}

export type MessageRole = 'user' | 'agent';

export interface Message {
  id: number;
  conversation_id: number;
  role: MessageRole;
  content: string;
  ai_provider: string | null;
  ai_model: string | null;
  prompt_tokens: number | null;
  completion_tokens: number | null;
  total_tokens: number | null;
  ai_latency_ms: number | null;
  trace_id: string | null;
  is_verified: boolean | null;
  verification_status: 'passed' | 'failed' | 'not_required' | null;
  verification_failures_json: unknown[] | null;
  verification_latency_ms: number | null;
  citations_count?: number;
  created_at: string;
}

export interface SendMessageResponse {
  user_message: Message;
  agent_message: Message | null;
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export type StreamEvent =
  | { type: 'token'; content: string }
  | {
      type: 'done';
      message_id: number;
      is_verified: boolean | null;
      citations_count: number;
      verification_latency_ms: number | null;
    }
  | { type: 'error'; message: string };
