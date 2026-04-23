import { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  visible: boolean;
  initialTitle: string;
  onSave: (title: string) => Promise<void> | void;
  onClose: () => void;
};

export function RenameConversationModal({
  visible,
  initialTitle,
  onSave,
  onClose,
}: Props) {
  const [value, setValue] = useState(initialTitle);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (visible) {
      setValue(initialTitle);
      setSaving(false);
    }
  }, [visible, initialTitle]);

  const handleSave = async () => {
    const trimmed = value.trim();
    if (!trimmed) return;
    setSaving(true);
    try {
      await onSave(trimmed);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      visible={visible}
      transparent
      animationType="fade"
      onRequestClose={onClose}
    >
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.backdrop}
      >
        <Pressable style={styles.backdropPress} onPress={onClose}>
          <Pressable style={styles.card} onPress={(e) => e.stopPropagation()}>
            <Text style={styles.heading}>Rename conversation</Text>
            <TextInput
              style={styles.input}
              value={value}
              onChangeText={setValue}
              autoFocus
              maxLength={120}
              placeholder="Conversation title"
              placeholderTextColor={colors.textMuted}
              selectionColor={colors.primary}
            />
            <View style={styles.actionsRow}>
              <Pressable
                onPress={onClose}
                style={({ pressed }) => [
                  styles.btn,
                  styles.btnGhost,
                  pressed && styles.btnPressed,
                ]}
                disabled={saving}
              >
                <Text style={styles.btnGhostText}>Cancel</Text>
              </Pressable>
              <Pressable
                onPress={handleSave}
                style={({ pressed }) => [
                  styles.btn,
                  styles.btnPrimary,
                  (!value.trim() || saving) && styles.btnDisabled,
                  pressed && styles.btnPressed,
                ]}
                disabled={!value.trim() || saving}
              >
                {saving ? (
                  <ActivityIndicator color={colors.textPrimary} size="small" />
                ) : (
                  <Text style={styles.btnPrimaryText}>Save</Text>
                )}
              </Pressable>
            </View>
          </Pressable>
        </Pressable>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.6)',
  },
  backdropPress: {
    flex: 1,
    justifyContent: 'center',
    paddingHorizontal: spacing.lg,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  heading: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
    marginBottom: spacing.md,
  },
  input: {
    backgroundColor: colors.surfaceElevated,
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 2,
    fontSize: fontSize.md,
    marginBottom: spacing.md,
  },
  actionsRow: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    gap: spacing.sm,
  },
  btn: {
    minWidth: 88,
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.md,
    borderRadius: radius.md,
    alignItems: 'center',
    justifyContent: 'center',
  },
  btnGhost: {
    backgroundColor: 'transparent',
  },
  btnGhostText: {
    color: colors.textSecondary,
    fontSize: fontSize.md,
    fontWeight: '600',
  },
  btnPrimary: {
    backgroundColor: colors.primary,
  },
  btnPrimaryText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  btnDisabled: { opacity: 0.4 },
  btnPressed: { opacity: 0.85 },
});
