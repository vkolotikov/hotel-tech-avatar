# Mobile Chat UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a React Native chat UI in the WellnessAI Expo app with text+voice input, streaming agent responses (graceful degradation), multi-conversation history, and citation badges — backed by the existing Laravel verification pipeline.

**Architecture:** React Navigation stack (ConversationList → ChatDetail). React Query for server state. expo-av for voice. react-native-sse for streaming with synchronous POST fallback. Components split by responsibility (screens, chat, conversations, avatars, hooks, api).

**Tech Stack:** Expo SDK 54, React Native 0.81, React 19, TypeScript 5.9, @react-navigation 6.x, @tanstack/react-query 5.x, expo-av, react-native-sse, @testing-library/react-native + jest-expo, MSW for API mocking.

**Spec:** `docs/superpowers/specs/2026-04-21-mobile-chat-ui-design.md`

---

## File Structure

**New files to create (all under `mobile/src/`):**

```
mobile/src/
├── theme/
│   └── index.ts                          # Color palette, spacing, avatar accents
├── navigation/
│   └── AppNavigator.tsx                  # Root stack + auth switcher
├── screens/
│   ├── SignInScreen.tsx                  # Extracted from App.tsx
│   ├── ConversationListScreen.tsx        # List of past conversations
│   ├── ChatDetailScreen.tsx              # Message thread + input
│   └── AvatarPickerModal.tsx             # Select avatar for new chat
├── components/
│   ├── chat/
│   │   ├── MessageBubble.tsx             # User or agent message
│   │   ├── MessageInput.tsx              # Text field + send + voice
│   │   ├── TypingIndicator.tsx           # "Nora is thinking..."
│   │   ├── CitationBadge.tsx             # "📎 N sources · Verified"
│   │   ├── VoiceRecordButton.tsx         # Hold-to-record
│   │   └── StreamingMessage.tsx          # Animated streaming text
│   ├── conversations/
│   │   ├── ConversationCard.tsx          # List row
│   │   └── EmptyState.tsx                # "No conversations yet"
│   └── avatars/
│       ├── AvatarPickerCard.tsx          # Pickable avatar tile
│       └── AvatarHeader.tsx              # Top bar in chat screen
├── hooks/
│   ├── useConversations.ts               # List + create via React Query
│   ├── useMessages.ts                    # Fetch messages
│   ├── useChatStream.ts                  # Send + stream SSE with POST fallback
│   ├── useVoiceRecorder.ts               # expo-av recording
│   └── useAvatars.ts                     # Fetch avatar catalog
├── api/
│   ├── index.ts                          # (existing — keep as is)
│   ├── conversations.ts                  # list, create, getById
│   ├── messages.ts                       # list, send
│   ├── transcribe.ts                     # upload audio → transcript
│   └── avatars.ts                        # list
└── types/
    └── models.ts                         # TypeScript DTOs

mobile/
├── App.tsx                               # Replaced with AppNavigator wrapper
├── jest.config.js                        # Added for tests
├── jest.setup.ts                         # Test setup (RN mocks)
└── __mocks__/
    └── expo-secure-store.ts              # Mock for tests
```

**Modified files:**
- `mobile/App.tsx` — stripped to render `<AppNavigator />` inside `<QueryClientProvider>`
- `mobile/package.json` — new dependencies

---

## Task 1: Install Dependencies

**Files:**
- Modify: `mobile/package.json`

- [ ] **Step 1: Install navigation + state deps**

Run from repo root:
```bash
cd mobile && npx expo install @react-navigation/native @react-navigation/native-stack react-native-screens react-native-safe-area-context @tanstack/react-query
```

Expected: packages added to `package.json` with Expo-compatible versions, no peer dep warnings.

- [ ] **Step 2: Install audio + streaming deps**

```bash
cd mobile && npx expo install expo-av react-native-sse
```

Expected: expo-av and react-native-sse added.

- [ ] **Step 3: Install test deps**

```bash
cd mobile && npm install --save-dev jest-expo @testing-library/react-native @testing-library/jest-native @types/jest msw
```

Expected: test libraries added to devDependencies.

- [ ] **Step 4: Add test script to package.json**

Edit `mobile/package.json`, add to `scripts`:
```json
"test": "jest --watchAll=false",
"test:watch": "jest --watchAll"
```

- [ ] **Step 5: Commit**

```bash
git add mobile/package.json mobile/package-lock.json
git commit -m "chore(mobile): install navigation, react-query, expo-av, SSE, and test deps"
```

---

## Task 2: Jest Configuration

**Files:**
- Create: `mobile/jest.config.js`
- Create: `mobile/jest.setup.ts`
- Create: `mobile/__mocks__/expo-secure-store.ts`

- [ ] **Step 1: Create jest.config.js**

```js
// mobile/jest.config.js
module.exports = {
  preset: 'jest-expo',
  setupFilesAfterEach: ['<rootDir>/jest.setup.ts'],
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|expo(nent)?|@expo(nent)?/.*|@expo-google-fonts/.*|react-navigation|@react-navigation/.*|@unimodules/.*|unimodules|sentry-expo|native-base|react-native-svg|@tanstack|react-native-sse))',
  ],
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/src/$1',
  },
};
```

- [ ] **Step 2: Create jest.setup.ts**

```ts
// mobile/jest.setup.ts
import '@testing-library/jest-native/extend-expect';
```

- [ ] **Step 3: Create expo-secure-store mock**

```ts
// mobile/__mocks__/expo-secure-store.ts
const store: Record<string, string> = {};

export const getItemAsync = jest.fn(async (key: string) => store[key] ?? null);
export const setItemAsync = jest.fn(async (key: string, value: string) => {
  store[key] = value;
});
export const deleteItemAsync = jest.fn(async (key: string) => {
  delete store[key];
});
```

- [ ] **Step 4: Verify jest runs**

```bash
cd mobile && npx jest --version
```

Expected: prints jest version.

- [ ] **Step 5: Commit**

```bash
git add mobile/jest.config.js mobile/jest.setup.ts mobile/__mocks__
git commit -m "chore(mobile): configure jest-expo with testing-library and secure-store mock"
```

---

## Task 3: Theme Extraction

**Files:**
- Create: `mobile/src/theme/index.ts`
- Test: `mobile/src/theme/__tests__/index.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
// mobile/src/theme/__tests__/index.test.ts
import { colors, spacing, avatarColors } from '../index';

describe('theme', () => {
  test('exports base colors', () => {
    expect(colors.background).toBe('#0b0f17');
    expect(colors.primary).toBe('#7c5cff');
    expect(colors.textPrimary).toBe('#ffffff');
  });

  test('exports spacing scale', () => {
    expect(spacing.sm).toBe(8);
    expect(spacing.md).toBe(16);
    expect(spacing.lg).toBe(24);
  });

  test('exports avatar accent colors', () => {
    expect(avatarColors.nora).toBe('#4ade80');
    expect(avatarColors.luna).toBe('#818cf8');
    expect(avatarColors.zen).toBe('#2dd4bf');
    expect(avatarColors.integra).toBe('#3b82f6');
    expect(avatarColors.axel).toBe('#f87171');
    expect(avatarColors.aura).toBe('#f472b6');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd mobile && npx jest src/theme
```

Expected: FAIL — module not found.

- [ ] **Step 3: Create theme module**

