import { useMemo, useState } from 'react';
import { Alert, Pressable, Text, View, StyleSheet } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import Markdown from 'react-native-markdown-display';
import type { Message, Attachment } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';
import { CitationBadge } from './CitationBadge';
import { parseContent } from '../../utils/citations';
import { fetchAndPlay, useMessagePlayback } from '../../hooks/useMessagePlayback';

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

type SpeakButtonProps = {
  message: Message;
  accent: string;
  /**
   * The cleaned reply text (citation markers stripped). Speaking this
   * version stops the avatar from reading "PMID 12345" or "[1]" aloud
   * and keeps us under the backend's 4096-char TTS limit on long
   * structured replies.
   */
  speakText: string;
};

/**
 * Per-message TTS playback button. Sits in the agent bubble's footer
 * row and toggles between ▶ Speak and ■ Stop. Uses the shared playback
 * singleton so opening playback on one bubble auto-stops any other.
 */
function SpeakButton({ message, accent, speakText }: SpeakButtonProps) {
  const playback = useMessagePlayback();
  const [loading, setLoading] = useState(false);
  const isThis = playback.isPlaying(message.id);

  const handlePress = async () => {
    if (isThis) {
      await playback.stop();
      return;
    }
    const trimmed = speakText.trim();
    if (!trimmed) return;

    // Backend validates `max:4096` chars on /voice/speak. Long structured
    // replies (meal plans, comparisons) easily blow past that and used
    // to silently 422. Truncate at a safe boundary on a sentence break
    // when possible so spoken output ends cleanly.
    const safe = trimmed.length <= 4000
      ? trimmed
      : (() => {
          const slice = trimmed.slice(0, 4000);
          const lastDot = Math.max(slice.lastIndexOf('. '), slice.lastIndexOf('! '), slice.lastIndexOf('? '));
          return lastDot > 3000 ? slice.slice(0, lastDot + 1) : slice;
        })();

    setLoading(true);
    try {
      await fetchAndPlay(message.id, message.conversation_id, safe);
    } catch (err) {
      const detail = err instanceof Error ? err.message : String(err);
      console.warn('SpeakButton: playback failed', detail);
      Alert.alert('Could not play voice', detail);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Pressable
      onPress={handlePress}
      hitSlop={6}
      accessibilityLabel={isThis ? 'Stop speaking' : 'Speak this message'}
      style={({ pressed }) => [
        styles.speakBtn,
        isThis && { borderColor: accent, backgroundColor: accent + '22' },
        pressed && { opacity: 0.7 },
      ]}
      disabled={loading}
    >
      <Ionicons
        name={isThis ? 'stop' : loading ? 'ellipsis-horizontal' : 'play'}
        size={11}
        color={isThis ? accent : colors.textMuted}
      />
      <Text style={[styles.speakBtnText, isThis && { color: accent }]}>
        {isThis ? 'Stop' : loading ? '...' : 'Speak'}
      </Text>
    </Pressable>
  );
}

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

  // Only agent replies need citation parsing; user messages display raw.
  // Parsing is cheap but memoised anyway because FlatList may re-render
  // the bubble when the viewport shifts.
  const parsed = useMemo(() => {
    if (isUser) return { cleaned: message.content, citations: [] };
    return parseContent(message.content);
  }, [isUser, message.content]);

  const displayText = parsed.cleaned;

  // Citations extracted from the reply text are the authoritative list
  // for the info icon; fall back to the count the backend returned if
  // for some reason the model emitted citations we didn't match (e.g.
  // a new format we haven't parsed yet).
  const citationsForBadge = parsed.citations;
  const countForBadge = parsed.citations.length > 0
    ? parsed.citations.length
    : (message.citations_count ?? 0);

  // Build markdown render styles inside the component so we can pull the
  // accent + theme values from props/closure. Only used on agent
  // messages — user input stays as plain Text since users don't author
  // markdown intentionally.
  const markdownStyles = useMemo(() => buildMarkdownStyles(accent), [accent]);

  return (
    <View style={[styles.row, isUser ? styles.rowUser : styles.rowAgent]}>
      <View
        style={[
          isUser ? styles.bubble : styles.bubbleWide,
          isUser
            ? [styles.bubbleUser, { borderColor: accent }]
            : [styles.bubbleAgent, { borderLeftColor: accent }],
        ]}
      >
        {displayText.length > 0 && (
          isUser
            ? <Text style={styles.text}>{displayText}</Text>
            : <Markdown style={markdownStyles}>{displayText}</Markdown>
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
            citationsCount={countForBadge}
            citations={citationsForBadge}
            isVerified={message.is_verified}
            verificationStatus={message.verification_status}
            verificationFailures={message.verification_failures_json}
          />
        )}
        {!isUser && displayText.trim().length > 0 && (
          <SpeakButton message={message} accent={accent} speakText={displayText} />
        )}
        {time !== '' && <Text style={styles.timeText}>{time}</Text>}
      </View>
    </View>
  );
}

