import { useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Dimensions,
  FlatList,
  ImageBackground,
  NativeScrollEvent,
  NativeSyntheticEvent,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAvatars } from '../hooks/useAvatars';
import { useConversations, useCreateConversation } from '../hooks/useConversations';
import { resolveAssetUrl } from '../api';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';
import type { Avatar, Conversation } from '../types/models';

type Nav = NativeStackNavigationProp<RootStackParamList, 'AvatarHome'>;

const { width: SCREEN_WIDTH } = Dimensions.get('window');

export function AvatarHomeScreen() {
  const navigation = useNavigation<Nav>();
  const insets = useSafeAreaInsets();
  const { data: avatars, isLoading, isError } = useAvatars();
  const { data: conversationsData } = useConversations();
  const createMutation = useCreateConversation();
  const [activeIndex, setActiveIndex] = useState(0);
  const listRef = useRef<FlatList<Avatar>>(null);

  const conversationsByAgent = useMemo(() => {
    const map = new Map<number, Conversation>();
    (conversationsData?.data ?? []).forEach((c) => {
      const existing = map.get(c.agent_id);
      if (!existing) {
        map.set(c.agent_id, c);
      }
    });
    return map;
  }, [conversationsData]);

  const handleOpenHistory = () => navigation.navigate('ConversationList');

  const handleStart = async (avatar: Avatar) => {
    const existing = conversationsByAgent.get(avatar.id);
    if (existing) {
      navigation.navigate('ChatDetail', {
        conversationId: existing.id,
        avatarSlug: avatar.slug,
        avatarName: avatar.name,
        avatarImageUrl: avatar.avatar_image_url,
      });
      return;
    }
    try {
      const conversation = await createMutation.mutateAsync({
        agentId: avatar.id,
        title: null,
      });
      navigation.navigate('ChatDetail', {
        conversationId: conversation.id,
        avatarSlug: avatar.slug,
        avatarName: avatar.name,
        avatarImageUrl: avatar.avatar_image_url,
      });
    } catch (err) {
      Alert.alert('Could not start chat', (err as Error).message ?? 'Unknown error');
    }
  };

  const onScroll = (e: NativeSyntheticEvent<NativeScrollEvent>) => {
    const idx = Math.round(e.nativeEvent.contentOffset.x / SCREEN_WIDTH);
    if (idx !== activeIndex) setActiveIndex(idx);
  };

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }

  if (isError || !avatars || avatars.length === 0) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>Couldn't load avatars</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <FlatList
        ref={listRef}
        data={avatars}
        keyExtractor={(a) => a.slug}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        onScroll={onScroll}
        scrollEventThrottle={16}
        renderItem={({ item }) => (
          <AvatarPage
            avatar={item}
            hasExistingChat={conversationsByAgent.has(item.id)}
            onStart={() => handleStart(item)}
            pending={createMutation.isPending}
          />
        )}
      />

      {/* Top bar — history + title floating over the portrait */}
      <View style={[styles.topBar, { paddingTop: insets.top + spacing.sm }]} pointerEvents="box-none">
        <Text style={styles.brand}>WellnessAI</Text>
        <Pressable
          onPress={handleOpenHistory}
          style={({ pressed }) => [styles.historyButton, pressed && styles.pressed]}
          accessibilityLabel="Conversation history"
        >
          <Text style={styles.historyIcon}>☰</Text>
        </Pressable>
      </View>

      {/* Pagination dots */}
      <View style={[styles.dots, { bottom: insets.bottom + spacing.sm }]} pointerEvents="none">
        {avatars.map((a, i) => (
          <View
            key={a.slug}
            style={[
              styles.dot,
              i === activeIndex && styles.dotActive,
            ]}
          />
        ))}
      </View>
    </View>
  );
}

type PageProps = {
  avatar: Avatar;
  hasExistingChat: boolean;
  onStart: () => void;
  pending: boolean;
};

