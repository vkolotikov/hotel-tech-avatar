import { Pressable, Text, View, StyleSheet } from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  onStartNew: () => void;
};

export function EmptyState({ onStartNew }: Props) {
  return (
    <View style={styles.container}>
      <View style={styles.badge} />
      <Text style={styles.heading}>Start your first chat</Text>
      <Text style={styles.body}>
        Choose an avatar to begin — they're here to guide, coach, and answer.
      </Text>
      <Pressable
        style={({ pressed }) => [styles.button, pressed && styles.buttonPressed]}
        onPress={onStartNew}
      >
        <Text style={styles.buttonText}>Choose an avatar</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
    backgroundColor: colors.background,
  },
  badge: {
    width: 64,
    height: 64,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(124,92,255,0.15)',
    borderWidth: 1,
    borderColor: 'rgba(124,92,255,0.35)',
    marginBottom: spacing.lg,
  },
  heading: {
    color: colors.textPrimary,
    fontSize: fontSize.xl,
    fontWeight: '700',
    marginBottom: spacing.sm,
    letterSpacing: -0.5,
  },
  body: {
    color: colors.textMuted,
    fontSize: fontSize.md,
    textAlign: 'center',
    marginBottom: spacing.xl,
    lineHeight: 22,
    maxWidth: 300,
  },
  button: {
    backgroundColor: colors.primary,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.xl,
    borderRadius: radius.pill,
    shadowColor: colors.primary,
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.4,
    shadowRadius: 12,
    elevation: 6,
  },
  buttonPressed: { opacity: 0.85, transform: [{ scale: 0.98 }] },
  buttonText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    letterSpacing: 0.2,
  },
});
