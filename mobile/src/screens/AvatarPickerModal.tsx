import { ActivityIndicator, Alert, FlatList, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useAvatars } from '../hooks/useAvatars';
import { useCreateConversation } from '../hooks/useConversations';
import { AvatarPickerCard } from '../components/avatars/AvatarPickerCard';
import { colors, spacing, fontSize } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';
import type { Avatar } from '../types/models';

type Nav = NativeStackNavigationProp<RootStackParamList, 'AvatarPicker'>;

export function AvatarPickerModal() {
  const navigation = useNavigation<Nav>();
  const { data, isLoading, isError } = useAvatars();
  const createMutation = useCreateConversation();

  const handleSelect = async (avatar: Avatar) => {
    try {
      const conversation = await createMutation.mutateAsync({
        agentId: avatar.id,
        title: null,
      });
      if (!conversation?.id) {
        Alert.alert('Could not start chat', 'Server returned no conversation id.');
        return;
      }
      navigation.replace('ChatDetail', {
        conversationId: conversation.id,
        avatarSlug: avatar.slug,
        avatarName: avatar.name,
        avatarImageUrl: avatar.avatar_image_url,
      });
    } catch (err) {
      Alert.alert('Could not start chat', (err as Error).message ?? 'Unknown error');
    }
  };

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.primary} />
      </View>
    );
  }

  if (isError || !data) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>Couldn't load avatars</Text>
      </View>
    );
  }

  return (
    <FlatList
      style={styles.container}
      contentContainerStyle={styles.list}
      data={data}
      keyExtractor={(a) => a.slug}
      renderItem={({ item }) => (
        <AvatarPickerCard avatar={item} onPress={handleSelect} />
      )}
    />
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
  errorText: { color: colors.textSecondary, fontSize: fontSize.md },
});
