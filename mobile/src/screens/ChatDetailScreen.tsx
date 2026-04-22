import { useEffect, useRef } from 'react';
import {
  ActivityIndicator,
  FlatList,
  ImageBackground,
  KeyboardAvoidingView,
  Platform,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useRoute, RouteProp } from '@react-navigation/native';
import { useHeaderHeight } from '@react-navigation/elements';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useMessages } from '../hooks/useMessages';
import { useChatStream } from '../hooks/useChatStream';
import { MessageBubble } from '../components/chat/MessageBubble';
import { MessageInput } from '../components/chat/MessageInput';
import { TypingIndicator } from '../components/chat/TypingIndicator';
import { StreamingMessage } from '../components/chat/StreamingMessage';
import { resolveAssetUrl } from '../api';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';

type Route = RouteProp<RootStackParamList, 'ChatDetail'>;

const HERO_FLEX = 0.55;

export function ChatDetailScreen() {
  const route = useRoute<Route>();
  const { conversationId, avatarSlug, avatarName, avatarImageUrl } = route.params;
  const listRef = useRef<FlatList>(null);
  const headerHeight = useHeaderHeight();
  const insets = useSafeAreaInsets();

  const heroUri = resolveAssetUrl(avatarImageUrl);
  const accent =
    avatarSlug in avatarColors ? avatarColors[avatarSlug as AvatarSlug] : colors.primary;

  const { data, isLoading, isError, refetch } = useMessages(conversationId);
  const stream = useChatStream(conversationId);

  useEffect(() => {
    if (data?.length) {
      setTimeout(() => listRef.current?.scrollToEnd({ animated: true }), 50);
    }
  }, [data?.length]);

  const handleSend = (text: string) => {
    stream.send(text);
  };

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.primary} />
      </View>
    );
  }

  if (isError) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>Couldn't load messages</Text>
        <Text style={styles.retryText} onPress={() => refetch()}>Tap to retry</Text>
      </View>
    );
  }

  const messages = data ?? [];

  return (
    <View style={styles.container}>
      <View style={[styles.hero, { flex: HERO_FLEX }]}>
        {heroUri ? (
          <ImageBackground
            source={{ uri: heroUri }}
            style={styles.heroImage}
            resizeMode="cover"
          >
            <View style={[styles.heroFadeSoft, { paddingTop: headerHeight }]} />
            <View style={styles.heroFadeMid} />
            <View style={styles.heroFadeEdge} />
          </ImageBackground>
        ) : (
          <View style={[styles.heroImage, { backgroundColor: accent, opacity: 0.3 }]} />
        )}
      </View>

      <View style={[styles.namePill, { borderColor: accent }]}>
        <Text style={styles.nameText}>{avatarName}</Text>
      </View>

      <KeyboardAvoidingView
        style={styles.chatWrap}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        keyboardVerticalOffset={Platform.OS === 'ios' ? headerHeight : 0}
      >
        <FlatList
          ref={listRef}
          data={messages}
          keyExtractor={(m) => String(m.id)}
          renderItem={({ item }) => (
            <MessageBubble message={item} avatarSlug={avatarSlug} />
          )}
          contentContainerStyle={[styles.list, { paddingBottom: spacing.md }]}
          ListFooterComponent={
            stream.isPending ? (
              stream.streamingText ? (
                <StreamingMessage text={stream.streamingText} avatarSlug={avatarSlug} />
              ) : (
                <TypingIndicator avatarName={avatarName} />
              )
            ) : null
          }
          onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: true })}
        />
        <MessageInput
          conversationId={conversationId}
          onSend={handleSend}
          disabled={stream.isPending}
        />
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  hero: { width: '100%' },
  heroImage: { flex: 1, width: '100%', height: '100%' },
  heroFadeSoft: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 120,
    backgroundColor: 'rgba(11,15,23,0.35)',
  },
  heroFadeMid: {
    position: 'absolute',
    bottom: 40,
    left: 0,
    right: 0,
    height: 80,
    backgroundColor: 'rgba(11,15,23,0.55)',
  },
  heroFadeEdge: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    height: 40,
    backgroundColor: colors.background,
  },
  namePill: {
    position: 'absolute',
    top: '55%',
    alignSelf: 'center',
    transform: [{ translateY: -18 }],
    paddingVertical: spacing.xs,
    paddingHorizontal: spacing.md,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(20,26,38,0.85)',
    borderWidth: 1,
  },
  nameText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
  },
  chatWrap: {
    flex: 1 - HERO_FLEX,
    backgroundColor: colors.background,
  },
  list: { padding: spacing.md, paddingTop: spacing.lg },
  centered: {
    flex: 1,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
  },
  errorText: { color: colors.textSecondary, fontSize: fontSize.md, marginBottom: spacing.sm },
  retryText: { color: colors.primary, fontSize: fontSize.md, fontWeight: '600' },
});
