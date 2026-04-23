import { Text, View, StyleSheet } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import type { Message, Attachment } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';
import { CitationBadge } from './CitationBadge';

function iconForMime(mime: string | null | undefined): keyof typeof Ionicons.glyphMap {
  if (!mime) return 'document-outline';
  if (mime.startsWith('image/')) return 'image-outline';
  if (mime.startsWith('video/')) return 'videocam-outline';
  if (mime.startsWith('audio/')) return 'musical-notes-outline';
  if (mime.includes('pdf')) return 'document-text-outline';
  return 'document-outline';
}

function AttachmentChip({ attachment }: { attachment: Attachment }) {
  return (
    <View style={bubbleStyles.attachmentChip}>
      <Ionicons
        name={iconForMime(attachment.mime_type)}
        size={16}
        color={colors.textPrimary}
      />
      <Text style={bubbleStyles.attachmentName} numberOfLines={1}>
        {attachment.file_name}
      </Text>
    </View>
  );
}

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
        {message.content.length > 0 && (
          <Text style={styles.text}>{message.content}</Text>
        )}
        {message.attachments && message.attachments.length > 0 && (
          <View style={bubbleStyles.attachments}>
            {message.attachments.map((a) => (
              <AttachmentChip key={a.id} attachment={a} />
            ))}
          </View>
        )}
      </View>
      <View style={[styles.metaRow, isUser ? styles.metaRowUser : styles.metaRowAgent]}>
        {!isUser && (
          <CitationBadge
            citationsCount={message.citations_count ?? 0}
            isVerified={message.is_verified}
            verificationStatus={message.verification_status}
            verificationFailures={message.verification_failures_json}
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

const bubbleStyles = StyleSheet.create({
  attachments: {
    marginTop: spacing.xs + 2,
    gap: spacing.xs,
  },
  attachmentChip: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: radius.md,
    paddingVertical: 6,
    paddingHorizontal: spacing.sm,
    gap: spacing.xs + 2,
    maxWidth: 260,
  },
  attachmentName: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    flexShrink: 1,
  },
});