```ts
// mobile/src/theme/index.ts
export const colors = {
  background: '#0b0f17',
  surface: '#141a26',
  surfaceElevated: '#1f2937',
  primary: '#7c5cff',
  textPrimary: '#ffffff',
  textSecondary: '#d1d5db',
  textMuted: '#9ca3af',
  border: '#374151',
  danger: '#ef4444',
  warning: '#f59e0b',
  success: '#10b981',
} as const;

export const spacing = {
  xs: 4,
  sm: 8,
  md: 16,
  lg: 24,
  xl: 32,
  xxl: 48,
} as const;

export const radius = {
  sm: 8,
  md: 12,
  lg: 16,
  pill: 9999,
} as const;

export const fontSize = {
  xs: 12,
  sm: 14,
  md: 16,
  lg: 20,
  xl: 28,
} as const;

export const avatarColors = {
  nora: '#4ade80',
  luna: '#818cf8',
  zen: '#2dd4bf',
  integra: '#3b82f6',
  axel: '#f87171',
  aura: '#f472b6',
} as const;

export type AvatarSlug = keyof typeof avatarColors;
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd mobile && npx jest src/theme
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add mobile/src/theme
git commit -m "feat(mobile): extract theme with colors, spacing, and avatar accent palette"
```

---

## Task 4: TypeScript Models

**Files:**
- Create: `mobile/src/types/models.ts`

- [ ] **Step 1: Create type definitions**

```ts
// mobile/src/types/models.ts
export interface Avatar {
  id: number;
  slug: string;
  name: string;
  role: string;
  domain: string;
  description: string | null;
  vertical_slug: string;
  avatar_image_url: string | null;
}

export interface Conversation {
  id: number;
  agent_id: number;
  title: string | null;
  created_at: string;
  updated_at: string;
  agent?: Avatar;
  last_message?: Message;
}

export type MessageRole = 'user' | 'agent';

export interface Message {
  id: number;
  conversation_id: number;
  role: MessageRole;
  content: string;
  ai_provider: string | null;
  ai_model: string | null;
  prompt_tokens: number | null;
  completion_tokens: number | null;
  total_tokens: number | null;
  ai_latency_ms: number | null;
  trace_id: string | null;
  is_verified: boolean | null;
  verification_status: 'passed' | 'failed' | 'not_required' | null;
  verification_failures_json: unknown[] | null;
  verification_latency_ms: number | null;
  citations_count?: number;
  created_at: string;
}

export interface SendMessageResponse {
  user_message: Message;
  agent_message: Message;
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export type StreamEvent =
  | { type: 'token'; content: string }
  | {
      type: 'done';
      message_id: number;
      is_verified: boolean | null;
      citations_count: number;
      verification_latency_ms: number | null;
    }
  | { type: 'error'; message: string };
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add mobile/src/types
git commit -m "feat(mobile): add TypeScript models for avatars, conversations, messages"
```

---

## Task 5: API Clients

**Files:**
- Create: `mobile/src/api/conversations.ts`
- Create: `mobile/src/api/messages.ts`
- Create: `mobile/src/api/avatars.ts`
- Create: `mobile/src/api/transcribe.ts`
- Test: `mobile/src/api/__tests__/conversations.test.ts`

- [ ] **Step 1: Write failing test for conversations API**

```ts
// mobile/src/api/__tests__/conversations.test.ts
import { listConversations, createConversation } from '../conversations';

jest.mock('../index', () => ({
  request: jest.fn(),
}));
import { request } from '../index';

describe('conversations api', () => {
  beforeEach(() => jest.clearAllMocks());

  test('listConversations calls GET /api/v1/conversations', async () => {
    (request as jest.Mock).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });

    await listConversations();

    expect(request).toHaveBeenCalledWith('/api/v1/conversations', { auth: true });
  });

  test('createConversation posts agent_id and title', async () => {
    (request as jest.Mock).mockResolvedValue({ id: 42 });

    await createConversation(7, 'Nora chat');

    expect(request).toHaveBeenCalledWith('/api/v1/conversations', {
      method: 'POST',
      auth: true,
      body: JSON.stringify({ agent_id: 7, title: 'Nora chat' }),
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd mobile && npx jest src/api/__tests__/conversations
```

Expected: FAIL — module not found.

- [ ] **Step 3: Export `request` from `api/index.ts`**

Edit `mobile/src/api/index.ts`, change the `request` function declaration from `async function request` to `export async function request` (so siblings can import it).

- [ ] **Step 4: Create conversations API client**

```ts
// mobile/src/api/conversations.ts
import { request } from './index';
import type { Conversation, Paginated } from '../types/models';

export async function listConversations(): Promise<Paginated<Conversation>> {
  return request<Paginated<Conversation>>('/api/v1/conversations', { auth: true });
}

export async function createConversation(
  agentId: number,
  title: string | null,
): Promise<Conversation> {
  return request<Conversation>('/api/v1/conversations', {
    method: 'POST',
    auth: true,
    body: JSON.stringify({ agent_id: agentId, title }),
  });
}

export async function getConversation(id: number): Promise<Conversation> {
  return request<Conversation>(`/api/v1/conversations/${id}`, { auth: true });
}
```

- [ ] **Step 5: Create messages API client**

```ts
// mobile/src/api/messages.ts
import { request } from './index';
import type { Message, SendMessageResponse } from '../types/models';

export async function listMessages(conversationId: number): Promise<{ data: Message[] }> {
  return request<{ data: Message[] }>(
    `/api/v1/conversations/${conversationId}/messages`,
    { auth: true },
  );
}

export async function sendMessage(
  conversationId: number,
  content: string,
): Promise<SendMessageResponse> {
  return request<SendMessageResponse>(
    `/api/v1/conversations/${conversationId}/messages`,
    {
      method: 'POST',
      auth: true,
      body: JSON.stringify({ content, auto_reply: true }),
    },
  );
}
```

- [ ] **Step 6: Create avatars API client**

```ts
// mobile/src/api/avatars.ts
import { request } from './index';
import type { Avatar } from '../types/models';

export async function listAvatars(vertical = 'wellness'): Promise<{ data: Avatar[] }> {
  return request<{ data: Avatar[] }>(
    `/api/v1/agents?vertical=${encodeURIComponent(vertical)}`,
    { auth: true },
  );
}
```

- [ ] **Step 7: Create transcribe API client**

```ts
// mobile/src/api/transcribe.ts
import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'sanctum_token';

function baseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set');
  return url.replace(/\/$/, '');
}

export async function transcribeAudio(uri: string): Promise<{ transcript: string }> {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (!token) throw new Error('Not authenticated');

  const form = new FormData();
  form.append('audio', {
    uri,
    name: 'recording.m4a',
    type: 'audio/m4a',
  } as any);

  const response = await fetch(`${baseUrl()}/api/v1/transcribe`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
    body: form,
  });

  if (!response.ok) {
    throw new Error(`Transcribe failed: ${response.status}`);
  }
  return response.json();
}
```

- [ ] **Step 8: Run tests**

```bash
cd mobile && npx jest src/api
```

Expected: PASS, 2 tests.

- [ ] **Step 9: Commit**

```bash
git add mobile/src/api
git commit -m "feat(mobile): add API clients for conversations, messages, avatars, transcribe"
```

---

## Task 6: Navigation Setup

**Files:**
- Create: `mobile/src/navigation/AppNavigator.tsx`
- Modify: `mobile/App.tsx`
- Create: `mobile/src/screens/SignInScreen.tsx`

- [ ] **Step 1: Extract SignInScreen from App.tsx**

Create `mobile/src/screens/SignInScreen.tsx` by copying the sign-in JSX from the current `App.tsx` into a component:

