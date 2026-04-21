import { render, fireEvent } from '@testing-library/react-native';
import { MessageInput } from '../MessageInput';

describe('MessageInput', () => {
  test('calls onSend with trimmed text', () => {
    const onSend = jest.fn();
    const { getByPlaceholderText, getByTestId } = render(
      <MessageInput onSend={onSend} disabled={false} />,
    );
    fireEvent.changeText(getByPlaceholderText(/message/i), '  Hello  ');
    fireEvent.press(getByTestId('send-button'));
    expect(onSend).toHaveBeenCalledWith('Hello');
  });

  test('does not send empty text', () => {
    const onSend = jest.fn();
    const { getByTestId } = render(<MessageInput onSend={onSend} disabled={false} />);
    fireEvent.press(getByTestId('send-button'));
    expect(onSend).not.toHaveBeenCalled();
  });

  test('disables send when disabled prop is true', () => {
    const onSend = jest.fn();
    const { getByTestId, getByPlaceholderText } = render(
      <MessageInput onSend={onSend} disabled={true} />,
    );
    fireEvent.changeText(getByPlaceholderText(/message/i), 'Hi');
    fireEvent.press(getByTestId('send-button'));
    expect(onSend).not.toHaveBeenCalled();
  });
});
