import { Text, View, StyleSheet } from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  citationsCount: number;
  isVerified: boolean | null;
};

export function CitationBadge({ citationsCount, isVerified }: Props) {
  if (isVerified === null) return null;

  const label =
    isVerified === true
      ? `📎 ${citationsCount} source${citationsCount === 1 ? '' : 's'} · Verified ✓`
      : '⚠ Fallback response';

  const tint = isVerified ? colors.success : colors.warning;

  return (
    <View style={[styles.badge, { borderColor: tint }]}>
      <Text style={[styles.text, { color: tint }]}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    alignSelf: 'flex-start',
    borderWidth: 1,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
    marginTop: spacing.xs,
  },
  text: {
    fontSize: fontSize.xs,
    fontWeight: '500',
  },
});
