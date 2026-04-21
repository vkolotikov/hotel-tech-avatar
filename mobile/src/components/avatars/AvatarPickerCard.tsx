import { Pressable, Text, View, StyleSheet } from 'react-native';
import type { Avatar } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  avatar: Avatar;
  onPress: (avatar: Avatar) => void;
};

export function AvatarPickerCard({ avatar, onPress }: Props) {
  const slug = avatar.slug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <Pressable
      testID={`avatar-card-${avatar.slug}`}
      onPress={() => onPress(avatar)}
      style={({ pressed }) => [
        styles.card,
        { borderColor: accent },
        pressed && styles.pressed,
      ]}
    >
      <View style={[styles.dot, { backgroundColor: accent }]} />
      <Text style={styles.name}>{avatar.name}</Text>
      <Text style={styles.role}>{avatar.role}</Text>
      {avatar.description && (
        <Text style={styles.description} numberOfLines={3}>
          {avatar.description}
        </Text>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    padding: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    marginBottom: spacing.sm,
  },
  pressed: { opacity: 0.7 },
  dot: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    marginBottom: spacing.sm,
  },
  name: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    marginBottom: 2,
  },
  role: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    marginBottom: spacing.xs,
  },
  description: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
  },
});
