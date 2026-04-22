import { useEffect, useRef } from 'react';
import {
  ActivityIndicator,
  Animated,
  Easing,
  Pressable,
  StyleSheet,
  View,
} from 'react-native';
import { colors, radius } from '../../theme';

type Props = {
  isRecording: boolean;
  isTranscribing: boolean;
  accent?: string;
  onToggle: () => void;
};

export function VoiceRecordButton({
  isRecording,
  isTranscribing,
  accent = colors.primary,
  onToggle,
}: Props) {
  const pulse = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    if (isRecording) {
      pulse.setValue(0);
      const loop = Animated.loop(
        Animated.timing(pulse, {
          toValue: 1,
          duration: 1200,
          easing: Easing.out(Easing.ease),
          useNativeDriver: true,
        }),
      );
      loop.start();
      return () => loop.stop();
    }
    pulse.setValue(0);
  }, [isRecording, pulse]);

  const ringScale = pulse.interpolate({ inputRange: [0, 1], outputRange: [1, 1.9] });
  const ringOpacity = pulse.interpolate({ inputRange: [0, 1], outputRange: [0.55, 0] });

  const bgColor = isRecording ? accent : 'rgba(255,255,255,0.12)';
  const accessibilityLabel = isRecording
    ? 'Stop recording'
    : isTranscribing
    ? 'Transcribing'
    : 'Start voice message';

  return (
    <View style={styles.wrap}>
      {isRecording && (
        <Animated.View
          pointerEvents="none"
          style={[
            styles.ring,
            {
              backgroundColor: accent,
              opacity: ringOpacity,
              transform: [{ scale: ringScale }],
            },
          ]}
        />
      )}
      <Pressable
        testID="voice-record-button"
        onPress={onToggle}
        disabled={isTranscribing}
        hitSlop={8}
        style={({ pressed }) => [
          styles.button,
          { backgroundColor: bgColor, borderColor: isRecording ? accent : 'rgba(255,255,255,0.18)' },
          pressed && styles.pressed,
          isTranscribing && styles.disabled,
        ]}
        accessibilityLabel={accessibilityLabel}
        accessibilityRole="button"
        accessibilityState={{ busy: isTranscribing, selected: isRecording }}
      >
        {isTranscribing ? (
          <ActivityIndicator color={colors.textPrimary} />
        ) : isRecording ? (
          <View style={styles.stopSquare} />
        ) : (
          <MicGlyph />
        )}
      </Pressable>
    </View>
  );
}

function MicGlyph() {
  return (
    <View style={micStyles.root}>
      <View style={micStyles.capsule} />
      <View style={micStyles.arc} />
      <View style={micStyles.stem} />
      <View style={micStyles.base} />
    </View>
  );
}

const BUTTON_SIZE = 48;

const styles = StyleSheet.create({
  wrap: {
    width: BUTTON_SIZE,
    height: BUTTON_SIZE,
    alignItems: 'center',
    justifyContent: 'center',
  },
  ring: {
    position: 'absolute',
    width: BUTTON_SIZE,
    height: BUTTON_SIZE,
    borderRadius: radius.pill,
  },
  button: {
    width: BUTTON_SIZE,
    height: BUTTON_SIZE,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
  },
  pressed: { opacity: 0.85, transform: [{ scale: 0.96 }] },
  disabled: { opacity: 0.6 },
  stopSquare: {
    width: 14,
    height: 14,
    borderRadius: 3,
    backgroundColor: colors.textPrimary,
  },
});

const micStyles = StyleSheet.create({
  root: { width: 22, height: 24, alignItems: 'center' },
  capsule: {
    width: 10,
    height: 14,
    borderRadius: 5,
    backgroundColor: colors.textPrimary,
  },
  arc: {
    position: 'absolute',
    top: 9,
    width: 16,
    height: 10,
    borderColor: colors.textPrimary,
    borderBottomWidth: 1.5,
    borderLeftWidth: 1.5,
    borderRightWidth: 1.5,
    borderBottomLeftRadius: 8,
    borderBottomRightRadius: 8,
    backgroundColor: 'transparent',
  },
  stem: {
    position: 'absolute',
    bottom: 3,
    width: 1.5,
    height: 4,
    backgroundColor: colors.textPrimary,
  },
  base: {
    position: 'absolute',
    bottom: 0,
    width: 10,
    height: 1.5,
    backgroundColor: colors.textPrimary,
  },
});
