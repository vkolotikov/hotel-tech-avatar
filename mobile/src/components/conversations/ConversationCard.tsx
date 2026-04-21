import { Pressable, Text, View, StyleSheet } from 'react-native';
import type { Conversation } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  conversation: Conversation;
  onPress: (c: Conversation) => void;
};

export function ConversationCard({ conversation, onPress }: Props) {
  const slug = conversation.agent?.slug as AvatarSlug | undefined;
  const accent = slug && slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <Pressable
      testID="conversation-card"
      onPress={() => onPress(conversation)}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
    >
      <View style={[styles.avatarDot, { backgroundColor: accent }]} />
      <View style={styles.body}>
        <Text style={styles.title} numberOfLines={1}>
          {conversation.title ?? 'Untitled conversation'}
        </Text>
        <Text style={styles.subtitle}>{conversation.agent?.name ?? 'Agent'}</Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    padding: spacing.md,
    borderRadius: radius.md,
    marginBottom: spacing.sm,
  },
  pressed: { opacity: 0.7 },
  avatarDot: {
    width: 40,
    height: 40,
    borderRadius: radius.pill,
    marginRight: spacing.md,
  },
  body: { flex: 1 },
  title: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    marginBottom: 2,
  },
  subtitle: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
  },
});
