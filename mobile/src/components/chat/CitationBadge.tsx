import { useState } from 'react';
import { Alert, Linking, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, radius, fontSize } from '../../theme';
import type { ParsedCitation } from '../../utils/citations';
import { labelForCitation, sourceNameForCitation } from '../../utils/citations';

type VerificationStatus = 'passed' | 'failed' | 'not_required' | null;

type VerificationFailure = {
  type?: string;
  claim_text?: string;
  reason?: string;
};

type Props = {
  citationsCount: number;
  citations?: ParsedCitation[];
  isVerified: boolean | null;
  verificationStatus?: VerificationStatus;
  verificationFailures?: unknown[] | null;
};

/**
 * Compact badge under an agent bubble that doubles as the entry point
 * to the source list. Tapping either the passed or failed state opens
 * a modal — sources for passed, failure reasons for failed. Hidden for
 * messages the verification pipeline doesn't touch (hotel vertical,
 * legacy rows with no verification status).
 */
export function CitationBadge({
  citationsCount,
  citations,
  isVerified,
  verificationStatus,
  verificationFailures,
}: Props) {
  const [sourcesOpen, setSourcesOpen] = useState(false);
  const [failuresOpen, setFailuresOpen] = useState(false);

  if (
    verificationStatus === 'not_required' ||
    (verificationStatus == null && isVerified === null)
  ) {
    return null;
  }

  const passed = verificationStatus === 'passed' || (verificationStatus == null && isVerified === true);
  const failed = verificationStatus === 'failed' || (verificationStatus == null && isVerified === false);
  const hasFailures = Array.isArray(verificationFailures) && verificationFailures.length > 0;
  const hasCitations = Array.isArray(citations) && citations.length > 0;

  let iconName: keyof typeof Ionicons.glyphMap;
  let label: string;
  let tint: string;

  if (passed) {
    iconName = 'checkmark-circle';
    tint = colors.success;
    label =
      citationsCount > 0
        ? `Verified · ${citationsCount} source${citationsCount === 1 ? '' : 's'}`
        : 'Verified';
  } else if (failed) {
    iconName = 'alert-circle';
    tint = colors.warning;
    label = 'Fallback response';
  } else {
    return null;
  }

  const showSourcesAffordance = passed && hasCitations;
  const showFailuresAffordance = failed && hasFailures;
  const isTappable = showSourcesAffordance || showFailuresAffordance;

  const content = (
    <View style={[styles.badge, { borderColor: tint + '66' }]}>
      <Ionicons name={iconName} size={12} color={tint} />
      <Text style={[styles.text, { color: tint }]}>{label}</Text>
      {isTappable && (
        <Ionicons
          name={showSourcesAffordance ? 'information-circle-outline' : 'chevron-forward'}
          size={13}
          color={tint}
        />
      )}
    </View>
  );

  if (showSourcesAffordance) {
    return (
      <>
        <Pressable
          onPress={() => setSourcesOpen(true)}
          hitSlop={8}
          accessibilityLabel="View sources"
        >
          {content}
        </Pressable>
        <SourcesModal
          visible={sourcesOpen}
          citations={citations as ParsedCitation[]}
          onClose={() => setSourcesOpen(false)}
        />
      </>
    );
  }

  if (showFailuresAffordance) {
    return (
      <>
        <Pressable
          onPress={() => setFailuresOpen(true)}
          hitSlop={8}
          accessibilityLabel="Why the fallback"
        >
          {content}
        </Pressable>
        <VerificationFailuresModal
          visible={failuresOpen}
          failures={(verificationFailures as VerificationFailure[]) ?? []}
          onClose={() => setFailuresOpen(false)}
        />
      </>
    );
  }

  return content;
}

function openExternal(url: string) {
  Linking.openURL(url).catch(() => Alert.alert("Couldn't open link", url));
}

