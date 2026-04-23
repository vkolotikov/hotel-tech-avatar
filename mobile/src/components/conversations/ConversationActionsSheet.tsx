import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, spacing, radius, fontSize } from '../../theme';
import type { Conversation } from '../../types/models';

type Props = {
  visible: boolean;
  conversation: Conversation | null;
  onRename: () => void;
  onDelete: () => void;
  onClose: () => void;
};

export function ConversationActionsSheet({
  visible,
  conversation,
  onRename,
  onDelete,
  onClose,
}: Props) {
  const insets = useSafeAreaInsets();
  const title = conversation?.title ?? 'Untitled conversation';

  return (
    <Modal
      visible={visible}
      transparent
      animationType="fade"
      onRequestClose={onClose}
    >
      <Pressable style={styles.backdrop} onPress={onClose}>
        <Pressable
          style={[styles.sheet, { paddingBottom: insets.bottom + spacing.md }]}
          onPress={(e) => e.stopPropagation()}
        >
          <View style={styles.handle} />
          <Text style={styles.heading} numberOfLines={1}>
            {title}
          </Text>

          <Pressable
            onPress={onRename}
            style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
          >
            <Ionicons name="create-outline" size={22} color={colors.textPrimary} />
            <Text style={styles.rowLabel}>Rename</Text>
          </Pressable>

          <Pressable
            onPress={onDelete}
            style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
          >
            <Ionicons name="trash-outline" size={22} color={colors.danger} />
            <Text style={[styles.rowLabel, { color: colors.danger }]}>Delete</Text>
          </Pressable>

          <Pressable
            onPress={onClose}
            style={({ pressed }) => [styles.cancel, pressed && styles.rowPressed]}
          >
            <Text style={styles.cancelLabel}>Cancel</Text>
          </Pressable>
        </Pressable>
      </Pressable>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.5)',
    justifyContent: 'flex-end',
  },
  sheet: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.sm,
  },
  handle: {
    alignSelf: 'center',
    width: 36,
    height: 4,
    borderRadius: 2,
    backgroundColor: 'rgba(255,255,255,0.2)',
    marginBottom: spacing.sm,
  },
  heading: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    fontWeight: '600',
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.sm,
    borderRadius: radius.md,
    gap: spacing.md,
  },
  rowPressed: {
    backgroundColor: 'rgba(255,255,255,0.05)',
  },
  rowLabel: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '500',
  },
  cancel: {
    alignItems: 'center',
    paddingVertical: spacing.md,
    marginTop: spacing.sm,
    borderRadius: radius.md,
    backgroundColor: colors.surfaceElevated,
  },
  cancelLabel: {
    color: colors.textSecondary,
    fontSize: fontSize.md,
    fontWeight: '600',
  },
});
