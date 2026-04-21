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
  row: { alignItems: 'flex-start', marginBottom: spacing.md },
  bubble: {
    maxWidth: '85%',
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
