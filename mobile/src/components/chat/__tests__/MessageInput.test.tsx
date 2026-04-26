import type { ReactElement } from 'react';
import { render, fireEvent } from '@testing-library/react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { MessageInput } from '../MessageInput';

const baseProps = {
  conversationId: 1,
  onOpenVoiceMode: jest.fn(),
};

// MessageInput calls useSafeAreaInsets() for keyboard padding; tests need
// a provider with explicit metrics so that hook returns synchronously.
const wrap = (ui: ReactElement) => (
  <SafeAreaProvider
    initialMetrics={{
      insets: { top: 0, right: 0, bottom: 0, left: 0 },
      frame: { x: 0, y: 0, width: 320, height: 640 },
    }}
  >
    {ui}
  </SafeAreaProvider>
);

describe('MessageInput', () => {
  test('calls onSend with trimmed text', () => {
    const onSend = jest.fn();
    const { getByPlaceholderText, getByTestId } = render(
      wrap(<MessageInput {...baseProps} onSend={onSend} disabled={false} />),
    );
    fireEvent.changeText(getByPlaceholderText(/message/i), '  Hello  ');
    fireEvent.press(getByTestId('send-button'));
    expect(onSend).toHaveBeenCalledWith('Hello', undefined);
  });

  test('does not send empty text', () => {
    const onSend = jest.fn();
    // With no text + no attachments, the rightmost slot becomes the
    // voice-mode button instead of send, so send-button is absent.
    const { queryByTestId } = render(
      wrap(<MessageInput {...baseProps} onSend={onSend} disabled={false} />),
    );
    expect(queryByTestId('send-button')).toBeNull();
    expect(onSend).not.toHaveBeenCalled();
  });

  test('disables send when disabled prop is true', () => {
    const onSend = jest.fn();
    const { queryByTestId, getByPlaceholderText } = render(
      wrap(<MessageInput {...baseProps} onSend={onSend} disabled={true} />),
    );
    fireEvent.changeText(getByPlaceholderText(/message/i), 'Hi');
    // disabled=true means canSend is false so the slot stays as the
    // voice-mode button (no send-button rendered).
    expect(queryByTestId('send-button')).toBeNull();
    expect(onSend).not.toHaveBeenCalled();
  });
});
