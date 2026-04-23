import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  suggestions: string[];
  avatarSlug: string;
  onPick: (text: string) => void;
};

/**
 * Tappable follow-up chips rendered under the most recent agent message.
 * Fed by the model's structured output ({reply, suggestions}). Tapping a
 * chip sends it as the next user message — same behaviour as typing it
 * into the input.
 */
export function SuggestionChips({ suggestions, avatarSlug, onPick }: Props) {
  if (!suggestions || suggestions.length === 0) return null;

  const slug = avatarSlug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <View style={styles.wrap}>
      {suggestions.map((s) => (
        <Pressable
          key={s}
          onPress={() => onPick(s)}
          style={({ pressed }) => [
            styles.chip,
            { borderColor: accent + '66' },
            pressed && styles.chipPressed,
          ]}
        >
          <Text style={styles.chipText}>{s}</Text>
        </Pressable>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.xs + 2,
    marginTop: spacing.xs,
    marginBottom: spacing.sm,
    paddingHorizontal: spacing.xs,
  },
  chip: {
    paddingVertical: spacing.xs + 2,
    paddingHorizontal: spacing.md,
    borderRadius: radius.pill,
    borderWidth: 1,
    backgroundColor: 'rgba(20,26,38,0.82)',
  },
  chipPressed: {
    opacity: 0.85,
    transform: [{ scale: 0.98 }],
  },
  chipText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm - 1,
  },
});
