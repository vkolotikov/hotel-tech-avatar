import { useState } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, radius, fontSize } from '../../theme';

type VerificationStatus = 'passed' | 'failed' | 'not_required' | null;

type VerificationFailure = {
  type?: string;
  claim_text?: string;
  reason?: string;
};

type Props = {
  citationsCount: number;
  isVerified: boolean | null;
  verificationStatus?: VerificationStatus;
  verificationFailures?: unknown[] | null;
};

/**
 * Compact badge under an agent bubble showing the verification outcome.
 * Tap to see which claims failed (when applicable). Hidden entirely on
 * messages that don't go through the verification pipeline (hotel vertical,
 * legacy rows with status=null).
 */
export function CitationBadge({
  citationsCount,
  isVerified,
  verificationStatus,
  verificationFailures,
}: Props) {
  const [detailsOpen, setDetailsOpen] = useState(false);

  // Hide entirely for messages the verification pipeline didn't touch.
  if (verificationStatus === 'not_required' || (verificationStatus == null && isVerified === null)) {
    return null;
  }

  const passed = verificationStatus === 'passed' || (verificationStatus == null && isVerified === true);
  const failed = verificationStatus === 'failed' || (verificationStatus == null && isVerified === false);
  const hasFailures = Array.isArray(verificationFailures) && verificationFailures.length > 0;

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
    // Shouldn't reach here given the early returns — render nothing.
    return null;
  }

  const content = (
    <View style={[styles.badge, { borderColor: tint + '66' }]}>
      <Ionicons name={iconName} size={12} color={tint} />
      <Text style={[styles.text, { color: tint }]}>{label}</Text>
      {failed && hasFailures && (
        <Ionicons name="chevron-forward" size={11} color={tint} />
      )}
    </View>
  );

  if (failed && hasFailures) {
    return (
      <>
        <Pressable onPress={() => setDetailsOpen(true)} hitSlop={8}>
          {content}
        </Pressable>
        <VerificationFailuresModal
          visible={detailsOpen}
          failures={(verificationFailures as VerificationFailure[]) ?? []}
          onClose={() => setDetailsOpen(false)}
        />
      </>
    );
  }

  return content;
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