```tsx
// mobile/src/screens/SignInScreen.tsx
import { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { AuthUser, login } from '../api';
import { colors, spacing, radius, fontSize } from '../theme';

type Props = {
  onSignedIn: (user: AuthUser) => void;
};

export function SignInScreen({ onSignedIn }: Props) {
  const [email, setEmail] = useState('test@example.com');
  const [password, setPassword] = useState('password');
  const [loading, setLoading] = useState(false);

  const handleSignIn = async () => {
    setLoading(true);
    try {
      const user = await login(email, password, `${Platform.OS}-expo`);
      onSignedIn(user);
    } catch (error) {
      Alert.alert('Sign in failed', (error as Error).message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <View style={styles.card}>
        <Text style={styles.brand}>WellnessAI</Text>
        <Text style={styles.subheading}>Sign in to continue</Text>
        <Text style={styles.label}>Email</Text>
        <TextInput
          style={styles.input}
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          placeholder="you@example.com"
          placeholderTextColor={colors.textMuted}
        />
        <Text style={styles.label}>Password</Text>
        <TextInput
          style={styles.input}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          placeholder="••••••••"
          placeholderTextColor={colors.textMuted}
        />
        <Pressable
          style={[styles.button, loading && styles.buttonDisabled]}
          onPress={handleSignIn}
          disabled={loading}
        >
          {loading ? (
            <ActivityIndicator color={colors.textPrimary} />
          ) : (
            <Text style={styles.buttonText}>Sign in</Text>
          )}
        </Pressable>
      </View>
      <StatusBar style="light" />
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background,
    justifyContent: 'center',
    padding: spacing.lg,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  brand: {
    color: colors.textPrimary,
    fontSize: fontSize.xl,
    fontWeight: '700',
    marginBottom: spacing.xs,
  },
  subheading: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    marginBottom: spacing.lg,
  },
  label: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    marginBottom: spacing.xs,
    marginTop: spacing.sm,
  },
  input: {
    backgroundColor: colors.surfaceElevated,
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: fontSize.md,
  },
  button: {
    backgroundColor: colors.primary,
    paddingVertical: 14,
    borderRadius: radius.md,
    alignItems: 'center',
    marginTop: spacing.lg,
  },
  buttonDisabled: { opacity: 0.7 },
  buttonText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
  },
});
```

- [ ] **Step 2: Create AppNavigator**

```tsx
// mobile/src/navigation/AppNavigator.tsx
import { useEffect, useState } from 'react';
import { ActivityIndicator, View, StyleSheet } from 'react-native';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { AuthUser, me, storedToken } from '../api';
import { SignInScreen } from '../screens/SignInScreen';
import { ConversationListScreen } from '../screens/ConversationListScreen';
import { ChatDetailScreen } from '../screens/ChatDetailScreen';
import { AvatarPickerModal } from '../screens/AvatarPickerModal';
import { colors } from '../theme';

export type RootStackParamList = {
  ConversationList: undefined;
  ChatDetail: { conversationId: number; avatarSlug: string; avatarName: string };
  AvatarPicker: undefined;
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
          options={({ route }) => ({ title: route.params.avatarName })}
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
```

- [ ] **Step 3: Rewrite App.tsx as thin wrapper**

```tsx
// mobile/App.tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StatusBar } from 'expo-status-bar';
import { AppNavigator } from './src/navigation/AppNavigator';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1 },
  },
});

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AppNavigator />
      <StatusBar style="light" />
    </QueryClientProvider>
  );
}
```

- [ ] **Step 4: Create placeholder screens to avoid import errors**

```tsx
// mobile/src/screens/ConversationListScreen.tsx (placeholder)
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
```

```tsx
// mobile/src/screens/ChatDetailScreen.tsx (placeholder)
import { Text, View, StyleSheet } from 'react-native';
import { colors, spacing } from '../theme';

export function ChatDetailScreen() {
  return (
    <View style={styles.container}>
      <Text style={styles.text}>Chat detail — coming next</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background, padding: spacing.lg },
  text: { color: colors.textPrimary },
});
```

```tsx
// mobile/src/screens/AvatarPickerModal.tsx (placeholder)
import { Text, View, StyleSheet } from 'react-native';
import { colors, spacing } from '../theme';

export function AvatarPickerModal() {
  return (
    <View style={styles.container}>
      <Text style={styles.text}>Avatar picker — coming next</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background, padding: spacing.lg },
  text: { color: colors.textPrimary },
});
```

- [ ] **Step 5: Verify TypeScript compiles and app boots**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

```bash
cd mobile && npx expo start --web
```

Manually verify: sign-in screen appears; signing in navigates to placeholder list screen.

- [ ] **Step 6: Commit**

```bash
git add mobile/App.tsx mobile/src/navigation mobile/src/screens
git commit -m "feat(mobile): add navigation stack with auth switcher and placeholder screens"
```

---

## Task 7: useAvatars Hook + useConversations Hook

**Files:**
- Create: `mobile/src/hooks/useAvatars.ts`
- Create: `mobile/src/hooks/useConversations.ts`
- Test: `mobile/src/hooks/__tests__/useConversations.test.tsx`

- [ ] **Step 1: Write failing test for useConversations**

```tsx
// mobile/src/hooks/__tests__/useConversations.test.tsx
import { renderHook, waitFor } from '@testing-library/react-native';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useConversations } from '../useConversations';

jest.mock('../../api/conversations', () => ({
  listConversations: jest.fn(),
}));
import { listConversations } from '../../api/conversations';

const wrapper = ({ children }: { children: React.ReactNode }) => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
};

describe('useConversations', () => {
  test('returns conversations from API', async () => {
    (listConversations as jest.Mock).mockResolvedValue({
      data: [{ id: 1, agent_id: 5, title: 'Hello', created_at: '', updated_at: '' }],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
    });

    const { result } = renderHook(() => useConversations(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.data).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd mobile && npx jest src/hooks/__tests__/useConversations
```

Expected: FAIL — module not found.

- [ ] **Step 3: Create useConversations**

```ts
// mobile/src/hooks/useConversations.ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createConversation, listConversations } from '../api/conversations';

export const conversationsKey = ['conversations'] as const;

export function useConversations() {
  return useQuery({
    queryKey: conversationsKey,
    queryFn: listConversations,
  });
}

export function useCreateConversation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ agentId, title }: { agentId: number; title: string | null }) =>
      createConversation(agentId, title),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: conversationsKey });
    },
  });
}
```

- [ ] **Step 4: Create useAvatars**

```ts
// mobile/src/hooks/useAvatars.ts
import { useQuery } from '@tanstack/react-query';
import { listAvatars } from '../api/avatars';

export function useAvatars() {
  return useQuery({
    queryKey: ['avatars', 'wellness'],
    queryFn: () => listAvatars('wellness'),
    staleTime: Infinity,
  });
}
```

- [ ] **Step 5: Run tests**

```bash
cd mobile && npx jest src/hooks
```

Expected: PASS, 1 test.

- [ ] **Step 6: Commit**

```bash
git add mobile/src/hooks
git commit -m "feat(mobile): add useConversations and useAvatars React Query hooks"
```

---

## Task 8: ConversationCard + EmptyState Components

**Files:**
- Create: `mobile/src/components/conversations/ConversationCard.tsx`
- Create: `mobile/src/components/conversations/EmptyState.tsx`
- Test: `mobile/src/components/conversations/__tests__/ConversationCard.test.tsx`

- [ ] **Step 1: Write failing test**

