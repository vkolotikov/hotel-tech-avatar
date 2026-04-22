import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useVoiceRecorder } from '../../hooks/useVoiceRecorder';
import { VoiceRecordButton } from './VoiceRecordButton';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  conversationId: number;
  accent?: string;
  onSend: (text: string, opts?: { voice?: boolean }) => void;
  disabled: boolean;
};

export function MessageInput({
  conversationId,
  accent = colors.primary,
  onSend,
  disabled,
}: Props) {
  const [text, setText] = useState('');
  const insets = useSafeAreaInsets();
  const recorder = useVoiceRecorder(conversationId, (transcript) => {
    const trimmed = transcript.trim();
    if (!trimmed) return;
    onSend(trimmed, { voice: true });
  });

  const handleSend = () => {
    const trimmed = text.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setText('');
  };

  const voiceActive = recorder.isRecording || recorder.isTranscribing;
  const hint = recorder.isRecording
    ? 'Listening… tap mic to finish'
    : recorder.isTranscribing
    ? 'Transcribing…'
    : null;

  return (
    <View style={styles.wrapper}>
      {hint && (
        <View style={[styles.hintPill, { borderColor: accent }]}>
          {recorder.isRecording && (
            <View style={[styles.hintDot, { backgroundColor: accent }]} />
          )}
          <Text style={styles.hintText}>{hint}</Text>
        </View>
      )}
      <View style={[styles.container, { paddingBottom: spacing.sm + insets.bottom }]}>
        <VoiceRecordButton
          isRecording={recorder.isRecording}
          isTranscribing={recorder.isTranscribing}
          accent={accent}
          onToggle={recorder.toggle}
        />
        <TextInput
          style={[
            styles.input,
            voiceActive && { opacity: 0.5 },
          ]}
          value={text}
          onChangeText={setText}
          placeholder={voiceActive ? '' : 'Message…'}
          placeholderTextColor={colors.textMuted}
          multiline
          editable={!disabled && !voiceActive}
        />
        <Pressable
          testID="send-button"
          style={[
            styles.sendButton,
            { backgroundColor: accent },
            (disabled || !text.trim() || voiceActive) && styles.sendDisabled,
          ]}
          onPress={handleSend}
          disabled={disabled || !text.trim() || voiceActive}
          accessibilityLabel="Send message"
        >
          <Text style={styles.sendIcon}>➤</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: {
    backgroundColor: 'rgba(11,15,23,0.92)',
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: 'rgba(255,255,255,0.08)',
  },
  hintPill: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'center',
    marginTop: spacing.sm,
    marginBottom: 2,
    paddingVertical: spacing.xs + 2,
    paddingHorizontal: spacing.md,
    borderRadius: 999,
    borderWidth: 1,
    backgroundColor: 'rgba(20,26,38,0.9)',
  },
  hintDot: {
    width: 7,
    height: 7,
    borderRadius: 4,
    marginRight: spacing.xs + 2,
  },
  hintText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm - 1,
    fontWeight: '500',
    letterSpacing: 0.2,
  },
  container: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    padding: spacing.sm,
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
    minHeight: 44,
  },
  sendButton: {
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendDisabled: { opacity: 0.35 },
  sendIcon: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
});
