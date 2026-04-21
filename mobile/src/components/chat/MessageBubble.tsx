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
            ? { backgroundColor: colors.primary }
            : { backgroundColor: colors.surface, borderLeftColor: accent, borderLeftWidth: 3 },
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
  row: { marginBottom: spacing.md },
  rowUser: { alignItems: 'flex-end' },
  rowAgent: { alignItems: 'flex-start' },
  bubble: {
    maxWidth: '85%',
    padding: spacing.md,
    borderRadius: radius.lg,
  },
  text: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    lineHeight: 22,
  },
});
