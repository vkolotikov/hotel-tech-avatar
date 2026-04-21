import { render, fireEvent } from '@testing-library/react-native';
import { ConversationCard } from '../ConversationCard';

describe('ConversationCard', () => {
  const conversation = {
    id: 1,
    agent_id: 5,
    title: 'Breakfast planning',
    created_at: '2026-04-21T10:00:00Z',
    updated_at: '2026-04-21T10:30:00Z',
    agent: {
      id: 5,
      slug: 'nora',
      name: 'Nora',
      role: 'Nutritionist',
      domain: 'nutrition',
      description: null,
      vertical_slug: 'wellness',
      avatar_image_url: null,
    },
  };

  test('renders title and avatar name', () => {
    const { getByText } = render(
      <ConversationCard conversation={conversation} onPress={() => {}} />,
    );
    expect(getByText('Breakfast planning')).toBeTruthy();
    expect(getByText('Nora')).toBeTruthy();
  });

  test('calls onPress with conversation when tapped', () => {
    const onPress = jest.fn();
    const { getByTestId } = render(
      <ConversationCard conversation={conversation} onPress={onPress} />,
    );
    fireEvent.press(getByTestId('conversation-card'));
    expect(onPress).toHaveBeenCalledWith(conversation);
  });
});
