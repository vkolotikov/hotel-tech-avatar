import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import {
  NavigationContainer,
  NavigatorScreenParams,
  getFocusedRouteNameFromRoute,
  RouteProp,
} from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { Ionicons } from '@expo/vector-icons';
import { AuthUser, me, onSessionExpired, storedToken } from '../api';
import { SignInScreen } from '../screens/SignInScreen';
import { AvatarHomeScreen } from '../screens/AvatarHomeScreen';
import { ConversationListScreen } from '../screens/ConversationListScreen';
import { ChatDetailScreen } from '../screens/ChatDetailScreen';
import { LibraryScreen } from '../screens/LibraryScreen';
import { SettingsScreen } from '../screens/SettingsScreen';
import { colors } from '../theme';

export type ChatDetailParams = {
  conversationId: number;
  avatarSlug: string;
  avatarName: string;
  avatarImageUrl?: string | null;
  promptSuggestions?: string[];
};

export type HomeStackParamList = {
  AvatarHome: undefined;
  ChatDetail: ChatDetailParams;
};

export type LibraryStackParamList = {
  LibraryHome: undefined;
  ChatDetail: ChatDetailParams;
};

export type HistoryStackParamList = {
  ConversationList: undefined;
  ChatDetail: ChatDetailParams;
};

export type SettingsStackParamList = {
  SettingsHome: undefined;
};

export type RootTabParamList = {
  HomeTab: NavigatorScreenParams<HomeStackParamList>;
  LibraryTab: NavigatorScreenParams<LibraryStackParamList>;
  HistoryTab: NavigatorScreenParams<HistoryStackParamList>;
  SettingsTab: NavigatorScreenParams<SettingsStackParamList>;
};

// Legacy alias kept so screens typed against RootStackParamList still
// compile while the codebase migrates to per-tab param lists.
export type RootStackParamList = {
  AvatarHome: undefined;
  ConversationList: undefined;
  ChatDetail: ChatDetailParams;
};

const HomeStack = createNativeStackNavigator<HomeStackParamList>();
const LibraryStack = createNativeStackNavigator<LibraryStackParamList>();
const HistoryStack = createNativeStackNavigator<HistoryStackParamList>();
const SettingsStack = createNativeStackNavigator<SettingsStackParamList>();
const Tab = createBottomTabNavigator<RootTabParamList>();

function HomeStackScreen() {
  return (
    <HomeStack.Navigator screenOptions={stackScreenOptions}>
      <HomeStack.Screen
        name="AvatarHome"
        component={AvatarHomeScreen}
        options={{ headerShown: false }}
      />
      <HomeStack.Screen
        name="ChatDetail"
        component={ChatDetailScreen}
        options={{ headerShown: false }}
      />
    </HomeStack.Navigator>
  );
}

function LibraryStackScreen() {
  return (
    <LibraryStack.Navigator screenOptions={stackScreenOptions}>
      <LibraryStack.Screen
        name="LibraryHome"
        component={LibraryScreen}
        options={{ title: 'Library' }}
      />
      <LibraryStack.Screen
        name="ChatDetail"
        component={ChatDetailScreen}
        options={{ headerShown: false }}
      />
    </LibraryStack.Navigator>
  );
}

function HistoryStackScreen() {
  return (
    <HistoryStack.Navigator screenOptions={stackScreenOptions}>
      <HistoryStack.Screen
        name="ConversationList"
        component={ConversationListScreen}
        options={{ title: 'History' }}
      />
      <HistoryStack.Screen
        name="ChatDetail"
        component={ChatDetailScreen}
        options={{ headerShown: false }}
      />
    </HistoryStack.Navigator>
  );
}

function SettingsStackScreenFactory({ user }: { user: AuthUser | null }) {
  // Wrap so we can close over the authenticated user without touching
  // route params. Each mount returns a stable component ref.
  return function SettingsStackScreen() {
    return (
      <SettingsStack.Navigator screenOptions={stackScreenOptions}>
        <SettingsStack.Screen name="SettingsHome" options={{ title: 'Settings' }}>
          {() => <SettingsScreen user={user} />}
        </SettingsStack.Screen>
      </SettingsStack.Navigator>
    );
  };
}

const stackScreenOptions = {
  headerStyle: { backgroundColor: colors.surface },
  headerTintColor: colors.textPrimary,
  contentStyle: { backgroundColor: colors.background },
};

/**
 * Hide the tab bar on the immersive chat screen — it ate vertical space
 * and competed with the keyboard. Any other route keeps the bar visible.
 */
function tabBarStyleForRoute(route: RouteProp<RootTabParamList, keyof RootTabParamList>) {
  const routeName = getFocusedRouteNameFromRoute(route);
  if (routeName === 'ChatDetail') {
    return { display: 'none' as const };
  }
  return undefined;
}

function RootTabs({ user }: { user: AuthUser | null }) {
  const SettingsStackScreen = SettingsStackScreenFactory({ user });

  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.textMuted,
        tabBarStyle: {
          backgroundColor: colors.surface,
          borderTopColor: 'rgba(255,255,255,0.08)',
        },
        tabBarLabelStyle: { fontSize: 11, fontWeight: '600' },
      }}
    >
      <Tab.Screen
        name="HomeTab"
        component={HomeStackScreen}
        options={({ route }) => ({
          title: 'Home',
          tabBarIcon: ({ color, size }) => (
            <Ionicons name="home-outline" color={color} size={size} />
          ),
          tabBarStyle: {
            backgroundColor: colors.surface,
            borderTopColor: 'rgba(255,255,255,0.08)',
            ...(tabBarStyleForRoute(route) ?? {}),
          },
        })}
      />
      <Tab.Screen
        name="LibraryTab"
        component={LibraryStackScreen}
        options={({ route }) => ({
          title: 'Library',
          tabBarIcon: ({ color, size }) => (
            <Ionicons name="library-outline" color={color} size={size} />
          ),
          tabBarStyle: {
            backgroundColor: colors.surface,
            borderTopColor: 'rgba(255,255,255,0.08)',
            ...(tabBarStyleForRoute(route) ?? {}),
          },
        })}
      />
      <Tab.Screen
        name="HistoryTab"
        component={HistoryStackScreen}
        options={({ route }) => ({
          title: 'History',
          tabBarIcon: ({ color, size }) => (
            <Ionicons name="time-outline" color={color} size={size} />
          ),
          tabBarStyle: {
            backgroundColor: colors.surface,
            borderTopColor: 'rgba(255,255,255,0.08)',
            ...(tabBarStyleForRoute(route) ?? {}),
          },
        })}
      />
      <Tab.Screen
        name="SettingsTab"
        component={SettingsStackScreen}
        options={{
          title: 'Settings',
          tabBarIcon: ({ color, size }) => (
            <Ionicons name="settings-outline" color={color} size={size} />
          ),
        }}
      />
    </Tab.Navigator>
  );
}

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
      <RootTabs user={user} />
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
