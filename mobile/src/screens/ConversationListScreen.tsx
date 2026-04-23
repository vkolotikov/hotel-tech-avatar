import { useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import {
  useConversations,
  useDeleteConversation,
  useRenameConversation,
} from '../hooks/useConversations';
import { ConversationCard } from '../components/conversations/ConversationCard';
import { ConversationActionsSheet } from '../components/conversations/ConversationActionsSheet';
import { RenameConversationModal } from '../components/conversations/RenameConversationModal';
import { EmptyState } from '../components/conversations/EmptyState';
import { colors, spacing, radius, fontSize } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';
import type { Conversation } from '../types/models';

type Nav = NativeStackNavigationProp<RootStackParamList, 'ConversationList'>;

export function ConversationListScreen() {
  const navigation = useNavigation<Nav>();
  const { data, isLoading, isError, refetch, isRefetching } = useConversations();
  const deleteMutation = useDeleteConversation();
  const renameMutation = useRenameConversation();

  const [actionTarget, setActionTarget] = useState<Conversation | null>(null);
  const [renameTarget, setRenameTarget] = useState<Conversation | null>(null);

  const handleOpen = (conversation: Conversation) => {
    navigation.navigate('ChatDetail', {
      conversationId: conversation.id,
      avatarSlug: conversation.agent?.slug ?? 'nora',
      avatarName: conversation.agent?.name ?? 'Agent',
      avatarImageUrl: conversation.agent?.avatar_image_url,
    });
  };

  const handleLongPress = (conversation: Conversation) => {
    setActionTarget(conversation);
  };

  const handleRenameSelected = () => {
    const target = actionTarget;
    setActionTarget(null);
    if (target) setRenameTarget(target);
  };

  const handleDeleteSelected = () => {
    const target = actionTarget;
    setActionTarget(null);
    if (!target) return;
    const label = target.title ?? 'this conversation';
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
              await deleteMutation.mutateAsync(target.id);
            } catch (err) {
              Alert.alert('Delete failed', (err as Error).message);
            }
          },
        },
      ],
    );
  };

  const handleRenameSubmit = async (title: string) => {
    const target = renameTarget;
    if (!target) return;
    try {
      await renameMutation.mutateAsync({ id: target.id, title });
      setRenameTarget(null);
    } catch (err) {
      Alert.alert('Rename failed', (err as Error).message);
    }
  };

  const handleNewChat = () => navigation.navigate('AvatarHome');

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

      <ConversationActionsSheet
        visible={actionTarget !== null}
        conversation={actionTarget}
        onRename={handleRenameSelected}
        onDelete={handleDeleteSelected}
        onClose={() => setActionTarget(null)}
      />

      <RenameConversationModal
        visible={renameTarget !== null}
        initialTitle={renameTarget?.title ?? ''}
        onSave={handleRenameSubmit}
        onClose={() => setRenameTarget(null)}
      />
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
  list: { padding: spacing.md, paddingBottom: spacing.xl },
  errorText: { color: colors.textSecondary, marginBottom: spacing.md },
  retryButton: {
    backgroundColor: colors.primary,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
  },
  retryText: { color: colors.textPrimary, fontWeight: '600' },
});
