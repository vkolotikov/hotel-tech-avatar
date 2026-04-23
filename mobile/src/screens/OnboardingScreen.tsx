import { useRef, useState } from 'react';
import {
  Dimensions,
  FlatList,
  NativeScrollEvent,
  NativeSyntheticEvent,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, spacing, radius, fontSize } from '../theme';

const { width: SCREEN_WIDTH } = Dimensions.get('window');

type Props = {
  onFinish: () => void;
};

type Slide = {
  key: string;
  icon: keyof typeof Ionicons.glyphMap;
  iconTint: string;
  title: string;
  body: string;
};

const SLIDES: Slide[] = [
  {
    key: 'welcome',
    icon: 'leaf',
    iconTint: '#4ade80',
    title: 'Welcome to WellnessAI',
    body:
      'Six expert avatars — sleep, nutrition, fitness, mindfulness, skin and lab literacy — each grounded in current research. Ask anything in plain English and get a response that’s evidence-led, calm, and practical.',
  },
  {
    key: 'not-medical',
    icon: 'medkit',
    iconTint: '#f59e0b',
    title: 'Not medical advice',
    body:
      'WellnessAI is wellness education. It does not diagnose, prescribe, or replace a clinician. If you’re experiencing anything urgent, contact your doctor or local emergency services — the avatars will always redirect you to the right help.',
  },
  {
    key: 'safety-first',
    icon: 'shield-checkmark',
    iconTint: '#7c5cff',
    title: 'Safety and sources',
    body:
      'Every health claim is checked against real sources before it reaches you. Unverifiable responses are replaced with a safer fallback, and crisis indicators route you directly to help. You’re in control of your data at all times.',
  },
];

export function OnboardingScreen({ onFinish }: Props) {
  const insets = useSafeAreaInsets();
  const [index, setIndex] = useState(0);
  const listRef = useRef<FlatList<Slide>>(null);

  const handleScroll = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    const next = Math.round(e.nativeEvent.contentOffset.x / SCREEN_WIDTH);
    if (next !== index) setIndex(next);
  };

  const goToIndex = (i: number) => {
    listRef.current?.scrollToOffset({ offset: i * SCREEN_WIDTH, animated: true });
    setIndex(i);
  };

  const isLast = index === SLIDES.length - 1;

  return (
    <View style={[styles.container, { paddingTop: insets.top }]}>
      <View style={styles.skipRow}>
        {!isLast && (
          <Pressable
            onPress={onFinish}
            hitSlop={8}
            style={({ pressed }) => [styles.skip, pressed && { opacity: 0.6 }]}
          >
            <Text style={styles.skipText}>Skip</Text>
          </Pressable>
        )}
      </View>

      <FlatList
        ref={listRef}
        data={SLIDES}
        keyExtractor={(s) => s.key}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        onScroll={handleScroll}
        scrollEventThrottle={16}
        renderItem={({ item }) => <OnboardingSlide slide={item} />}
      />

      <View style={[styles.footer, { paddingBottom: insets.bottom + spacing.lg }]}>
        <View style={styles.dots}>
          {SLIDES.map((s, i) => (
            <Pressable
              key={s.key}
              onPress={() => goToIndex(i)}
              hitSlop={8}
              style={[styles.dot, i === index && styles.dotActive]}
            />
          ))}
        </View>
        <Pressable
          onPress={() => {
            if (isLast) onFinish();
            else goToIndex(index + 1);
          }}
          style={({ pressed }) => [styles.cta, pressed && { opacity: 0.85 }]}
        >
          <Text style={styles.ctaText}>
            {isLast ? 'Get started' : 'Next'}
          </Text>
          <Ionicons
            name={isLast ? 'arrow-forward' : 'chevron-forward'}
            size={18}
            color={colors.textPrimary}
          />
        </Pressable>
      </View>
      <StatusBar style="light" />
    </View>
  );
}

function OnboardingSlide({ slide }: { slide: Slide }) {
  return (
    <View style={[slideStyles.slide, { width: SCREEN_WIDTH }]}>
      <View style={[slideStyles.iconWrap, { borderColor: slide.iconTint + '55' }]}>
        <Ionicons name={slide.icon} size={48} color={slide.iconTint} />
      </View>
      <Text style={slideStyles.title}>{slide.title}</Text>
      <Text style={slideStyles.body}>{slide.body}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
  },
  skipRow: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    paddingHorizontal: spacing.md,
    paddingTop: spacing.sm,
    height: 44,
  },
  skip: {
    paddingVertical: spacing.xs,
    paddingHorizontal: spacing.sm,
  },
  skipText: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  footer: {
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
    gap: spacing.md,
  },
  dots: {
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    gap: 6,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: 'rgba(255,255,255,0.35)',
  },
  dotActive: {
    width: 18,
    backgroundColor: colors.primary,
  },
  cta: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.primary,
    paddingVertical: spacing.md,
    borderRadius: radius.pill,
    gap: spacing.sm,
  },
  ctaText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
    letterSpacing: 0.3,
  },
});

const slideStyles = StyleSheet.create({
  slide: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.xl,
  },
  iconWrap: {
    width: 120,
    height: 120,
    borderRadius: radius.pill,
    borderWidth: 2,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.xl,
    backgroundColor: colors.surface,
  },
  title: {
    color: colors.textPrimary,
    fontSize: 26,
    fontWeight: '800',
    textAlign: 'center',
    letterSpacing: -0.5,
    marginBottom: spacing.md,
  },
  body: {
    color: colors.textSecondary,
    fontSize: fontSize.md,
    lineHeight: 24,
    textAlign: 'center',
  },
});
