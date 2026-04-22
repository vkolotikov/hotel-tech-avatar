import { useEffect, useRef } from 'react';
import {
  ActivityIndicator,
  FlatList,
  ImageBackground,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useRoute, RouteProp } from '@react-navigation/native';
import { useHeaderHeight } from '@react-navigation/elements';
import { useMessages } from '../hooks/useMessages';
import { useChatStream } from '../hooks/useChatStream';
import { MessageBubble } from '../components/chat/MessageBubble';
import { MessageInput } from '../components/chat/MessageInput';
import { TypingIndicator } from '../components/chat/TypingIndicator';
import { StreamingMessage } from '../components/chat/StreamingMessage';
import { resolveAssetUrl } from '../api';
import { colors, spacing, fontSize, avatarColors, AvatarSlug } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';

type Route = RouteProp<RootStackParamList, 'ChatDetail'>;

export function ChatDetailScreen() {
  const route = useRoute<Route>();
  const { conversationId, avatarSlug, avatarName, avatarImageUrl } = route.params;
  const listRef = useRef<FlatList>(null);
  const headerHeight = useHeaderHeight();

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

  const handleSend = (text: string, opts?: { voice?: boolean }) => {
    stream.send(text, { speak: opts?.voice === true });
  };

  const Background = heroUri
    ? (
        <ImageBackground
          source={{ uri: heroUri }}
          style={StyleSheet.absoluteFill}
          resizeMode="cover"
        />
      )
    : (
        <View
          style={[StyleSheet.absoluteFill, { backgroundColor: accent, opacity: 0.25 }]}
        />
      );

  if (isLoading) {
    return (
      <View style={styles.container}>
        {Background}
        <View style={styles.centerOverlay}>
          <ActivityIndicator color={colors.textPrimary} size="large" />
        </View>
      </View>
    );
  }

  if (isError) {
    return (
      <View style={styles.container}>
        {Background}
        <View style={styles.centerOverlay}>
          <Text style={styles.errorText}>Couldn't load messages</Text>
          <Text style={styles.retryText} onPress={() => refetch()}>Tap to retry</Text>
        </View>
      </View>
    );
  }

  const messages = data ?? [];

  return (
    <View style={styles.container}>
      {Background}

      {/* Top dim so back arrow and header name are readable */}
      <View style={styles.topDim} pointerEvents="none" />

      {/* Bottom dim + gradient-ish fade for chat legibility */}
      <View style={styles.bottomDimLight} pointerEvents="none" />
      <View style={styles.bottomDimHeavy} pointerEvents="none" />

      <KeyboardAvoidingView
        style={styles.overlay}
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
          contentContainerStyle={[
            styles.list,
            { paddingTop: headerHeight + spacing.md },
          ]}
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
          showsVerticalScrollIndicator={false}
        />
        {stream.isSpeaking && (
          <Pressable
            onPress={stream.stopSpeaking}
            style={[styles.speakingPill, { borderColor: accent }]}
          >
            <View style={[styles.speakingDot, { backgroundColor: accent }]} />
            <Text style={styles.speakingText}>{avatarName} is speaking · tap to stop</Text>
          </Pressable>
        )}
        <MessageInput
          conversationId={conversationId}
          accent={accent}
          onSend={handleSend}
          disabled={stream.isPending}
        />
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  overlay: { flex: 1 },
  topDim: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 140,
    backgroundColor: 'rgba(11,15,23,0.45)',
  },
  bottomDimLight: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    height: '60%',
    backgroundColor: 'rgba(11,15,23,0.45)',
  },
  bottomDimHeavy: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    height: '30%',
    backgroundColor: 'rgba(11,15,23,0.35)',
  },
  list: {
    flexGrow: 1,
    justifyContent: 'flex-end',
    paddingHorizontal: spacing.md,
    paddingBottom: spacing.md,
  },
  centerOverlay: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(11,15,23,0.5)',
  },
  errorText: { color: colors.textPrimary, fontSize: fontSize.md, marginBottom: spacing.sm },
  retryText: { color: colors.primary, fontSize: fontSize.md, fontWeight: '600' },
  speakingPill: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'center',
    marginBottom: spacing.sm,
    paddingVertical: spacing.xs + 2,
    paddingHorizontal: spacing.md,
    borderRadius: 999,
    borderWidth: 1,
    backgroundColor: 'rgba(20,26,38,0.85)',
  },
  speakingDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    marginRight: spacing.sm,
  },
  speakingText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontWeight: '500',
  },
});
