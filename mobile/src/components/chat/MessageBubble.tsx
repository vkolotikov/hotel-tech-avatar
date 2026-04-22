import { Text, View, StyleSheet } from 'react-native';
import type { Message } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';
import { CitationBadge } from './CitationBadge';

type Props = {
  message: Message;
  avatarSlug: string;
};

function formatTime(iso: string | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  let h = d.getHours();
  const m = d.getMinutes().toString().padStart(2, '0');
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return `${h}:${m} ${ampm}`;
}

export function MessageBubble({ message, avatarSlug }: Props) {
  const isUser = message.role === 'user';
  const slug = avatarSlug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;
  const time = formatTime(message.created_at);

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
      <View style={[styles.metaRow, isUser ? styles.metaRowUser : styles.metaRowAgent]}>
        {!isUser && (
          <CitationBadge
            citationsCount={message.citations_count ?? 0}
            isVerified={message.is_verified}
          />
        )}
        {time !== '' && <Text style={styles.timeText}>{time}</Text>}
      </View>
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
  metaRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 2,
    gap: spacing.xs,
  },
  metaRowUser: { justifyContent: 'flex-end' },
  metaRowAgent: { justifyContent: 'flex-start' },
  timeText: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: '500',
    letterSpacing: 0.3,
  },
});
