import { render } from '@testing-library/react-native';
import { MessageBubble } from '../MessageBubble';

const baseMessage = {
  id: 1,
  conversation_id: 1,
  role: 'agent' as const,
  content: 'Hello!',
  ai_provider: null,
  ai_model: null,
  prompt_tokens: null,
  completion_tokens: null,
  total_tokens: null,
  ai_latency_ms: null,
  trace_id: null,
  is_verified: null,
  verification_status: null,
  verification_failures_json: null,
  verification_latency_ms: null,
  created_at: '',
};

describe('MessageBubble', () => {
  test('renders agent message content', () => {
    const { getByText } = render(
      <MessageBubble message={baseMessage} avatarSlug="nora" />,
    );
    expect(getByText('Hello!')).toBeTruthy();
  });

  test('shows citation badge when citations_count > 0', () => {
    const { getByText } = render(
      <MessageBubble
        message={{ ...baseMessage, is_verified: true, citations_count: 3 }}
        avatarSlug="nora"
      />,
    );
    expect(getByText(/3 sources/)).toBeTruthy();
    expect(getByText(/Verified/)).toBeTruthy();
  });

  test('shows fallback badge when is_verified is false', () => {
    const { getByText } = render(
      <MessageBubble
        message={{ ...baseMessage, is_verified: false, citations_count: 0 }}
        avatarSlug="nora"
      />,
    );
    expect(getByText(/Fallback/)).toBeTruthy();
  });
});
