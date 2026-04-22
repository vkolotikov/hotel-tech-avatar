import { Image, Pressable, Text, View, StyleSheet } from 'react-native';
import type { Conversation } from '../../types/models';
import { resolveAssetUrl } from '../../api';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  conversation: Conversation;
  onPress: (c: Conversation) => void;
  onLongPress?: (c: Conversation) => void;
};

export function ConversationCard({ conversation, onPress, onLongPress }: Props) {
  const slug = conversation.agent?.slug as AvatarSlug | undefined;
  const accent = slug && slug in avatarColors ? avatarColors[slug] : colors.primary;
  const imageUrl = resolveAssetUrl(conversation.agent?.avatar_image_url);

  return (
    <Pressable
      testID="conversation-card"
      onPress={() => onPress(conversation)}
      onLongPress={onLongPress ? () => onLongPress(conversation) : undefined}
      style={({ pressed }) => [
        styles.card,
        { borderColor: accent + '33' },
        pressed && styles.pressed,
      ]}
    >
      <View style={[styles.avatarRing, { borderColor: accent }]}>
        {imageUrl ? (
          <Image source={{ uri: imageUrl }} style={styles.avatarImage} />
        ) : (
          <View style={[styles.avatarDot, { backgroundColor: accent }]} />
        )}
      </View>
      <View style={styles.body}>
        <Text style={styles.title} numberOfLines={1}>
          {conversation.title ?? 'Untitled conversation'}
        </Text>
        <Text style={[styles.subtitle, { color: accent }]} numberOfLines={1}>
          {conversation.agent?.name ?? 'Agent'}
        </Text>
      </View>
      <View style={[styles.chevronDot, { backgroundColor: accent + '33' }]} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    padding: spacing.md,
    borderRadius: radius.lg,
    borderWidth: 1,
    marginBottom: spacing.sm + 2,
  },
  pressed: { opacity: 0.85, transform: [{ scale: 0.995 }] },
  avatarRing: {
    width: 60,
    height: 60,
    borderRadius: radius.pill,
    borderWidth: 2,
    padding: 2,
    marginRight: spacing.md,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarImage: {
    width: 52,
    height: 52,
    borderRadius: radius.pill,
    backgroundColor: colors.surfaceElevated,
  },
  avatarDot: {
    width: 52,
    height: 52,
    borderRadius: radius.pill,
  },
  body: { flex: 1 },
  title: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    marginBottom: 2,
    letterSpacing: -0.2,
  },
  subtitle: {
    fontSize: fontSize.xs,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  chevronDot: {
    width: 8,
    height: 8,
    borderRadius: radius.pill,
    marginLeft: spacing.sm,
  },
});
