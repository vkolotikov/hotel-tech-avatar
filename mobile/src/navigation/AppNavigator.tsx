import { useEffect, useState } from 'react';
import { ActivityIndicator, Image, Text, View, StyleSheet } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { AuthUser, me, onSessionExpired, resolveAssetUrl, storedToken } from '../api';
import { SignInScreen } from '../screens/SignInScreen';
import { ConversationListScreen } from '../screens/ConversationListScreen';
import { ChatDetailScreen } from '../screens/ChatDetailScreen';
import { AvatarPickerModal } from '../screens/AvatarPickerModal';
import { colors, radius, spacing, fontSize, avatarColors, AvatarSlug } from '../theme';

export type RootStackParamList = {
  ConversationList: undefined;
  ChatDetail: {
    conversationId: number;
    avatarSlug: string;
    avatarName: string;
    avatarImageUrl?: string | null;
  };
  AvatarPicker: undefined;
};

function ChatHeaderTitle({
  avatarName,
  avatarSlug,
  avatarImageUrl,
}: {
  avatarName: string;
  avatarSlug: string;
  avatarImageUrl?: string | null;
}) {
  const uri = resolveAssetUrl(avatarImageUrl);
  const accent =
    avatarSlug in avatarColors ? avatarColors[avatarSlug as AvatarSlug] : colors.primary;
  return (
    <View style={headerStyles.row}>
      {uri ? (
        <Image source={{ uri }} style={[headerStyles.image, { borderColor: accent }]} />
      ) : (
        <View style={[headerStyles.dot, { backgroundColor: accent }]} />
      )}
      <Text style={headerStyles.name} numberOfLines={1}>
        {avatarName}
      </Text>
    </View>
  );
}

const Stack = createNativeStackNavigator<RootStackParamList>();

export function AppNavigator() {
  const [booting, setBooting] = useState(true);
  const [user, setUser] = useState<AuthUser | null>(null);

  useEffect(() => {
    (async () => {
      const token = await storedToken();
      if (!token) {
        setBooting(false);
        return;
      }
      try {
        const current = await me();
        setUser(current);
      } catch {
        // token invalid — fall through to sign-in
      } finally {
        setBooting(false);
      }
    })();
  }, []);

  useEffect(() => {
    return onSessionExpired(() => {
      setUser(null);
    });
  }, []);

  if (booting) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color={colors.primary} />
      </View>
    );
  }

  if (!user) {
    return <SignInScreen onSignedIn={setUser} />;
  }

  return (
    <NavigationContainer>
      <Stack.Navigator
        screenOptions={{
          headerStyle: { backgroundColor: colors.surface },
          headerTintColor: colors.textPrimary,
          contentStyle: { backgroundColor: colors.background },
        }}
      >
        <Stack.Screen
          name="ConversationList"
          component={ConversationListScreen}
          options={{ title: 'WellnessAI' }}
        />
        <Stack.Screen
          name="ChatDetail"
          component={ChatDetailScreen}
          options={({ route }) => ({
            title: route.params.avatarName,
            headerTitle: () => (
              <ChatHeaderTitle
                avatarName={route.params.avatarName}
                avatarSlug={route.params.avatarSlug}
                avatarImageUrl={route.params.avatarImageUrl}
              />
            ),
          })}
        />
        <Stack.Screen
          name="AvatarPicker"
          component={AvatarPickerModal}
          options={{ presentation: 'modal', title: 'Choose an avatar' }}
        />
      </Stack.Navigator>
    </NavigationContainer>
  );
}

const styles = StyleSheet.create({
  centered: {
    flex: 1,
    backgroundColor: colors.background,
    alignItems: 'center',
    justifyContent: 'center',
  },
});

const headerStyles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center' },
  image: {
    width: 32,
    height: 32,
    borderRadius: radius.pill,
    borderWidth: 2,
    marginRight: spacing.sm,
    backgroundColor: colors.surfaceElevated,
  },
  dot: {
    width: 32,
    height: 32,
    borderRadius: radius.pill,
    marginRight: spacing.sm,
  },
  name: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    maxWidth: 220,
  },
});
