import { renderHook, waitFor } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useConversations } from '../useConversations';

jest.mock('../../api/conversations', () => ({
  listConversations: jest.fn(),
}));
import { listConversations } from '../../api/conversations';

const wrapper = ({ children }: { children: React.ReactNode }) => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
};

describe('useConversations', () => {
  test('returns conversations from API', async () => {
    (listConversations as jest.Mock).mockResolvedValue({
      data: [{ id: 1, agent_id: 5, title: 'Hello', created_at: '', updated_at: '' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    });

    const { result } = renderHook(() => useConversations(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.data).toHaveLength(1);
  });
});
