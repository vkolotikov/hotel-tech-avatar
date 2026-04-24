import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  ImageBackground,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useRoute, RouteProp, useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { useMessages } from '../hooks/useMessages';
import { useChatStream } from '../hooks/useChatStream';
import { useAvatars } from '../hooks/useAvatars';
import { useCreateConversation } from '../hooks/useConversations';
import { ApiError } from '../api';
import { PaywallScreen } from './PaywallScreen';
import { LiveAvatarModal } from '../components/chat/LiveAvatarModal';
import { MessageBubble } from '../components/chat/MessageBubble';
import { MessageInput } from '../components/chat/MessageInput';
import { TypingIndicator } from '../components/chat/TypingIndicator';
import { StreamingMessage } from '../components/chat/StreamingMessage';
import { StarterPrompts } from '../components/chat/StarterPrompts';
import { SuggestionChips } from '../components/chat/SuggestionChips';
import { resolveAssetUrl } from '../api';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';

type Route = RouteProp<RootStackParamList, 'ChatDetail'>;
type Nav = NativeStackNavigationProp<RootStackParamList, 'ChatDetail'>;

const HEADER_HEIGHT = 56;

export function ChatDetailScreen() {
  const route = useRoute<Route>();
  const navigation = useNavigation<Nav>();
  const insets = useSafeAreaInsets();
  const {
    conversationId,
    avatarSlug,
    avatarName,
    avatarImageUrl,
    promptSuggestions,
  } = route.params;
  const listRef = useRef<FlatList>(null);

  const heroUri = resolveAssetUrl(avatarImageUrl);
  const accent =
    avatarSlug in avatarColors ? avatarColors[avatarSlug as AvatarSlug] : colors.primary;

  const { data, isLoading, isError, refetch } = useMessages(conversationId);
  const stream = useChatStream(conversationId);
  const { data: avatars } = useAvatars();
  const createConversation = useCreateConversation();

  const [paywallOpen, setPaywallOpen] = useState(false);
  const [paywallReason, setPaywallReason] = useState<string | null>(null);
  const [videoModeOpen, setVideoModeOpen] = useState(false);

  // When the backend rejects a send with 402 (free-tier daily limit),
  // open the paywall automatically with the exact message the backend
  // returned — "You've used your 10 free messages for today." — so the
  // user doesn't have to guess why the send didn't go through.
  useEffect(() => {
    const err = stream.error;
    if (err instanceof ApiError && err.status === 402) {
      const body = (err.body ?? {}) as {
        message?: string;
        used_today?: number;
        daily_limit?: number;
      };
      setPaywallReason(
        body.message ??
          `You've used your ${body.daily_limit} free messages for today. Upgrade for unlimited.`,
      );
      setPaywallOpen(true);
    }
  }, [stream.error]);

  const showPaywallDismiss = useMemo(() => {
    return () => {
      setPaywallOpen(false);
      setPaywallReason(null);
    };
  }, []);

  const handleNewChat = () => {
    Alert.alert(
      'Start a new chat?',
      `This keeps the current conversation with ${avatarName} in your history and opens a fresh one.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'New chat',
          onPress: async () => {
            const agent = avatars?.find((a) => a.slug === avatarSlug);
            if (!agent) {
              Alert.alert('Could not start new chat', 'Avatar not available.');
              return;
            }
            try {
              const conversation = await createConversation.mutateAsync({
                agentId: agent.id,
                title: null,
              });
              // Replace current ChatDetail rather than stacking another —
              // the old conversation is already saved, and back-nav from
              // the new one should go to the screen the user came from
              // (AvatarHome / Library / History), not the old chat.
              navigation.replace('ChatDetail', {
                conversationId: conversation.id,
                avatarSlug: agent.slug,
                avatarName: agent.name,
                avatarImageUrl: agent.avatar_image_url,
                promptSuggestions: agent.prompt_suggestions,
              });
            } catch (err) {
              Alert.alert('Could not start new chat', (err as Error).message);
            }
          },
        },
      ],
    );
  };

  useEffect(() => {
    if (data?.length) {
      setTimeout(() => listRef.current?.scrollToEnd({ animated: true }), 50);
    }
  }, [data?.length]);

  const handleSend = (
    text: string,
    opts?: { voice?: boolean; attachmentIds?: number[] },
  ): Promise<boolean> => {
    return stream.send(text, {
      speak: opts?.voice === true,
      attachmentIds: opts?.attachmentIds,
    });
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

  const TopBar = (
    <View
      style={[
        styles.topBar,
        { paddingTop: insets.top, height: insets.top + HEADER_HEIGHT },
      ]}
      pointerEvents="box-none"
    >
      <Pressable
        onPress={() => navigation.goBack()}
        accessibilityLabel="Go back"
        hitSlop={8}
        style={({ pressed }) => [
          styles.backButton,
          pressed && { opacity: 0.7 },
        ]}
      >
        <Ionicons name="chevron-back" size={24} color={colors.textPrimary} />
      </Pressable>
      <Text style={styles.headerTitle} numberOfLines={1}>
        {avatarName}
      </Text>
      <View style={styles.topBarActions}>
        <Pressable
          onPress={() => setVideoModeOpen(true)}
          accessibilityLabel={`Start video call with ${avatarName}`}
          hitSlop={8}
          style={({ pressed }) => [
            styles.backButton,
            pressed && { opacity: 0.7 },
          ]}
        >
          <Ionicons name="videocam-outline" size={20} color={colors.textPrimary} />
        </Pressable>
        <Pressable
          onPress={handleNewChat}
          accessibilityLabel={`Start a new chat with ${avatarName}`}
          hitSlop={8}
          style={({ pressed }) => [
            styles.backButton,
            (pressed || createConversation.isPending) && { opacity: 0.7 },
          ]}
          disabled={createConversation.isPending}
        >
          {createConversation.isPending ? (
            <ActivityIndicator size="small" color={colors.textPrimary} />
          ) : (
            <Ionicons name="create-outline" size={20} color={colors.textPrimary} />
          )}
        </Pressable>
      </View>
    </View>
  );

  const contentPaddingTop = insets.top + HEADER_HEIGHT + spacing.sm;

  if (isLoading) {
    return (
      <View style={styles.container}>
        {Background}
        {TopBar}
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
        {TopBar}
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
      {TopBar}

      <KeyboardAvoidingView
        style={styles.overlay}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        keyboardVerticalOffset={Platform.OS === 'ios' ? insets.top + HEADER_HEIGHT : 0}
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
            { paddingTop: contentPaddingTop },
          ]}
          ListFooterComponent={
            stream.isPending ? (
              stream.streamingText ? (
                <StreamingMessage text={stream.streamingText} avatarSlug={avatarSlug} />
              ) : (
                <TypingIndicator avatarName={avatarName} />
              )
            ) : messages.length === 0 ? (
              <StarterPrompts
                avatarSlug={avatarSlug}
                avatarName={avatarName}
                suggestions={promptSuggestions}
                onPick={(text) => handleSend(text)}
              />
            ) : (() => {
                const last = messages[messages.length - 1];
                const chips = last?.role === 'agent' ? last.ui_json?.suggestions ?? [] : [];
                return chips.length > 0 ? (
                  <SuggestionChips
                    suggestions={chips}
                    avatarSlug={avatarSlug}
                    onPick={(text) => handleSend(text)}
                  />
                ) : null;
              })()
          }
          onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: true })}
          showsVerticalScrollIndicator={false}
          keyboardDismissMode="on-drag"
          keyboardShouldPersistTaps="handled"
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
        {stream.error && (
          <View style={styles.errorBanner}>
            <Ionicons name="alert-circle" size={18} color={colors.danger} />
            <Text style={styles.errorBannerText} numberOfLines={2}>
              {stream.error.message}
            </Text>
          </View>
        )}
        <MessageInput
          conversationId={conversationId}
          accent={accent}
          onSend={handleSend}
          disabled={stream.isPending}
        />
      </KeyboardAvoidingView>

      <PaywallScreen
        visible={paywallOpen}
        reason={paywallReason}
        onClose={showPaywallDismiss}
      />

      <LiveAvatarModal
        visible={videoModeOpen}
        avatarSlug={avatarSlug}
        avatarName={avatarName}
        onClose={() => setVideoModeOpen(false)}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  overlay: { flex: 1 },
  topBar: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.md,
    zIndex: 10,
  },
  backButton: {
    width: 40,
    height: 40,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(20,26,38,0.55)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  headerTitle: {
    flex: 1,
    textAlign: 'center',
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    textShadowColor: 'rgba(0,0,0,0.75)',
    textShadowRadius: 8,
  },
  topBarActions: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
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
  errorBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'stretch',
    marginHorizontal: spacing.md,
    marginBottom: spacing.sm,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.danger + '55',
    backgroundColor: 'rgba(239,68,68,0.12)',
    gap: spacing.sm,
  },
  errorBannerText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    flex: 1,
  },
});