```tsx
// mobile/src/components/conversations/__tests__/ConversationCard.test.tsx
import { render, fireEvent } from '@testing-library/react-native';
import { ConversationCard } from '../ConversationCard';

describe('ConversationCard', () => {
  const conversation = {
    id: 1,
    agent_id: 5,
    title: 'Breakfast planning',
    created_at: '2026-04-21T10:00:00Z',
    updated_at: '2026-04-21T10:30:00Z',
    agent: {
      id: 5,
      slug: 'nora',
      name: 'Nora',
      role: 'Nutritionist',
      domain: 'nutrition',
      description: null,
      vertical_slug: 'wellness',
      avatar_image_url: null,
    },
  };

  test('renders title and avatar name', () => {
    const { getByText } = render(
      <ConversationCard conversation={conversation} onPress={() => {}} />,
    );
    expect(getByText('Breakfast planning')).toBeTruthy();
    expect(getByText('Nora')).toBeTruthy();
  });

  test('calls onPress with conversation when tapped', () => {
    const onPress = jest.fn();
    const { getByTestId } = render(
      <ConversationCard conversation={conversation} onPress={onPress} />,
    );
    fireEvent.press(getByTestId('conversation-card'));
    expect(onPress).toHaveBeenCalledWith(conversation);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd mobile && npx jest src/components/conversations
```

Expected: FAIL.

- [ ] **Step 3: Create ConversationCard**

```tsx
// mobile/src/components/conversations/ConversationCard.tsx
import { Pressable, Text, View, StyleSheet } from 'react-native';
import type { Conversation } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  conversation: Conversation;
  onPress: (c: Conversation) => void;
};

export function ConversationCard({ conversation, onPress }: Props) {
  const slug = conversation.agent?.slug as AvatarSlug | undefined;
  const accent = slug && slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <Pressable
      testID="conversation-card"
      onPress={() => onPress(conversation)}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
    >
      <View style={[styles.avatarDot, { backgroundColor: accent }]} />
      <View style={styles.body}>
        <Text style={styles.title} numberOfLines={1}>
          {conversation.title ?? 'Untitled conversation'}
        </Text>
        <Text style={styles.subtitle}>{conversation.agent?.name ?? 'Agent'}</Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    padding: spacing.md,
    borderRadius: radius.md,
    marginBottom: spacing.sm,
  },
  pressed: { opacity: 0.7 },
  avatarDot: {
    width: 40,
    height: 40,
    borderRadius: radius.pill,
    marginRight: spacing.md,
  },
  body: { flex: 1 },
  title: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    marginBottom: 2,
  },
  subtitle: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
  },
});
```

- [ ] **Step 4: Create EmptyState**

```tsx
// mobile/src/components/conversations/EmptyState.tsx
import { Pressable, Text, View, StyleSheet } from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  onStartNew: () => void;
};

export function EmptyState({ onStartNew }: Props) {
  return (
    <View style={styles.container}>
      <Text style={styles.heading}>No conversations yet</Text>
      <Text style={styles.body}>
        Start a new chat with one of your wellness avatars.
      </Text>
      <Pressable style={styles.button} onPress={onStartNew}>
        <Text style={styles.buttonText}>Start new chat</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
  },
  heading: {
    color: colors.textPrimary,
    fontSize: fontSize.lg,
    fontWeight: '600',
    marginBottom: spacing.sm,
  },
  body: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    textAlign: 'center',
    marginBottom: spacing.lg,
  },
  button: {
    backgroundColor: colors.primary,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
  },
  buttonText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
  },
});
```

- [ ] **Step 5: Run tests**

```bash
cd mobile && npx jest src/components/conversations
```

Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add mobile/src/components/conversations
git commit -m "feat(mobile): add ConversationCard and EmptyState components"
```

---

## Task 9: ConversationListScreen

**Files:**
- Modify: `mobile/src/screens/ConversationListScreen.tsx` (replace placeholder)

- [ ] **Step 1: Implement ConversationListScreen**

```tsx
// mobile/src/screens/ConversationListScreen.tsx
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useConversations } from '../hooks/useConversations';
import { ConversationCard } from '../components/conversations/ConversationCard';
import { EmptyState } from '../components/conversations/EmptyState';
import { colors, spacing, radius, fontSize } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';
import type { Conversation } from '../types/models';

type Nav = NativeStackNavigationProp<RootStackParamList, 'ConversationList'>;

export function ConversationListScreen() {
  const navigation = useNavigation<Nav>();
  const { data, isLoading, isError, refetch, isRefetching } = useConversations();

  const handleOpen = (conversation: Conversation) => {
    navigation.navigate('ChatDetail', {
      conversationId: conversation.id,
      avatarSlug: conversation.agent?.slug ?? 'nora',
      avatarName: conversation.agent?.name ?? 'Agent',
    });
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
          <ConversationCard conversation={item} onPress={handleOpen} />
        )}
        contentContainerStyle={styles.list}
        onRefresh={refetch}
        refreshing={isRefetching}
      />
      <Pressable style={styles.fab} onPress={handleNewChat} accessibilityLabel="Start new chat">
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
  list: { padding: spacing.md },
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
    bottom: spacing.lg,
    right: spacing.lg,
    width: 56,
    height: 56,
    borderRadius: radius.pill,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    elevation: 4,
  },
  fabText: { color: colors.textPrimary, fontSize: fontSize.xl },
});
```

- [ ] **Step 2: Verify TypeScript**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add mobile/src/screens/ConversationListScreen.tsx
git commit -m "feat(mobile): implement ConversationListScreen with list, pull-to-refresh, empty state, and FAB"
```

---

## Task 10: AvatarPickerModal

**Files:**
- Create: `mobile/src/components/avatars/AvatarPickerCard.tsx`
- Modify: `mobile/src/screens/AvatarPickerModal.tsx` (replace placeholder)

- [ ] **Step 1: Create AvatarPickerCard**

```tsx
// mobile/src/components/avatars/AvatarPickerCard.tsx
import { Pressable, Text, View, StyleSheet } from 'react-native';
import type { Avatar } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  avatar: Avatar;
  onPress: (avatar: Avatar) => void;
};

export function AvatarPickerCard({ avatar, onPress }: Props) {
  const slug = avatar.slug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <Pressable
      testID={`avatar-card-${avatar.slug}`}
      onPress={() => onPress(avatar)}
      style={({ pressed }) => [
        styles.card,
        { borderColor: accent },
        pressed && styles.pressed,
      ]}
    >
      <View style={[styles.dot, { backgroundColor: accent }]} />
      <Text style={styles.name}>{avatar.name}</Text>
      <Text style={styles.role}>{avatar.role}</Text>
      {avatar.description && (
        <Text style={styles.description} numberOfLines={3}>
          {avatar.description}
        </Text>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    padding: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    marginBottom: spacing.sm,
  },
  pressed: { opacity: 0.7 },
  dot: {
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    marginBottom: spacing.sm,
  },
  name: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    marginBottom: 2,
  },
  role: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    marginBottom: spacing.xs,
  },
  description: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
  },
});
```

- [ ] **Step 2: Implement AvatarPickerModal**

```tsx
// mobile/src/screens/AvatarPickerModal.tsx
import { ActivityIndicator, FlatList, StyleSheet, Text, View } from 'react-native';
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
    const conversation = await createMutation.mutateAsync({
      agentId: avatar.id,
      title: null,
    });
    navigation.replace('ChatDetail', {
      conversationId: conversation.id,
      avatarSlug: avatar.slug,
      avatarName: avatar.name,
    });
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
      data={data.data}
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
```

- [ ] **Step 3: Verify TypeScript**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add mobile/src/components/avatars mobile/src/screens/AvatarPickerModal.tsx
git commit -m "feat(mobile): implement AvatarPickerModal with avatar catalog + conversation creation"
```

---

## Task 11: MessageBubble + CitationBadge + TypingIndicator

**Files:**
- Create: `mobile/src/components/chat/MessageBubble.tsx`
- Create: `mobile/src/components/chat/CitationBadge.tsx`
- Create: `mobile/src/components/chat/TypingIndicator.tsx`
- Test: `mobile/src/components/chat/__tests__/MessageBubble.test.tsx`

- [ ] **Step 1: Write failing test**

```tsx
// mobile/src/components/chat/__tests__/MessageBubble.test.tsx
import { render } from '@testing-library/react-native';
import { MessageBubble } from '../MessageBubble';

