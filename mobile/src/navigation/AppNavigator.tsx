import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { AuthUser, me, onSessionExpired, storedToken } from '../api';
import { SignInScreen } from '../screens/SignInScreen';
import { AvatarHomeScreen } from '../screens/AvatarHomeScreen';
import { ConversationListScreen } from '../screens/ConversationListScreen';
import { ChatDetailScreen } from '../screens/ChatDetailScreen';
import { colors, fontSize } from '../theme';

export type RootStackParamList = {
  AvatarHome: undefined;
  ConversationList: undefined;
  ChatDetail: {
    conversationId: number;
    avatarSlug: string;
    avatarName: string;
    avatarImageUrl?: string | null;
  };
};

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
          name="AvatarHome"
          component={AvatarHomeScreen}
          options={{ headerShown: false }}
        />
        <Stack.Screen
          name="ConversationList"
          component={ConversationListScreen}
          options={{ title: 'History' }}
        />
        <Stack.Screen
          name="ChatDetail"
          component={ChatDetailScreen}
          options={({ route }) => ({
            headerTransparent: true,
            headerStyle: { backgroundColor: 'transparent' },
            headerShadowVisible: false,
            headerTintColor: colors.textPrimary,
            contentStyle: { backgroundColor: 'transparent' },
            headerBackground: () => <View style={styles.headerScrim} />,
            headerTitle: () => (
              <Text
                numberOfLines={1}
                style={{
                  color: colors.textPrimary,
                  fontSize: fontSize.md,
                  fontWeight: '600',
                  textShadowColor: 'rgba(0,0,0,0.7)',
                  textShadowRadius: 6,
                }}
              >
                {route.params.avatarName}
              </Text>
            ),
          })}
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
  headerScrim: {
    flex: 1,
    backgroundColor: 'rgba(11,15,23,0.35)',
  },
});
