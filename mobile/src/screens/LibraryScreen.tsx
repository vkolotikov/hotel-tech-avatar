import { useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Image,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { Ionicons } from '@expo/vector-icons';
import { useTranslation } from 'react-i18next';
import { useAvatars } from '../hooks/useAvatars';
import { useConversations, useCreateConversation } from '../hooks/useConversations';
import { IntroVideoModal } from '../components/avatars/IntroVideoModal';
import { resolveAssetUrl } from '../api';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../theme';
import type { LibraryStackParamList } from '../navigation/AppNavigator';
import type { Avatar, Conversation } from '../types/models';

type Nav = NativeStackNavigationProp<LibraryStackParamList, 'LibraryHome'>;

export function LibraryScreen() {
  const navigation = useNavigation<Nav>();
  const { t } = useTranslation();
  const { data: avatars, isLoading, isError } = useAvatars();
  const { data: conversationsData } = useConversations();
  const createMutation = useCreateConversation();
  const [introAvatar, setIntroAvatar] = useState<Avatar | null>(null);

  const conversationsByAgent = useMemo(() => {
    const map = new Map<number, Conversation>();
    (conversationsData?.data ?? []).forEach((c) => {
      if (!map.has(c.agent_id)) map.set(c.agent_id, c);
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

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }
  if (isError || !avatars) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>{t('avatarHome.couldNotLoad')}</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <FlatList
        data={avatars}
        keyExtractor={(a) => a.slug}
        contentContainerStyle={styles.list}
        renderItem={({ item }) => (
          <LibraryCard
            avatar={item}
            onPressIntro={() => setIntroAvatar(item)}
            onPressChat={() => handleStart(item)}
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
          if (target) setTimeout(() => handleStart(target), 0);
        }}
        onClose={() => setIntroAvatar(null)}
      />
    </View>
  );
}

function LibraryCard({
  avatar,
  onPressIntro,
  onPressChat,
}: {
  avatar: Avatar;
  onPressIntro: () => void;
  onPressChat: () => void;
}) {
  const slug = avatar.slug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;
  const imageUrl = resolveAssetUrl(avatar.avatar_image_url);

  return (
    <View style={[styles.card, { borderColor: accent + '44' }]}>
      <View style={styles.cardHeader}>
        <View style={[styles.avatar, { borderColor: accent }]}>
          {imageUrl ? (
            <Image source={{ uri: imageUrl }} style={styles.avatarImage} />
          ) : (
            <View style={[styles.avatarDot, { backgroundColor: accent }]} />
          )}
        </View>
        <View style={styles.cardHeaderText}>
          <Text style={[styles.role, { color: accent }]} numberOfLines={1}>
            {avatar.role}
          </Text>
          <Text style={styles.name} numberOfLines={1}>
            {avatar.name}
          </Text>
        </View>
      </View>
      {avatar.description && (
        <Text style={styles.description} numberOfLines={3}>
          {avatar.description}
        </Text>
      )}
      <View style={styles.cardActions}>
        <Pressable
          onPress={onPressChat}
          style={({ pressed }) => [
            styles.primaryBtn,
            { backgroundColor: accent },
            pressed && { opacity: 0.85 },
          ]}
        >
          <Ionicons name="chatbubble-outline" size={16} color={colors.textPrimary} />
          <Text style={styles.primaryBtnText}>Start chat</Text>
        </Pressable>
        {avatar.intro_video_url && (
          <Pressable
            onPress={onPressIntro}
            style={({ pressed }) => [
              styles.secondaryBtn,
              { borderColor: accent },
              pressed && { opacity: 0.85 },
            ]}
          >
            <Ionicons name="play-circle-outline" size={16} color={accent} />
            <Text style={[styles.secondaryBtnText, { color: accent }]}>Intro</Text>
          </Pressable>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.background,
  },
  errorText: { color: colors.textSecondary, fontSize: fontSize.md },
  list: { padding: spacing.md, paddingBottom: spacing.xl },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    padding: spacing.md,
    marginBottom: spacing.sm + 2,
  },
  cardHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    marginBottom: spacing.sm,
  },
  avatar: {
    width: 56,
    height: 56,
    borderRadius: radius.pill,
    borderWidth: 2,
    padding: 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarImage: {
    width: 48,
    height: 48,
    borderRadius: radius.pill,
    backgroundColor: colors.surfaceElevated,
  },
  avatarDot: {
    width: 48,
    height: 48,
    borderRadius: radius.pill,
  },
  cardHeaderText: { flex: 1 },
  role: {
    fontSize: fontSize.xs,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 1,
    marginBottom: 2,
  },
  name: {
    color: colors.textPrimary,
    fontSize: fontSize.lg,
    fontWeight: '700',
    letterSpacing: -0.3,
  },
  description: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
    lineHeight: 20,
    marginBottom: spacing.md,
  },
  cardActions: {
    flexDirection: 'row',
    gap: spacing.sm,
    flexWrap: 'wrap',
  },
  primaryBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.md,
    borderRadius: radius.pill,
  },
  primaryBtnText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontWeight: '700',
  },
  secondaryBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.md,
    borderRadius: radius.pill,
    borderWidth: 1,
    backgroundColor: 'rgba(20,26,38,0.6)',
  },
  secondaryBtnText: {
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
});