const baseMessage = {
  id: 1,
  conversation_id: 1,
  role: 'agent' as const,
  content: 'Hello!',
  ai_provider: null,
  ai_model: null,
  prompt_tokens: null,
  completion_tokens: null,
  total_tokens: null,
  ai_latency_ms: null,
  trace_id: null,
  is_verified: null,
  verification_status: null,
  verification_failures_json: null,
  verification_latency_ms: null,
  created_at: '',
};

describe('MessageBubble', () => {
  test('renders agent message content', () => {
    const { getByText } = render(
      <MessageBubble message={baseMessage} avatarSlug="nora" />,
    );
    expect(getByText('Hello!')).toBeTruthy();
  });

  test('shows citation badge when citations_count > 0', () => {
    const { getByText } = render(
      <MessageBubble
        message={{ ...baseMessage, is_verified: true, citations_count: 3 }}
        avatarSlug="nora"
      />,
    );
    expect(getByText(/3 sources/)).toBeTruthy();
    expect(getByText(/Verified/)).toBeTruthy();
  });

  test('shows fallback badge when is_verified is false', () => {
    const { getByText } = render(
      <MessageBubble
        message={{ ...baseMessage, is_verified: false, citations_count: 0 }}
        avatarSlug="nora"
      />,
    );
    expect(getByText(/Fallback/)).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd mobile && npx jest src/components/chat
```

Expected: FAIL.

- [ ] **Step 3: Create CitationBadge**

```tsx
// mobile/src/components/chat/CitationBadge.tsx
import { Text, View, StyleSheet } from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  citationsCount: number;
  isVerified: boolean | null;
};

export function CitationBadge({ citationsCount, isVerified }: Props) {
  if (isVerified === null) return null;

  const label =
    isVerified === true
      ? `📎 ${citationsCount} source${citationsCount === 1 ? '' : 's'} · Verified ✓`
      : '⚠ Fallback response';

  const tint = isVerified ? colors.success : colors.warning;

  return (
    <View style={[styles.badge, { borderColor: tint }]}>
      <Text style={[styles.text, { color: tint }]}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    alignSelf: 'flex-start',
    borderWidth: 1,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
    marginTop: spacing.xs,
  },
  text: {
    fontSize: fontSize.xs,
    fontWeight: '500',
  },
});
```

- [ ] **Step 4: Create MessageBubble**

```tsx
// mobile/src/components/chat/MessageBubble.tsx
import { Text, View, StyleSheet } from 'react-native';
import type { Message } from '../../types/models';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';
import { CitationBadge } from './CitationBadge';

type Props = {
  message: Message;
  avatarSlug: string;
};

export function MessageBubble({ message, avatarSlug }: Props) {
  const isUser = message.role === 'user';
  const slug = avatarSlug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <View style={[styles.row, isUser ? styles.rowUser : styles.rowAgent]}>
      <View
        style={[
          styles.bubble,
          isUser
            ? { backgroundColor: colors.primary }
            : { backgroundColor: colors.surface, borderLeftColor: accent, borderLeftWidth: 3 },
        ]}
      >
        <Text style={styles.text}>{message.content}</Text>
      </View>
      {!isUser && (
        <CitationBadge
          citationsCount={message.citations_count ?? 0}
          isVerified={message.is_verified}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { marginBottom: spacing.md },
  rowUser: { alignItems: 'flex-end' },
  rowAgent: { alignItems: 'flex-start' },
  bubble: {
    maxWidth: '85%',
    padding: spacing.md,
    borderRadius: radius.lg,
  },
  text: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    lineHeight: 22,
  },
});
```

- [ ] **Step 5: Create TypingIndicator**

```tsx
// mobile/src/components/chat/TypingIndicator.tsx
import { useEffect, useRef } from 'react';
import { Animated, StyleSheet, Text, View } from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = { avatarName: string };

export function TypingIndicator({ avatarName }: Props) {
  const opacity = useRef(new Animated.Value(0.4)).current;

  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(opacity, { toValue: 1, duration: 600, useNativeDriver: true }),
        Animated.timing(opacity, { toValue: 0.4, duration: 600, useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [opacity]);

  return (
    <Animated.View style={[styles.container, { opacity }]}>
      <View style={styles.bubble}>
        <Text style={styles.text}>{avatarName} is thinking…</Text>
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  container: {
    alignItems: 'flex-start',
    marginBottom: spacing.md,
  },
  bubble: {
    backgroundColor: colors.surface,
    padding: spacing.sm,
    paddingHorizontal: spacing.md,
    borderRadius: radius.lg,
  },
  text: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    fontStyle: 'italic',
  },
});
```

- [ ] **Step 6: Run tests**

```bash
cd mobile && npx jest src/components/chat
```

Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
git add mobile/src/components/chat
git commit -m "feat(mobile): add MessageBubble, CitationBadge, and TypingIndicator"
```

---

## Task 12: MessageInput (Text-Only)

**Files:**
- Create: `mobile/src/components/chat/MessageInput.tsx`
- Test: `mobile/src/components/chat/__tests__/MessageInput.test.tsx`

- [ ] **Step 1: Write failing test**

```tsx
// mobile/src/components/chat/__tests__/MessageInput.test.tsx
import { render, fireEvent } from '@testing-library/react-native';
import { MessageInput } from '../MessageInput';

describe('MessageInput', () => {
  test('calls onSend with trimmed text', () => {
    const onSend = jest.fn();
    const { getByPlaceholderText, getByTestId } = render(
      <MessageInput onSend={onSend} disabled={false} />,
    );
    fireEvent.changeText(getByPlaceholderText(/message/i), '  Hello  ');
    fireEvent.press(getByTestId('send-button'));
    expect(onSend).toHaveBeenCalledWith('Hello');
  });

  test('does not send empty text', () => {
    const onSend = jest.fn();
    const { getByTestId } = render(<MessageInput onSend={onSend} disabled={false} />);
    fireEvent.press(getByTestId('send-button'));
    expect(onSend).not.toHaveBeenCalled();
  });

  test('disables send when disabled prop is true', () => {
    const onSend = jest.fn();
    const { getByTestId, getByPlaceholderText } = render(
      <MessageInput onSend={onSend} disabled={true} />,
    );
    fireEvent.changeText(getByPlaceholderText(/message/i), 'Hi');
    fireEvent.press(getByTestId('send-button'));
    expect(onSend).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd mobile && npx jest src/components/chat/__tests__/MessageInput
```

Expected: FAIL.

- [ ] **Step 3: Create MessageInput**

```tsx
// mobile/src/components/chat/MessageInput.tsx
import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  onSend: (text: string) => void;
  disabled: boolean;
};

export function MessageInput({ onSend, disabled }: Props) {
  const [text, setText] = useState('');

  const handleSend = () => {
    const trimmed = text.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setText('');
  };

  return (
    <View style={styles.container}>
      <TextInput
        style={styles.input}
        value={text}
        onChangeText={setText}
        placeholder="Message…"
        placeholderTextColor={colors.textMuted}
        multiline
        editable={!disabled}
      />
      <Pressable
        testID="send-button"
        style={[styles.sendButton, (disabled || !text.trim()) && styles.sendDisabled]}
        onPress={handleSend}
        disabled={disabled || !text.trim()}
        accessibilityLabel="Send message"
      >
        <Text style={styles.sendIcon}>➤</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    padding: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.surface,
  },
  input: {
    flex: 1,
    backgroundColor: colors.surfaceElevated,
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: fontSize.md,
    maxHeight: 100,
  },
  sendButton: {
    marginLeft: spacing.sm,
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendDisabled: { opacity: 0.4 },
  sendIcon: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
});
```

- [ ] **Step 4: Run tests**

```bash
cd mobile && npx jest src/components/chat/__tests__/MessageInput
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add mobile/src/components/chat/MessageInput.tsx mobile/src/components/chat/__tests__/MessageInput.test.tsx
git commit -m "feat(mobile): add MessageInput with text input and send button"
```

---

## Task 13: useMessages + useChatStream Hooks (Synchronous POST)

**Files:**
- Create: `mobile/src/hooks/useMessages.ts`
- Create: `mobile/src/hooks/useChatStream.ts`

- [ ] **Step 1: Create useMessages**

```ts
// mobile/src/hooks/useMessages.ts
import { useQuery } from '@tanstack/react-query';
import { listMessages } from '../api/messages';

export const messagesKey = (conversationId: number) =>
  ['conversations', conversationId, 'messages'] as const;

export function useMessages(conversationId: number) {
  return useQuery({
    queryKey: messagesKey(conversationId),
    queryFn: () => listMessages(conversationId),
  });
}
```

- [ ] **Step 2: Create useChatStream (synchronous POST baseline)**

```ts
// mobile/src/hooks/useChatStream.ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { sendMessage } from '../api/messages';
import { messagesKey } from './useMessages';
import type { Message, SendMessageResponse } from '../types/models';

type SendArgs = { conversationId: number; text: string };

/**
 * Sends a message and waits for the agent's full reply.
 * Streaming (SSE) will be layered in Task 14 without changing this hook's contract.
 */
export function useChatStream() {
  const qc = useQueryClient();

  return useMutation<SendMessageResponse, Error, SendArgs>({
    mutationFn: ({ conversationId, text }) => sendMessage(conversationId, text),
    onSuccess: (response, { conversationId }) => {
      qc.setQueryData<{ data: Message[] } | undefined>(messagesKey(conversationId), (prev) => {
        const prevData = prev?.data ?? [];
        return {
          data: [...prevData, response.user_message, response.agent_message],
        };
      });
    },
  });
}
```

- [ ] **Step 3: Verify TypeScript**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add mobile/src/hooks/useMessages.ts mobile/src/hooks/useChatStream.ts
git commit -m "feat(mobile): add useMessages and useChatStream hooks (synchronous baseline)"
```

---

## Task 14: ChatDetailScreen (Text Only)

**Files:**
- Modify: `mobile/src/screens/ChatDetailScreen.tsx` (replace placeholder)

- [ ] **Step 1: Implement ChatDetailScreen**

```tsx
// mobile/src/screens/ChatDetailScreen.tsx
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
import { colors, spacing, fontSize } from '../theme';
import type { RootStackParamList } from '../navigation/AppNavigator';

type Route = RouteProp<RootStackParamList, 'ChatDetail'>;

export function ChatDetailScreen() {
  const route = useRoute<Route>();
  const { conversationId, avatarSlug, avatarName } = route.params;
  const listRef = useRef<FlatList>(null);

  const { data, isLoading, isError, refetch } = useMessages(conversationId);
  const sendMutation = useChatStream();

  useEffect(() => {
    if (data?.data.length) {
      setTimeout(() => listRef.current?.scrollToEnd({ animated: true }), 50);
    }
  }, [data?.data.length]);

  const handleSend = (text: string) => {
    sendMutation.mutate({ conversationId, text });
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

  const messages = data?.data ?? [];

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
          sendMutation.isPending ? <TypingIndicator avatarName={avatarName} /> : null
        }
        onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: true })}
      />
      <MessageInput onSend={handleSend} disabled={sendMutation.isPending} />
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
```

- [ ] **Step 2: Verify TypeScript + manual smoke test**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

```bash
cd mobile && npx expo start
```

Manually verify on device/simulator:
- Sign in
- Start new chat, pick Nora
- Send "Hello" → see user bubble → see typing indicator → see Nora's response
- Back arrow → conversation appears in list → tap → messages load

- [ ] **Step 3: Commit**

```bash
git add mobile/src/screens/ChatDetailScreen.tsx
git commit -m "feat(mobile): implement ChatDetailScreen with synchronous send and typing indicator"
```

---

## Task 15: SSE Streaming Upgrade (Graceful Degradation)

**Files:**
- Modify: `mobile/src/hooks/useChatStream.ts`
- Create: `mobile/src/components/chat/StreamingMessage.tsx`

- [ ] **Step 1: Create StreamingMessage component**

```tsx
// mobile/src/components/chat/StreamingMessage.tsx
import { Text, View, StyleSheet } from 'react-native';
import { colors, spacing, radius, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  text: string;
  avatarSlug: string;
};

