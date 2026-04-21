import { Pressable, StyleSheet, Text } from 'react-native';
import { colors, radius, fontSize } from '../../theme';

type Props = {
  isRecording: boolean;
  isTranscribing: boolean;
  onPressIn: () => void;
  onPressOut: () => void;
};

export function VoiceRecordButton({ isRecording, isTranscribing, onPressIn, onPressOut }: Props) {
  const label = isTranscribing ? '…' : isRecording ? '●' : '🎤';
  return (
    <Pressable
      testID="voice-record-button"
      onPressIn={onPressIn}
      onPressOut={onPressOut}
      disabled={isTranscribing}
      style={[
        styles.button,
        isRecording && styles.recording,
        isTranscribing && styles.disabled,
      ]}
      accessibilityLabel="Record voice message"
      accessibilityHint="Press and hold to record, release to transcribe"
    >
      <Text style={styles.icon}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    backgroundColor: colors.surfaceElevated,
    alignItems: 'center',
    justifyContent: 'center',
  },
  recording: { backgroundColor: colors.danger },
  disabled: { opacity: 0.5 },
  icon: { color: colors.textPrimary, fontSize: fontSize.md },
});
