import { useCallback, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import EventSource from 'react-native-sse';
import * as SecureStore from 'expo-secure-store';
import { sendMessage } from '../api/messages';
import { messagesKey } from './useMessages';
import type { Message, StreamEvent } from '../types/models';

const TOKEN_KEY = 'sanctum_token';

function baseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set');
  return url.replace(/\/$/, '');
}

type State = {
  isPending: boolean;
  streamingText: string;
  error: Error | null;
};

export function useChatStream(conversationId: number) {
  const qc = useQueryClient();
  const [state, setState] = useState<State>({
    isPending: false,
    streamingText: '',
    error: null,
  });
  const esRef = useRef<EventSource | null>(null);

  const appendMessages = useCallback(
    (newMessages: Message[]) => {
      qc.setQueryData<Message[] | undefined>(messagesKey(conversationId), (prev) => [
        ...(prev ?? []),
        ...newMessages,
      ]);
    },
    [qc, conversationId],
  );

  const openStream = useCallback(
    async (userMessage: Message, placeholderId: number) => {
      const token = await SecureStore.getItemAsync(TOKEN_KEY);
      const url = `${baseUrl()}/api/v1/conversations/${conversationId}/stream?message_id=${placeholderId}`;
      const es = new EventSource(url, {
        headers: { Authorization: `Bearer ${token ?? ''}` },
      });
      esRef.current = es;

      let buffer = '';
      let finalMessage: Message | null = null;

      es.addEventListener('message', (evt: any) => {
        try {
          const event: StreamEvent = JSON.parse(evt.data);
          if (event.type === 'token') {
            buffer += event.content;
            setState((s) => ({ ...s, streamingText: buffer }));
          } else if (event.type === 'done') {
            finalMessage = {
              id: event.message_id,
              conversation_id: conversationId,
              role: 'agent',
              content: buffer,
              ai_provider: null,
              ai_model: null,
              prompt_tokens: null,
              completion_tokens: null,
              total_tokens: null,
              ai_latency_ms: null,
              trace_id: null,
              is_verified: event.is_verified,
              verification_status: null,
              verification_failures_json: null,
              verification_latency_ms: event.verification_latency_ms,
              citations_count: event.citations_count,
              created_at: new Date().toISOString(),
            };
            es.close();
            esRef.current = null;
            appendMessages([userMessage, finalMessage]);
            setState({ isPending: false, streamingText: '', error: null });
          } else if (event.type === 'error') {
            es.close();
            esRef.current = null;
            setState({ isPending: false, streamingText: '', error: new Error(event.message) });
          }
        } catch (err) {
          es.close();
          esRef.current = null;
          setState({ isPending: false, streamingText: '', error: err as Error });
        }
      });

      es.addEventListener('error', () => {
        es.close();
        esRef.current = null;
        setState((s) => ({ ...s, isPending: false, error: new Error('Stream failed') }));
      });
    },
    [conversationId, appendMessages],
  );

  const sendSync = useCallback(
    async (text: string) => {
      const response = await sendMessage(conversationId, text);
      const toAppend: Message[] = [response.user_message];
      if (response.agent_message) toAppend.push(response.agent_message);
      appendMessages(toAppend);
      setState({ isPending: false, streamingText: '', error: null });
    },
    [conversationId, appendMessages],
  );

  const send = useCallback(
    async (text: string) => {
      setState({ isPending: true, streamingText: '', error: null });
      try {
        const response = await sendMessage(conversationId, text);
        if (response.agent_message) {
          appendMessages([response.user_message, response.agent_message]);
          setState({ isPending: false, streamingText: '', error: null });
          return;
        }
        await openStream(response.user_message, 0);
      } catch (error) {
        try {
          await sendSync(text);
        } catch (e) {
          setState({ isPending: false, streamingText: '', error: e as Error });
        }
      }
    },
    [conversationId, openStream, sendSync, appendMessages],
  );

  const cancel = useCallback(() => {
    esRef.current?.close();
    esRef.current = null;
    setState({ isPending: false, streamingText: '', error: null });
  }, []);

  return { ...state, send, cancel };
}