export function StreamingMessage({ text, avatarSlug }: Props) {
  const slug = avatarSlug as AvatarSlug;
  const accent = slug in avatarColors ? avatarColors[slug] : colors.primary;

  return (
    <View style={styles.row}>
      <View style={[styles.bubble, { borderLeftColor: accent }]}>
        <Text style={styles.text}>{text || '…'}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { alignItems: 'flex-start', marginBottom: spacing.md },
  bubble: {
    maxWidth: '85%',
    backgroundColor: colors.surface,
    padding: spacing.md,
    borderRadius: radius.lg,
    borderLeftWidth: 3,
  },
  text: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    lineHeight: 22,
  },
});
```

- [ ] **Step 2: Rewrite useChatStream with SSE + fallback**

```ts
// mobile/src/hooks/useChatStream.ts
import { useCallback, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import EventSource from 'react-native-sse';
import * as SecureStore from 'expo-secure-store';
import { sendMessage } from '../api/messages';
import { messagesKey } from './useMessages';
import type { Message, StreamEvent } from '../types/models';

const TOKEN_KEY = 'sanctum_token';

function baseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set');
  return url.replace(/\/$/, '');
}

type State = {
  isPending: boolean;
  streamingText: string;
  error: Error | null;
};

export function useChatStream(conversationId: number) {
  const qc = useQueryClient();
  const [state, setState] = useState<State>({
    isPending: false,
    streamingText: '',
    error: null,
  });
  const esRef = useRef<EventSource | null>(null);

  const appendMessages = useCallback(
    (newMessages: Message[]) => {
      qc.setQueryData<{ data: Message[] } | undefined>(messagesKey(conversationId), (prev) => ({
        data: [...(prev?.data ?? []), ...newMessages],
      }));
    },
    [qc, conversationId],
  );

  const openStream = useCallback(
    async (userMessage: Message, placeholderId: number) => {
      const token = await SecureStore.getItemAsync(TOKEN_KEY);
      const url = `${baseUrl()}/api/v1/conversations/${conversationId}/stream?message_id=${placeholderId}`;
      const es = new EventSource(url, {
        headers: { Authorization: `Bearer ${token ?? ''}` },
      });
      esRef.current = es;

      let buffer = '';
      let finalMessage: Message | null = null;

      es.addEventListener('message', (evt: any) => {
        try {
          const event: StreamEvent = JSON.parse(evt.data);
          if (event.type === 'token') {
            buffer += event.content;
            setState((s) => ({ ...s, streamingText: buffer }));
          } else if (event.type === 'done') {
            finalMessage = {
              id: event.message_id,
              conversation_id: conversationId,
              role: 'agent',
              content: buffer,
              ai_provider: null,
              ai_model: null,
              prompt_tokens: null,
              completion_tokens: null,
              total_tokens: null,
              ai_latency_ms: null,
              trace_id: null,
              is_verified: event.is_verified,
              verification_status: null,
              verification_failures_json: null,
              verification_latency_ms: event.verification_latency_ms,
              citations_count: event.citations_count,
              created_at: new Date().toISOString(),
            };
            es.close();
            esRef.current = null;
            appendMessages([userMessage, finalMessage]);
            setState({ isPending: false, streamingText: '', error: null });
          } else if (event.type === 'error') {
            es.close();
            esRef.current = null;
            setState({ isPending: false, streamingText: '', error: new Error(event.message) });
          }
        } catch (err) {
          es.close();
          esRef.current = null;
          setState({ isPending: false, streamingText: '', error: err as Error });
        }
      });

      es.addEventListener('error', () => {
        es.close();
        esRef.current = null;
        setState((s) => ({ ...s, isPending: false, error: new Error('Stream failed') }));
      });
    },
    [conversationId, appendMessages],
  );

  const sendSync = useCallback(
    async (text: string) => {
      const response = await sendMessage(conversationId, text);
      appendMessages([response.user_message, response.agent_message]);
      setState({ isPending: false, streamingText: '', error: null });
    },
    [conversationId, appendMessages],
  );

  const send = useCallback(
    async (text: string) => {
      setState({ isPending: true, streamingText: '', error: null });
      try {
        // Try streaming endpoint first; fall back to sync on HTTP failure
        // We POST the message first (backend saves user + returns placeholder ids)
        const response = await sendMessage(conversationId, text);
        if (response.agent_message) {
          // Backend already returned a full response — no need to stream
          appendMessages([response.user_message, response.agent_message]);
          setState({ isPending: false, streamingText: '', error: null });
          return;
        }
        // If the endpoint shape later returns only user_message + placeholder, stream
        await openStream(response.user_message, response.agent_message?.id ?? 0);
      } catch (error) {
        // Final fallback: already-synchronous POST flow
        try {
          await sendSync(text);
        } catch (e) {
          setState({ isPending: false, streamingText: '', error: e as Error });
        }
      }
    },
    [conversationId, openStream, sendSync, appendMessages],
  );

  const cancel = useCallback(() => {
    esRef.current?.close();
    esRef.current = null;
    setState({ isPending: false, streamingText: '', error: null });
  }, []);

  return { ...state, send, cancel };
}
```

- [ ] **Step 3: Update ChatDetailScreen to consume new hook API**

Replace the send hook usage in `mobile/src/screens/ChatDetailScreen.tsx`:

Change this section:
```tsx
const sendMutation = useChatStream();
// ...
const handleSend = (text: string) => {
  sendMutation.mutate({ conversationId, text });
};
// ...
ListFooterComponent={
  sendMutation.isPending ? <TypingIndicator avatarName={avatarName} /> : null
}
// ...
<MessageInput onSend={handleSend} disabled={sendMutation.isPending} />
```

To:
```tsx
import { StreamingMessage } from '../components/chat/StreamingMessage';

