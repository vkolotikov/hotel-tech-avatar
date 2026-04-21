import { Text, View, StyleSheet } from 'react-native';
import { colors, spacing } from '../theme';

export function ConversationListScreen() {
  return (
    <View style={styles.container}>
      <Text style={styles.text}>Conversation list — coming next</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background, padding: spacing.lg },
  text: { color: colors.textPrimary },
});
