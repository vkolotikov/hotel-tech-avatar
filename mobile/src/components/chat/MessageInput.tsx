import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
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
  // Dictate mode (default): transcript fills the input, user reviews + sends.
  // Voice mode: transcript auto-sends and the agent reply plays through TTS.
  const [voiceMode, setVoiceMode] = useState(false);
  const insets = useSafeAreaInsets();
  const recorder = useVoiceRecorder(conversationId, (transcript) => {
    const trimmed = transcript.trim();
    if (!trimmed) return;
    if (voiceMode) {
      onSend(trimmed, { voice: true });
    } else {
      // Dictate — append to whatever the user had typed.
      setText((prev) => (prev ? prev + ' ' + trimmed : trimmed));
    }
  });

  const handleSend = () => {
    const trimmed = text.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setText('');
  };

  const voiceActive = recorder.isRecording || recorder.isTranscribing;
  const hint = recorder.isRecording
    ? voiceMode
      ? 'Voice mode · tap mic to send'
      : 'Listening… tap mic to finish'
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

      <View style={styles.modeRow} pointerEvents={voiceActive ? 'none' : 'auto'}>
        <Pressable
          onPress={() => setVoiceMode((v) => !v)}
          style={[
            styles.modeChip,
            voiceMode && { borderColor: accent, backgroundColor: accent + '22' },
          ]}
          accessibilityRole="switch"
          accessibilityState={{ checked: voiceMode }}
          accessibilityLabel="Toggle voice mode"
        >
          <View
            style={[
              styles.modeDot,
              { backgroundColor: voiceMode ? accent : 'rgba(255,255,255,0.3)' },
            ]}
          />
          <Text style={[styles.modeChipText, voiceMode && { color: colors.textPrimary }]}>
            {voiceMode ? 'Voice mode on' : 'Dictate to text'}
          </Text>
        </Pressable>
      </View>

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
            recorder.isTranscribing && { opacity: 0.5 },
          ]}
          value={text}
          onChangeText={setText}
          placeholder={
            recorder.isTranscribing
              ? ''
              : voiceMode
              ? 'Voice mode — tap mic to speak'
              : 'Message… or tap mic to dictate'
          }
          placeholderTextColor={colors.textMuted}
          multiline
          // In voice mode you can still type, but while transcribing we lock.
          editable={!disabled && !recorder.isTranscribing}
        />
        <Pressable
          testID="send-button"
          style={[
            styles.sendButton,
            { backgroundColor: accent },
            (disabled || !text.trim() || recorder.isTranscribing) && styles.sendDisabled,
          ]}
          onPress={handleSend}
          disabled={disabled || !text.trim() || recorder.isTranscribing}
          accessibilityLabel="Send message"
        >
          <Ionicons name="send" size={18} color={colors.textPrimary} />
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
  modeRow: {
    flexDirection: 'row',
    justifyContent: 'center',
    paddingTop: spacing.xs,
  },
  modeChip: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 4,
    paddingHorizontal: 10,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.12)',
    backgroundColor: 'rgba(20,26,38,0.6)',
  },
  modeDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    marginRight: 6,
  },
  modeChipText: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: '600',
    letterSpacing: 0.3,
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
});