// inside component:
const stream = useChatStream(conversationId);

const handleSend = (text: string) => {
  stream.send(text);
};

// FlatList footer:
ListFooterComponent={
  stream.isPending ? (
    stream.streamingText ? (
      <StreamingMessage text={stream.streamingText} avatarSlug={avatarSlug} />
    ) : (
      <TypingIndicator avatarName={avatarName} />
    )
  ) : null
}

// input:
<MessageInput onSend={handleSend} disabled={stream.isPending} />
```

- [ ] **Step 4: Verify TypeScript**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add mobile/src/hooks/useChatStream.ts mobile/src/components/chat/StreamingMessage.tsx mobile/src/screens/ChatDetailScreen.tsx
git commit -m "feat(mobile): add SSE streaming for agent responses with synchronous fallback"
```

---

## Task 16: Voice Recorder Hook + Record Button

**Files:**
- Create: `mobile/src/hooks/useVoiceRecorder.ts`
- Create: `mobile/src/components/chat/VoiceRecordButton.tsx`

- [ ] **Step 1: Create useVoiceRecorder hook**

```ts
// mobile/src/hooks/useVoiceRecorder.ts
import { useCallback, useRef, useState } from 'react';
import { Alert, Linking } from 'react-native';
import { Audio } from 'expo-av';
import { transcribeAudio } from '../api/transcribe';

type State = {
  isRecording: boolean;
  isTranscribing: boolean;
  error: Error | null;
};

export function useVoiceRecorder(onTranscript: (text: string) => void) {
  const [state, setState] = useState<State>({
    isRecording: false,
    isTranscribing: false,
    error: null,
  });
  const recordingRef = useRef<Audio.Recording | null>(null);

  const start = useCallback(async () => {
    try {
      const permission = await Audio.requestPermissionsAsync();
      if (!permission.granted) {
        Alert.alert(
          'Microphone access required',
          'Enable microphone access in Settings to record voice messages.',
          [
            { text: 'Cancel', style: 'cancel' },
            { text: 'Open Settings', onPress: () => Linking.openSettings() },
          ],
        );
        return;
      }

      await Audio.setAudioModeAsync({
        allowsRecordingIOS: true,
        playsInSilentModeIOS: true,
      });

      const { recording } = await Audio.Recording.createAsync(
        Audio.RecordingOptionsPresets.HIGH_QUALITY,
      );
      recordingRef.current = recording;
      setState({ isRecording: true, isTranscribing: false, error: null });
    } catch (error) {
      setState({ isRecording: false, isTranscribing: false, error: error as Error });
    }
  }, []);

  const stop = useCallback(async () => {
    const recording = recordingRef.current;
    recordingRef.current = null;
    if (!recording) return;
    try {
      setState((s) => ({ ...s, isRecording: false, isTranscribing: true }));
      await recording.stopAndUnloadAsync();
      const uri = recording.getURI();
      if (!uri) throw new Error('No recording URI');
      const { transcript } = await transcribeAudio(uri);
      onTranscript(transcript);
      setState({ isRecording: false, isTranscribing: false, error: null });
    } catch (error) {
      setState({ isRecording: false, isTranscribing: false, error: error as Error });
      Alert.alert('Transcription failed', (error as Error).message);
    }
  }, [onTranscript]);

  return { ...state, start, stop };
}
```

- [ ] **Step 2: Create VoiceRecordButton**

```tsx
// mobile/src/components/chat/VoiceRecordButton.tsx
import { Pressable, StyleSheet, Text } from 'react-native';
import { colors, radius, fontSize } from '../../theme';

type Props = {
  isRecording: boolean;
  isTranscribing: boolean;
  onPressIn: () => void;
  onPressOut: () => void;
};

export function VoiceRecordButton({ isRecording, isTranscribing, onPressIn, onPressOut }: Props) {
  const label = isTranscribing ? '…' : isRecording ? '●' : '🎤';
  return (
    <Pressable
      testID="voice-record-button"
      onPressIn={onPressIn}
      onPressOut={onPressOut}
      disabled={isTranscribing}
      style={[
        styles.button,
        isRecording && styles.recording,
        isTranscribing && styles.disabled,
      ]}
      accessibilityLabel="Record voice message"
      accessibilityHint="Press and hold to record, release to transcribe"
    >
      <Text style={styles.icon}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    backgroundColor: colors.surfaceElevated,
    alignItems: 'center',
    justifyContent: 'center',
  },
  recording: { backgroundColor: colors.danger },
  disabled: { opacity: 0.5 },
  icon: { color: colors.textPrimary, fontSize: fontSize.md },
});
```

- [ ] **Step 3: Integrate voice into MessageInput**

