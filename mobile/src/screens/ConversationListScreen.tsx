import { ActivityIndicator, Alert, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useConversations, useDeleteConversation } from '../hooks/useConversations';
import { ConversationCard } from '../components/conversations/ConversationCard';
import { EmptyState } from '../components/conversations/EmptyState';
import { colors, spacing, radius, fontSize } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';
import type { Conversation } from '../types/models';

type Nav = NativeStackNavigationProp<RootStackParamList, 'ConversationList'>;

export function ConversationListScreen() {
  const navigation = useNavigation<Nav>();
  const insets = useSafeAreaInsets();
  const { data, isLoading, isError, refetch, isRefetching } = useConversations();
  const deleteMutation = useDeleteConversation();

  const handleOpen = (conversation: Conversation) => {
    navigation.navigate('ChatDetail', {
      conversationId: conversation.id,
      avatarSlug: conversation.agent?.slug ?? 'nora',
      avatarName: conversation.agent?.name ?? 'Agent',
      avatarImageUrl: conversation.agent?.avatar_image_url,
    });
  };

  const handleLongPress = (conversation: Conversation) => {
    const label = conversation.title ?? 'this conversation';
    Alert.alert(
      'Delete conversation',
      `Delete "${label}"? This cannot be undone.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Delete',
          style: 'destructive',
          onPress: async () => {
            try {
              await deleteMutation.mutateAsync(conversation.id);
            } catch (err) {
              Alert.alert('Delete failed', (err as Error).message);
            }
          },
        },
      ],
    );
  };

  const handleNewChat = () => navigation.navigate('AvatarPicker');

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
        <Text style={styles.errorText}>Couldn't load conversations</Text>
        <Pressable onPress={() => refetch()} style={styles.retryButton}>
          <Text style={styles.retryText}>Retry</Text>
        </Pressable>
      </View>
    );
  }

  const conversations = data?.data ?? [];

  if (conversations.length === 0) {
    return <EmptyState onStartNew={handleNewChat} />;
  }

  return (
    <View style={styles.container}>
      <FlatList
        data={conversations}
        keyExtractor={(c) => String(c.id)}
        renderItem={({ item }) => (
          <ConversationCard
            conversation={item}
            onPress={handleOpen}
            onLongPress={handleLongPress}
          />
        )}
        contentContainerStyle={styles.list}
        onRefresh={refetch}
        refreshing={isRefetching}
      />
      <Pressable
        style={[styles.fab, { bottom: spacing.lg + insets.bottom }]}
        onPress={handleNewChat}
        accessibilityLabel="Start new chat"
      >
        <Text style={styles.fabText}>+</Text>
      </Pressable>
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
    padding: spacing.lg,
  },
  list: { padding: spacing.md, paddingBottom: spacing.xxl },
  errorText: { color: colors.textSecondary, marginBottom: spacing.md },
  retryButton: {
    backgroundColor: colors.primary,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
  },
  retryText: { color: colors.textPrimary, fontWeight: '600' },
  fab: {
    position: 'absolute',
    right: spacing.lg,
    width: 60,
    height: 60,
    borderRadius: radius.pill,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    elevation: 8,
    shadowColor: colors.primary,
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.4,
    shadowRadius: 12,
  },
  fabText: { color: colors.textPrimary, fontSize: 30, fontWeight: '300', marginTop: -2 },
});