function AvatarPage({ avatar, hasExistingChat, onStart, pending }: PageProps) {
  const insets = useSafeAreaInsets();
  const slug = avatar.slug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;
  const imageUrl = resolveAssetUrl(avatar.avatar_image_url);

  return (
    <View style={{ width: SCREEN_WIDTH, height: '100%' }}>
      {imageUrl ? (
        <ImageBackground
          source={{ uri: imageUrl }}
          style={StyleSheet.absoluteFill}
          resizeMode="cover"
        />
      ) : (
        <View style={[StyleSheet.absoluteFill, { backgroundColor: accent, opacity: 0.3 }]} />
      )}

      {/* Gradient-ish legibility overlays */}
      <View style={pageStyles.topFade} pointerEvents="none" />
      <View style={pageStyles.bottomFadeLight} pointerEvents="none" />
      <View style={pageStyles.bottomFadeHeavy} pointerEvents="none" />

      <View
        style={[
          pageStyles.content,
          { paddingBottom: insets.bottom + 80 }, // leaves room for dots
        ]}
      >
        <View style={[pageStyles.accentBar, { backgroundColor: accent }]} />
        <Text style={[pageStyles.role, { color: accent }]} numberOfLines={1}>
          {avatar.role}
        </Text>
        <Text style={pageStyles.name} numberOfLines={2}>
          {avatar.name}
        </Text>
        {avatar.description && (
          <Text style={pageStyles.description} numberOfLines={4}>
            {avatar.description}
          </Text>
        )}

        <Pressable
          onPress={onStart}
          disabled={pending}
          style={({ pressed }) => [
            pageStyles.cta,
            { backgroundColor: accent },
            (pressed || pending) && pageStyles.ctaPressed,
          ]}
        >
          <Text style={pageStyles.ctaText}>
            {pending ? 'Starting…' : hasExistingChat ? 'Continue chat' : 'Start chat'}
          </Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  centered: {
    flex: 1,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
  },
  errorText: { color: colors.textSecondary, fontSize: fontSize.md },
  topBar: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    paddingBottom: spacing.sm,
  },
  brand: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
    letterSpacing: 0.3,
    textShadowColor: 'rgba(0,0,0,0.6)',
    textShadowRadius: 6,
  },
  historyButton: {
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(20,26,38,0.55)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  historyIcon: {
    color: colors.textPrimary,
    fontSize: 20,
  },
  pressed: { opacity: 0.7 },
  dots: {
    position: 'absolute',
    left: 0,
    right: 0,
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
    backgroundColor: colors.textPrimary,
  },
});

const pageStyles = StyleSheet.create({
  topFade: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 140,
    backgroundColor: 'rgba(11,15,23,0.45)',
  },
  bottomFadeLight: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    height: '55%',
    backgroundColor: 'rgba(11,15,23,0.55)',
  },
  bottomFadeHeavy: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    height: '28%',
    backgroundColor: 'rgba(11,15,23,0.4)',
  },
  content: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    paddingHorizontal: spacing.lg,
  },
  accentBar: {
    width: 40,
    height: 3,
    borderRadius: 2,
    marginBottom: spacing.sm,
  },
  role: {
    fontSize: fontSize.sm,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 1.2,
    marginBottom: spacing.xs,
  },
  name: {
    color: colors.textPrimary,
    fontSize: 38,
    fontWeight: '800',
    letterSpacing: -1,
    lineHeight: 42,
    marginBottom: spacing.sm,
  },
  description: {
    color: colors.textSecondary,
    fontSize: fontSize.md,
    lineHeight: 22,
    marginBottom: spacing.lg,
  },
  cta: {
    alignSelf: 'flex-start',
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.xl,
    borderRadius: radius.pill,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 12,
    elevation: 6,
  },
  ctaPressed: { opacity: 0.85, transform: [{ scale: 0.98 }] },
  ctaText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
    letterSpacing: 0.3,
  },
});
