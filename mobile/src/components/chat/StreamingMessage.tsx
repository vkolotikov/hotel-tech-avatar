import { Text, View, StyleSheet } from 'react-native';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  text: string;
  avatarSlug: string;
};

export function StreamingMessage({ text, avatarSlug }: Props) {
  const slug = avatarSlug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <View style={styles.row}>
      <View style={[styles.bubble, { borderLeftColor: accent }]}>
        <Text style={styles.text}>{text || '…'}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  // Stretch so the streaming bubble matches the final agent bubble's
  // width when the stream completes — otherwise long replies "snap"
  // wider on completion, which is jarring.
  row: { alignSelf: 'stretch', marginBottom: spacing.md },
  bubble: {
    alignSelf: 'stretch',
    backgroundColor: colors.surface,
    padding: spacing.md,
    borderRadius: radius.lg,
    borderLeftWidth: 3,
  },
  text: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    lineHeight: 22,
  },
});
