import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useVoiceRecorder } from '../../hooks/useVoiceRecorder';
import { VoiceRecordButton } from './VoiceRecordButton';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  conversationId: number;
  onSend: (text: string) => void;
  disabled: boolean;
};

export function MessageInput({ conversationId, onSend, disabled }: Props) {
  const [text, setText] = useState('');
  const insets = useSafeAreaInsets();
  const recorder = useVoiceRecorder(conversationId, (transcript) => setText(transcript));

  const handleSend = () => {
    const trimmed = text.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setText('');
  };

  return (
    <View style={[styles.container, { paddingBottom: spacing.sm + insets.bottom }]}>
      <VoiceRecordButton
        isRecording={recorder.isRecording}
        isTranscribing={recorder.isTranscribing}
        onPressIn={recorder.start}
        onPressOut={recorder.stop}
      />
      <TextInput
        style={styles.input}
        value={text}
        onChangeText={setText}
        placeholder="Message…"
        placeholderTextColor={colors.textMuted}
        multiline
        editable={!disabled && !recorder.isTranscribing}
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
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: 'rgba(255,255,255,0.08)',
    backgroundColor: 'rgba(11,15,23,0.92)',
  },
  input: {
    flex: 1,
    marginHorizontal: spacing.sm,
    backgroundColor: 'rgba(31,41,55,0.7)',
    color: colors.textPrimary,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 2,
    fontSize: fontSize.md,
    maxHeight: 100,
  },
  sendButton: {
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
