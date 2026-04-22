import { Text, View, StyleSheet } from 'react-native';
import type { Message } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';
import { CitationBadge } from './CitationBadge';

type Props = {
  message: Message;
  avatarSlug: string;
};

export function MessageBubble({ message, avatarSlug }: Props) {
  const isUser = message.role === 'user';
  const slug = avatarSlug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <View style={[styles.row, isUser ? styles.rowUser : styles.rowAgent]}>
      <View
        style={[
          styles.bubble,
          isUser
            ? [styles.bubbleUser, { borderColor: accent }]
            : [styles.bubbleAgent, { borderLeftColor: accent }],
        ]}
      >
        <Text style={styles.text}>{message.content}</Text>
      </View>
      {!isUser && (
        <CitationBadge
          citationsCount={message.citations_count ?? 0}
          isVerified={message.is_verified}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { marginBottom: spacing.sm + 2 },
  rowUser: { alignItems: 'flex-end' },
  rowAgent: { alignItems: 'flex-start' },
  bubble: {
    maxWidth: '85%',
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.md,
    borderRadius: radius.lg,
  },
  bubbleUser: {
    backgroundColor: 'rgba(124,92,255,0.26)',
    borderWidth: 1,
    borderTopRightRadius: 4,
  },
  bubbleAgent: {
    backgroundColor: 'rgba(20,26,38,0.82)',
    borderLeftWidth: 3,
    borderTopLeftRadius: 4,
  },
  text: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    lineHeight: 22,
  },
});
