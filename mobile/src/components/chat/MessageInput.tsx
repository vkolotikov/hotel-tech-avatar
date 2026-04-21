import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  onSend: (text: string) => void;
  disabled: boolean;
};

export function MessageInput({ onSend, disabled }: Props) {
  const [text, setText] = useState('');

  const handleSend = () => {
    const trimmed = text.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setText('');
  };

  return (
    <View style={styles.container}>
      <TextInput
        style={styles.input}
        value={text}
        onChangeText={setText}
        placeholder="Message…"
        placeholderTextColor={colors.textMuted}
        multiline
        editable={!disabled}
      />
      <Pressable
        testID="send-button"
        style={[styles.sendButton, (disabled || !text.trim()) && styles.sendDisabled]}
        onPress={handleSend}
        disabled={disabled || !text.trim()}
        accessibilityLabel="Send message"
      >
        <Text style={styles.sendIcon}>➤</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    padding: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.surface,
  },
  input: {
    flex: 1,
    backgroundColor: colors.surfaceElevated,
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: fontSize.md,
    maxHeight: 100,
  },
  sendButton: {
    marginLeft: spacing.sm,
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendDisabled: { opacity: 0.4 },
  sendIcon: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
});
