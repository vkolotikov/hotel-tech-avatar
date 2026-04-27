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
import { useTranslation } from 'react-i18next';
import { useAvatars } from '../hooks/useAvatars';
import { useConversations, useCreateConversation } from '../hooks/useConversations';
import { IntroVideoModal } from '../components/avatars/IntroVideoModal';
import { resolveAssetUrl } from '../api';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../theme';
import type { HomeStackParamList } from '../navigation/AppNavigator';
import type { Avatar, Conversation } from '../types/models';

type Nav = NativeStackNavigationProp<HomeStackParamList, 'AvatarHome'>;

const { width: SCREEN_WIDTH } = Dimensions.get('window');

export function AvatarHomeScreen() {
  const navigation = useNavigation<Nav>();
  const insets = useSafeAreaInsets();
  const { t } = useTranslation();
  const { data: avatars, isLoading, isError } = useAvatars();
  const { data: conversationsData } = useConversations();
  const createMutation = useCreateConversation();
  const [activeIndex, setActiveIndex] = useState(0);
  const [introAvatar, setIntroAvatar] = useState<Avatar | null>(null);
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

  const handleStart = async (avatar: Avatar) => {
    const existing = conversationsByAgent.get(avatar.id);
    if (existing) {
      navigation.navigate('ChatDetail', {
        conversationId: existing.id,
        avatarSlug: avatar.slug,
        avatarName: avatar.name,
        avatarImageUrl: avatar.avatar_image_url,
        promptSuggestions: avatar.prompt_suggestions,
      });
      return;
    }
    await createAndOpen(avatar);
  };

  // Create a fresh conversation regardless of any existing one. Used by
  // the dedicated "New chat" button so the user can deliberately start
  // over (e.g. when their last topic with this avatar is finished and
  // they want a clean thread, or when revisiting after profile changes).
  const createAndOpen = async (avatar: Avatar) => {
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
        promptSuggestions: avatar.prompt_suggestions,
      });
    } catch (err) {
      Alert.alert(t('avatarHome.couldNotStart'), (err as Error).message ?? '');
    }
  };

  const handleNewChat = (avatar: Avatar) => {
    // If there's no prior conversation, "New chat" is just "Start chat" —
    // skip the confirm dialog and go straight in.
    if (!conversationsByAgent.has(avatar.id)) {
      void createAndOpen(avatar);
      return;
    }
    Alert.alert(
      t('avatarHome.newChatTitle'),
      t('avatarHome.newChatBody', { name: avatar.name }),
      [
        { text: t('common.cancel'), style: 'cancel' },
        { text: t('avatarHome.newChat'), onPress: () => void createAndOpen(avatar) },
      ],
    );
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
            onNewChat={() => handleNewChat(item)}
            onPlayIntro={() => setIntroAvatar(item)}
            pending={createMutation.isPending}
          />
        )}
      />

      <IntroVideoModal
        visible={introAvatar !== null}
        videoUrl={introAvatar?.intro_video_url}
        avatarName={introAvatar?.name ?? ''}
        avatarSlug={introAvatar?.slug ?? ''}
        onStartChat={() => {
          const target = introAvatar;
          setIntroAvatar(null);
          if (target) {
            // Defer so the modal is fully dismissed before navigation animates.
            setTimeout(() => handleStart(target), 0);
          }
        }}
        onClose={() => setIntroAvatar(null)}
      />

      {/* Top bar — brand mark floating over the portrait.
          History + sign-out moved to bottom tabs + Settings. */}
      <View style={[styles.topBar, { paddingTop: insets.top + spacing.sm }]} pointerEvents="box-none">
        <Text style={styles.brand}>Hexalife</Text>
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
  onNewChat: () => void;
  onPlayIntro: () => void;
  pending: boolean;
};

function AvatarPage({ avatar, hasExistingChat, onStart, onNewChat, onPlayIntro, pending }: PageProps) {
  const insets = useSafeAreaInsets();
  const { t } = useTranslation();
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

      <View
        style={[
          pageStyles.cardWrap,
          { paddingBottom: insets.bottom + 40, paddingHorizontal: spacing.md },
        ]}
        pointerEvents="box-none"
      >
        <View style={[pageStyles.card, { borderColor: accent + '55' }]}>
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

          <View style={pageStyles.ctaRow}>
            {/* Primary CTA: continue if there's a previous conversation,
                otherwise start the first one. The label changes but the
                handler is the same — handleStart picks the right path. */}
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
                {pending
                  ? t('avatarHome.starting')
                  : hasExistingChat
                  ? t('avatarHome.continueChat')
                  : t('avatarHome.startChat')}
              </Text>
            </Pressable>

            {/* Secondary CTA: only shown when there IS an existing chat,
                because otherwise "Continue" + "New chat" both mean the
                same thing — confusing. With no prior chat, the primary
                button is already labelled "Start chat". */}
            {hasExistingChat && (
              <Pressable
                onPress={onNewChat}
                disabled={pending}
                style={({ pressed }) => [
                  pageStyles.secondaryCta,
                  { borderColor: accent },
                  (pressed || pending) && pageStyles.ctaPressed,
                ]}
                accessibilityLabel={t('avatarHome.newChat')}
              >
                <Text style={[pageStyles.secondaryCtaText, { color: accent }]}>
                  {t('avatarHome.newChat')}
                </Text>
              </Pressable>
            )}

            {avatar.intro_video_url && (
              <Pressable
                onPress={onPlayIntro}
                style={({ pressed }) => [
                  pageStyles.playPill,
                  { borderColor: accent },
                  pressed && pageStyles.ctaPressed,
                ]}
                accessibilityLabel={t('avatarHome.watchDemo')}
              >
                <Text style={[pageStyles.playPillText, { color: accent }]}>
                  ▶ {t('avatarHome.watchDemo')}
                </Text>
              </Pressable>
            )}
          </View>
        </View>
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
  cardWrap: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
  },
  card: {
    backgroundColor: 'rgba(11,15,23,0.82)',
    borderWidth: 1,
    borderRadius: radius.lg,
    padding: spacing.lg,
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
  ctaRow: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: spacing.sm,
  },
  cta: {
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
  // Bordered (outlined) variant for the "New chat" secondary action —
  // visually subordinate to the filled primary CTA but more prominent
  // than the demo pill. Sits in the same row, inherits the avatar's
  // accent for the border + text colour.
  secondaryCta: {
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.pill,
    borderWidth: 1,
    backgroundColor: 'rgba(11,15,23,0.55)',
  },
  secondaryCtaText: {
    fontSize: fontSize.md,
    fontWeight: '700',
    letterSpacing: 0.3,
  },
  playPill: {
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.md,
    borderRadius: radius.pill,
    borderWidth: 1,
    backgroundColor: 'rgba(20,26,38,0.9)',
  },
  playPillText: {
    fontSize: fontSize.sm,
    fontWeight: '600',
    letterSpacing: 0.2,
  },
});