/**
 * Theme tokens for the markdown renderer. Background is transparent so
 * the bubble's own background shows through, and table borders pick up
 * the avatar accent so structured data looks tied to that avatar.
 */
function buildMarkdownStyles(accent: string) {
  return {
    body: {
      color: colors.textPrimary,
      fontSize: fontSize.md,
      lineHeight: 22,
    },
    paragraph: {
      marginTop: 0,
      marginBottom: 6,
    },
    heading1: {
      color: colors.textPrimary,
      fontSize: fontSize.lg,
      fontWeight: '700' as const,
      marginTop: 8,
      marginBottom: 4,
    },
    heading2: {
      color: colors.textPrimary,
      fontSize: fontSize.md + 2,
      fontWeight: '700' as const,
      marginTop: 8,
      marginBottom: 4,
    },
    heading3: {
      color: colors.textPrimary,
      fontSize: fontSize.md,
      fontWeight: '700' as const,
      marginTop: 6,
      marginBottom: 2,
    },
    strong: { fontWeight: '700' as const, color: colors.textPrimary },
    em: { fontStyle: 'italic' as const, color: colors.textPrimary },
    bullet_list: { marginVertical: 4 },
    ordered_list: { marginVertical: 4 },
    list_item: { marginVertical: 2 },
    code_inline: {
      backgroundColor: 'rgba(255,255,255,0.08)',
      color: colors.textPrimary,
      borderRadius: 4,
      paddingHorizontal: 4,
      fontSize: fontSize.sm,
    },
    code_block: {
      backgroundColor: 'rgba(255,255,255,0.06)',
      color: colors.textPrimary,
      borderRadius: radius.sm,
      padding: spacing.sm,
      fontSize: fontSize.sm,
    },
    fence: {
      backgroundColor: 'rgba(255,255,255,0.06)',
      color: colors.textPrimary,
      borderRadius: radius.sm,
      padding: spacing.sm,
      fontSize: fontSize.sm,
    },
    link: { color: accent },
    blockquote: {
      backgroundColor: 'rgba(255,255,255,0.04)',
      borderLeftColor: accent,
      borderLeftWidth: 3,
      paddingVertical: 4,
      paddingHorizontal: spacing.sm,
      marginVertical: 6,
    },
    table: {
      borderWidth: 1,
      borderColor: 'rgba(255,255,255,0.18)',
      borderRadius: radius.sm,
      marginVertical: 8,
    },
    thead: {
      backgroundColor: 'rgba(255,255,255,0.06)',
    },
    th: {
      padding: 6,
      borderRightWidth: 1,
      borderRightColor: 'rgba(255,255,255,0.12)',
    },
    tr: {
      borderBottomWidth: 1,
      borderBottomColor: 'rgba(255,255,255,0.08)',
    },
    td: {
      padding: 6,
      borderRightWidth: 1,
      borderRightColor: 'rgba(255,255,255,0.08)',
    },
    hr: {
      backgroundColor: 'rgba(255,255,255,0.12)',
      height: 1,
      marginVertical: 8,
    },
  };
}

const styles = StyleSheet.create({
  row: { marginBottom: spacing.sm + 2 },
  rowUser: { alignItems: 'flex-end' },
  rowAgent: { alignItems: 'stretch' },
  // User bubbles stay narrow so the chat retains its left/right "two
  // sides of a conversation" feel — the right-side cap also keeps long
  // pasted prompts from looking like a wall.
  bubble: {
    maxWidth: '85%',
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.md,
    borderRadius: radius.lg,
  },
  // Agent bubbles use the full width so structured replies (markdown
  // tables, multi-section meal plans, comparisons) have room to breathe
  // without word-wrap making everything illegible.
  bubbleWide: {
    alignSelf: 'stretch',
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
  speakBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingVertical: 2,
    paddingHorizontal: 6,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.12)',
    backgroundColor: 'rgba(20,26,38,0.6)',
  },
  speakBtnText: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: '600',
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
