import { useEffect, useRef } from 'react';
import {
  ActivityIndicator,
  FlatList,
  KeyboardAvoidingView,
  Platform,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useRoute, RouteProp } from '@react-navigation/native';
import { useMessages } from '../hooks/useMessages';
import { useChatStream } from '../hooks/useChatStream';
import { MessageBubble } from '../components/chat/MessageBubble';
import { MessageInput } from '../components/chat/MessageInput';
import { TypingIndicator } from '../components/chat/TypingIndicator';
import { StreamingMessage } from '../components/chat/StreamingMessage';
import { colors, spacing, fontSize } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';

type Route = RouteProp<RootStackParamList, 'ChatDetail'>;

export function ChatDetailScreen() {
  const route = useRoute<Route>();
  const { conversationId, avatarSlug, avatarName } = route.params;
  const listRef = useRef<FlatList>(null);

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
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      keyboardVerticalOffset={Platform.OS === 'ios' ? 64 : 0}
    >
      <FlatList
        ref={listRef}
        data={messages}
        keyExtractor={(m) => String(m.id)}
        renderItem={({ item }) => (
          <MessageBubble message={item} avatarSlug={avatarSlug} />
        )}
        contentContainerStyle={styles.list}
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
      <MessageInput onSend={handleSend} disabled={stream.isPending} />
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  list: { padding: spacing.md },
  centered: {
    flex: 1,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
  },
  errorText: { color: colors.textSecondary, fontSize: fontSize.md, marginBottom: spacing.sm },
  retryText: { color: colors.primary, fontSize: fontSize.md, fontWeight: '600' },
});
