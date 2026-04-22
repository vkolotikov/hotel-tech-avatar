import { ImageBackground, Pressable, Text, View, StyleSheet } from 'react-native';
import type { Avatar } from '../../types/models';
import { resolveAssetUrl } from '../../api';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  avatar: Avatar;
  onPress: (avatar: Avatar) => void;
};

export function AvatarPickerCard({ avatar, onPress }: Props) {
  const slug = avatar.slug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;
  const imageUrl = resolveAssetUrl(avatar.avatar_image_url);

  return (
    <Pressable
      testID={`avatar-card-${avatar.slug}`}
      onPress={() => onPress(avatar)}
      style={({ pressed }) => [
        styles.card,
        { borderColor: accent + '55' },
        pressed && styles.pressed,
      ]}
    >
      <View style={styles.portraitWrap}>
        {imageUrl ? (
          <ImageBackground
            source={{ uri: imageUrl }}
            style={styles.portrait}
            resizeMode="cover"
          >
            <View style={styles.portraitFade} pointerEvents="none" />
          </ImageBackground>
        ) : (
          <View style={[styles.portrait, { backgroundColor: accent, opacity: 0.4 }]} />
        )}
        <View style={[styles.accentBadge, { backgroundColor: accent }]} />
      </View>

      <View style={styles.body}>
        <Text style={styles.name} numberOfLines={1}>
          {avatar.name}
        </Text>
        <Text style={[styles.role, { color: accent }]} numberOfLines={1}>
          {avatar.role}
        </Text>
        {avatar.description && (
          <Text style={styles.description} numberOfLines={3}>
            {avatar.description}
          </Text>
        )}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    marginBottom: spacing.md,
    overflow: 'hidden',
  },
  pressed: { opacity: 0.85, transform: [{ scale: 0.99 }] },
  portraitWrap: { width: '100%', height: 200, position: 'relative' },
  portrait: { flex: 1, width: '100%', height: '100%' },
  portraitFade: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    height: 80,
    backgroundColor: 'rgba(20,26,38,0.65)',
  },
  accentBadge: {
    position: 'absolute',
    left: spacing.md,
    bottom: spacing.md,
    width: 4,
    height: 32,
    borderRadius: 2,
  },
  body: { padding: spacing.md },
  name: {
    color: colors.textPrimary,
    fontSize: fontSize.lg,
    fontWeight: '700',
    marginBottom: 2,
    letterSpacing: -0.3,
  },
  role: {
    fontSize: fontSize.sm,
    fontWeight: '600',
    marginBottom: spacing.xs,
    textTransform: 'uppercase',
    letterSpacing: 0.6,
  },
  description: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
    lineHeight: 20,
  },
});
