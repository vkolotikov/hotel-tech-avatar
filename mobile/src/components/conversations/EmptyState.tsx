import { Pressable, Text, View, StyleSheet } from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  onStartNew: () => void;
};

export function EmptyState({ onStartNew }: Props) {
  return (
    <View style={styles.container}>
      <Text style={styles.heading}>No conversations yet</Text>
      <Text style={styles.body}>
        Start a new chat with one of your wellness avatars.
      </Text>
      <Pressable style={styles.button} onPress={onStartNew}>
        <Text style={styles.buttonText}>Start new chat</Text>
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
  },
  heading: {
    color: colors.textPrimary,
    fontSize: fontSize.lg,
    fontWeight: '600',
    marginBottom: spacing.sm,
  },
  body: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    textAlign: 'center',
    marginBottom: spacing.lg,
  },
  button: {
    backgroundColor: colors.primary,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
  },
  buttonText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
  },
});