Replace `mobile/src/components/chat/MessageInput.tsx`:

```tsx
// mobile/src/components/chat/MessageInput.tsx
import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { useVoiceRecorder } from '../../hooks/useVoiceRecorder';
import { VoiceRecordButton } from './VoiceRecordButton';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  onSend: (text: string) => void;
  disabled: boolean;
};

export function MessageInput({ onSend, disabled }: Props) {
  const [text, setText] = useState('');
  const recorder = useVoiceRecorder((transcript) => setText(transcript));

  const handleSend = () => {
    const trimmed = text.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setText('');
  };

  return (
    <View style={styles.container}>
      <VoiceRecordButton
        isRecording={recorder.isRecording}
        isTranscribing={recorder.isTranscribing}
        onPressIn={recorder.start}
        onPressOut={recorder.stop}
      />
      <TextInput
        style={styles.input}
        value={text}
        onChangeText={setText}
        placeholder="Message…"
        placeholderTextColor={colors.textMuted}
        multiline
        editable={!disabled && !recorder.isTranscribing}
      />
      <Pressable
        testID="send-button"
        style={[styles.sendButton, (disabled || !text.trim()) && styles.sendDisabled]}
        onPress={handleSend}
        disabled={disabled || !text.trim()}
        accessibilityLabel="Send message"
      >
        <Text style={styles.sendIcon}>➤</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    padding: spacing.sm,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.surface,
  },
  input: {
    flex: 1,
    marginHorizontal: spacing.sm,
    backgroundColor: colors.surfaceElevated,
    color: colors.textPrimary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    fontSize: fontSize.md,
    maxHeight: 100,
  },
  sendButton: {
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendDisabled: { opacity: 0.4 },
  sendIcon: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
});
```

- [ ] **Step 4: Re-run MessageInput tests (they should still pass)**

```bash
cd mobile && npx jest src/components/chat/__tests__/MessageInput
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add mobile/src/hooks/useVoiceRecorder.ts mobile/src/components/chat
git commit -m "feat(mobile): add voice recording with expo-av and Whisper transcription"
```

---

## Task 17: Error Handling and Session Expiry

**Files:**
- Modify: `mobile/src/api/index.ts` (add 401 handler + session event)
- Modify: `mobile/src/navigation/AppNavigator.tsx` (listen for session-expired)

- [ ] **Step 1: Add session-expired event bus**

Edit `mobile/src/api/index.ts`. Add these exports and update `request` to emit on 401:

```ts
// Add near top of mobile/src/api/index.ts (below TOKEN_KEY constant)
type SessionListener = () => void;
const sessionListeners = new Set<SessionListener>();

export function onSessionExpired(listener: SessionListener): () => void {
  sessionListeners.add(listener);
  return () => sessionListeners.delete(listener);
}

function notifySessionExpired() {
  sessionListeners.forEach((fn) => fn());
}
```

Update the error handling inside `request`:

```ts
if (!response.ok) {
  if (response.status === 401 && init.auth) {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
    notifySessionExpired();
  }
  const message =
    body?.message ??
    body?.errors?.email?.[0] ??
    body?.error ??
    `Request failed: ${response.status}`;
  throw new Error(message);
}
```

- [ ] **Step 2: Listen for session expiry in AppNavigator**

Edit `mobile/src/navigation/AppNavigator.tsx`. Add useEffect to subscribe:

```tsx
// Inside AppNavigator, after the existing useEffect:
import { onSessionExpired } from '../api';

useEffect(() => {
  return onSessionExpired(() => {
    setUser(null);
  });
}, []);
```

- [ ] **Step 3: Verify TypeScript**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add mobile/src/api/index.ts mobile/src/navigation/AppNavigator.tsx
git commit -m "feat(mobile): handle 401 responses with auto-logout and session-expired event"
```

---

## Task 18: Manual Smoke Test + Documentation

**Files:**
- Modify: `mobile/README.md`

- [ ] **Step 1: Update README with usage**

Replace contents of `mobile/README.md`:

```markdown
# WellnessAI Mobile (Expo)

React Native (Expo) app for the WellnessAI wellness vertical.

## Prerequisites

- Node 20+
- Expo CLI: `npm install -g expo-cli`
- iOS Simulator (macOS) or Android emulator
- Set `EXPO_PUBLIC_API_URL` in `.env` (see `.env.example`)

## Run

```bash
cd mobile
npm install
npm start           # Expo dev server
npm run ios         # iOS simulator
npm run android     # Android emulator
npm test            # Jest + Testing Library
```

## Architecture

- **Navigation:** React Navigation native stack (`src/navigation/AppNavigator.tsx`)
- **State:** React Query for server state, local state for UI
- **API:** Sanctum bearer token via `src/api/index.ts`
- **Auth:** expo-secure-store for token persistence
- **Streaming:** react-native-sse with synchronous POST fallback
- **Voice:** expo-av recording + backend Whisper transcription

## Features

- Sign in with email/password (Sanctum)
- Conversation list with pull-to-refresh
- Avatar picker (6 wellness avatars)
- Chat with streaming responses
- Text + voice input
- Citation badges showing verification status
- Graceful fallback when backend SSE endpoint unavailable

## Backend endpoints consumed

- `POST /api/v1/auth/login` — sign in
- `GET  /api/v1/me` — current user
- `POST /api/v1/auth/logout` — sign out
- `GET  /api/v1/agents?vertical=wellness` — avatar catalog
- `GET  /api/v1/conversations` — conversation list
- `POST /api/v1/conversations` — create conversation
- `GET  /api/v1/conversations/{id}/messages` — message history
- `POST /api/v1/conversations/{id}/messages` — send message (auto_reply)
- `GET  /api/v1/conversations/{id}/stream?message_id={id}` — SSE stream (optional)
- `POST /api/v1/transcribe` — audio → transcript (optional)

Endpoints marked *(optional)* gracefully degrade when unavailable.
```

- [ ] **Step 2: Run full test suite**

```bash
cd mobile && npx jest
```

Expected: all tests pass.

- [ ] **Step 3: Run type checker**

```bash
cd mobile && npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Manual smoke test checklist**

Run `npx expo start` and verify on a simulator:

- Sign in with valid credentials → navigates to conversation list
- Tap "+" → avatar picker opens → pick Nora → conversation created → chat detail opens
- Type "hello" → tap send → typing indicator appears → agent response arrives
- Navigate back → conversation appears in list
- Tap conversation → messages reload
- Invalid credentials → alert shown
- Simulate network offline → error message + retry button

- [ ] **Step 5: Commit**

```bash
git add mobile/README.md
git commit -m "docs(mobile): update README with architecture, features, and backend endpoints"
```

---

## Success Criteria

- [ ] User can sign in and land on conversation list
- [ ] User can create a new chat by picking an avatar
- [ ] User can send text messages and receive agent responses
- [ ] Streaming responses render token-by-token when backend supports SSE; falls back to synchronous POST otherwise
- [ ] User can record voice, see transcription prefilled, edit, and send
- [ ] Citation badges appear on verified agent messages
- [ ] Fallback badge appears when verification fails
- [ ] Multiple conversations persist across sessions
- [ ] Pull-to-refresh on conversation list works
- [ ] 401 responses log the user out and return to sign-in
- [ ] All Jest tests pass
- [ ] No TypeScript errors
- [ ] Manual smoke test passes on iOS simulator and Android emulator

---

## Out of Scope (Phase 2+)

- Image upload (food, skin, lab reports)
- Voice output / TTS playback
- Avatar video (HeyGen streaming)
- Citation list modal (only badge count in Phase 1)
- Wearables integration
- Push notifications
- Onboarding flow
- Conversation search and filters