function SourcesModal({
  visible,
  citations,
  onClose,
}: {
  visible: boolean;
  citations: ParsedCitation[];
  onClose: () => void;
}) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <Pressable style={modalStyles.backdrop} onPress={onClose}>
        <Pressable style={modalStyles.card} onPress={(e) => e.stopPropagation()}>
          <View style={modalStyles.header}>
            <Ionicons name="document-text-outline" size={18} color={colors.success} />
            <Text style={modalStyles.heading}>Sources</Text>
          </View>
          <Text style={modalStyles.lead}>
            The response was grounded in the following references. Tap one to open it.
          </Text>
          {citations.map((c, idx) => (
            <Pressable
              key={`${c.type}-${c.key}-${idx}`}
              onPress={() => openExternal(c.url)}
              style={({ pressed }) => [
                modalStyles.sourceRow,
                pressed && modalStyles.sourceRowPressed,
              ]}
            >
              <View style={modalStyles.sourceText}>
                <Text style={modalStyles.sourceLabel}>{labelForCitation(c)}</Text>
                <Text style={modalStyles.sourceMeta}>{sourceNameForCitation(c)}</Text>
              </View>
              <Ionicons name="open-outline" size={16} color={colors.textMuted} />
            </Pressable>
          ))}
          <Pressable
            onPress={onClose}
            style={({ pressed }) => [modalStyles.closeBtn, pressed && { opacity: 0.85 }]}
          >
            <Text style={modalStyles.closeBtnText}>Close</Text>
          </Pressable>
        </Pressable>
      </Pressable>
    </Modal>
  );
}

function VerificationFailuresModal({
  visible,
  failures,
  onClose,
}: {
  visible: boolean;
  failures: VerificationFailure[];
  onClose: () => void;
}) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <Pressable style={modalStyles.backdrop} onPress={onClose}>
        <Pressable style={modalStyles.card} onPress={(e) => e.stopPropagation()}>
          <View style={modalStyles.header}>
            <Ionicons name="alert-circle" size={18} color={colors.warning} />
            <Text style={modalStyles.heading}>Why the fallback?</Text>
          </View>
          <Text style={modalStyles.lead}>
            The response couldn't be verified against trusted sources, so a safe
            fallback was sent instead.
          </Text>
          {failures.map((f, idx) => (
            <View key={idx} style={modalStyles.failure}>
              {f.type && <Text style={modalStyles.failureType}>{prettyType(f.type)}</Text>}
              {f.claim_text && (
                <Text style={modalStyles.failureClaim} numberOfLines={3}>
                  “{f.claim_text}”
                </Text>
              )}
              {f.reason && <Text style={modalStyles.failureReason}>{f.reason}</Text>}
            </View>
          ))}
          <Pressable
            onPress={onClose}
            style={({ pressed }) => [modalStyles.closeBtn, pressed && { opacity: 0.85 }]}
          >
            <Text style={modalStyles.closeBtnText}>Got it</Text>
          </Pressable>
        </Pressable>
      </Pressable>
    </Modal>
  );
}

function prettyType(type: string): string {
  return type
    .toLowerCase()
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

const styles = StyleSheet.create({
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    borderWidth: 1,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
    marginTop: spacing.xs,
    gap: 4,
    backgroundColor: 'rgba(0,0,0,0.25)',
  },
  text: {
    fontSize: fontSize.xs,
    fontWeight: '600',
    letterSpacing: 0.2,
  },
});

const modalStyles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.65)',
    justifyContent: 'center',
    padding: spacing.lg,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  heading: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  lead: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
    lineHeight: 20,
    marginBottom: spacing.sm,
  },
  sourceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.sm,
    borderRadius: radius.md,
    backgroundColor: colors.surfaceElevated,
    gap: spacing.sm,
  },
  sourceRowPressed: {
    opacity: 0.85,
  },
  sourceText: { flex: 1 },
  sourceLabel: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  sourceMeta: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    marginTop: 2,
  },
  failure: {
    borderLeftWidth: 3,
    borderLeftColor: colors.warning,
    paddingLeft: spacing.sm,
    paddingVertical: spacing.xs,
    gap: 2,
  },
  failureType: {
    color: colors.warning,
    fontSize: fontSize.xs,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 0.6,
  },
  failureClaim: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontStyle: 'italic',
  },
  failureReason: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    lineHeight: 16,
  },
  closeBtn: {
    alignSelf: 'flex-end',
    marginTop: spacing.sm,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    borderRadius: radius.md,
    backgroundColor: colors.primary,
  },
  closeBtnText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontWeight: '700',
  },
});
