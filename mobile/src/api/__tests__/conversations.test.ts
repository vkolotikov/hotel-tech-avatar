import { listConversations, createConversation } from '../conversations';

jest.mock('../index', () => ({
  request: jest.fn(),
}));
import { request } from '../index';

describe('conversations api', () => {
  beforeEach(() => jest.clearAllMocks());

  test('listConversations calls GET /api/v1/conversations', async () => {
    (request as jest.Mock).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });

    await listConversations();

    expect(request).toHaveBeenCalledWith('/api/v1/conversations', { auth: true });
  });

  test('createConversation posts agent_id and title', async () => {
    (request as jest.Mock).mockResolvedValue({ id: 42 });

    await createConversation(7, 'Nora chat');

    expect(request).toHaveBeenCalledWith('/api/v1/conversations', {
      method: 'POST',
      auth: true,
      body: JSON.stringify({ agent_id: 7, title: 'Nora chat' }),
    });
  });
});
