import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  avatarSlug: string;
  avatarName: string;
  onPick: (text: string) => void;
};

const PROMPTS_BY_SLUG: Record<string, string[]> = {
  nora: [
    'What foods help with gut health?',
    'How should I read a nutrition label?',
    'Ideas for a balanced breakfast?',
  ],
  integra: [
    'What does a CBC panel actually show?',
    'Help me prep questions for my next doctor visit',
    'How do I think about chronic inflammation?',
  ],
  luna: [
    'I can\'t fall asleep — where do I start?',
    'Design a wind-down routine for me',
    'Is my caffeine intake affecting sleep?',
  ],
  zen: [
    'I\'m stressed — a 2-minute practice?',
    'How do I handle racing thoughts?',
    'Teach me box breathing',
  ],
  axel: [
    'Build me a simple strength routine',
    'How much cardio do I actually need?',
    'I\'m sore — rest or move?',
  ],
  aura: [
    'Help me build a basic skincare routine',
    'What does niacinamide actually do?',
    'How do I read an ingredient list?',
  ],
};

const DEFAULT_PROMPTS = [
  'What can you help me with?',
  'Tell me something useful today',
  'What should I ask you first?',
];

export function StarterPrompts({ avatarSlug, avatarName, onPick }: Props) {
  const prompts = PROMPTS_BY_SLUG[avatarSlug] ?? DEFAULT_PROMPTS;
  const slug = avatarSlug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <View style={styles.wrap}>
      <Text style={styles.kicker}>Try asking {avatarName}</Text>
      <View style={styles.chips}>
        {prompts.map((prompt) => (
          <Pressable
            key={prompt}
            onPress={() => onPick(prompt)}
            style={({ pressed }) => [
              styles.chip,
              { borderColor: accent + '66' },
              pressed && styles.chipPressed,
            ]}
          >
            <Text style={styles.chipText}>{prompt}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    paddingVertical: spacing.md,
    gap: spacing.sm,
  },
  kicker: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.8,
    textAlign: 'center',
    textShadowColor: 'rgba(0,0,0,0.75)',
    textShadowRadius: 6,
  },
  chips: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    justifyContent: 'center',
  },
  chip: {
    paddingVertical: spacing.sm,
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
    fontSize: fontSize.sm,
  },
});
