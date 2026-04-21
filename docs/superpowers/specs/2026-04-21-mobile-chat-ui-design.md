# Mobile Chat UI Design

**Goal:** Build a React Native chat interface in the WellnessAI Expo app that lets users converse with wellness avatars (Nora, Luna, Zen, Dr. Integra, Axel, Aura) via text and voice, with streaming responses, citation indicators, and multi-conversation history.

**Architecture:** React Navigation stack (list → chat detail). React Query for server state. expo-av for voice recording. SSE streaming for agent responses. Sanctum bearer token (existing). Components split by responsibility (screens, chat components, hooks, API).

**Tech Stack:** React Native + Expo, TypeScript, React Navigation v6, @tanstack/react-query, expo-av, expo-secure-store (existing), EventSource polyfill for SSE.

---

## 1. Navigation Architecture

**Root navigator** — switches between auth and app stacks based on token state.

```
AppNavigator (Root)
├── AuthStack (when not authenticated)
│   └── SignInScreen (existing App.tsx, extracted)
└── AppStack (when authenticated)
    ├── ConversationListScreen (home)
    ├── AvatarPickerModal (modal presentation)
    └── ChatDetailScreen (stack push)
```

**Flow:**
1. Login → ConversationListScreen (list of past chats)
2. Tap "New Chat" → AvatarPickerModal (choose avatar)
3. Select avatar → POST /conversations → navigate to ChatDetailScreen
4. Tap existing conversation → ChatDetailScreen (load messages)
5. Back button → ConversationListScreen (state preserved)

**Constraint:** One avatar per conversation (locked at creation). Users can have multiple concurrent conversations with the same avatar.

---

## 2. Component Structure

```
mobile/src/
├── navigation/
│   └── AppNavigator.tsx              # Root stack + auth switcher
├── screens/
│   ├── SignInScreen.tsx              # Extracted from App.tsx
│   ├── ConversationListScreen.tsx    # FlatList of conversations
│   ├── ChatDetailScreen.tsx          # Message thread + input
│   └── AvatarPickerModal.tsx         # Grid of avatar cards
├── components/
│   ├── chat/
│   │   ├── MessageBubble.tsx         # User or agent message
│   │   ├── MessageInput.tsx          # Text field + send + voice
│   │   ├── TypingIndicator.tsx       # "Nora is thinking..."
│   │   ├── CitationBadge.tsx         # "📎 5 sources"
│   │   ├── VoiceRecordButton.tsx     # Hold-to-record
│   │   └── StreamingMessage.tsx      # Animated streaming text
│   ├── conversations/
│   │   ├── ConversationCard.tsx      # List item
│   │   └── EmptyState.tsx            # "No conversations yet"
│   └── avatars/
│       ├── AvatarPickerCard.tsx      # Pickable avatar tile
│       └── AvatarHeader.tsx          # Top bar in chat screen
├── hooks/
│   ├── useConversations.ts           # List + create via React Query
│   ├── useMessages.ts                # Fetch messages for conversation
│   ├── useChatStream.ts              # Send + stream SSE response
│   ├── useVoiceRecorder.ts           # expo-av record + transcribe
│   └── useAvatars.ts                 # Fetch available avatars
├── api/
│   ├── index.ts                      # existing (auth + request wrapper)
│   ├── conversations.ts              # list, create, getById
│   ├── messages.ts                   # list, send, stream
│   ├── transcribe.ts                 # upload audio → transcript
│   └── avatars.ts                    # list
├── types/
│   └── models.ts                     # TypeScript DTOs
└── theme/
    └── index.ts                      # Color palette, spacing
```

**Responsibilities:**
- Screens: orchestrate components, consume hooks, handle navigation
- Components: presentational only, accept props, no data fetching
- Hooks: own side effects (API, recording, streaming)
- API: raw HTTP — no caching, no business logic
- Types: shared DTOs matching backend responses

---

## 3. Data Flow

### Sending a text message

```
User types in MessageInput → local state
User taps Send
  → useChatStream.sendMessage(conversationId, text)
    → POST /api/v1/conversations/{id}/messages
       body: { content: text, auto_reply: true }
    → response: { user_message, agent_message_id_placeholder }
    → append user_message to messages list immediately (optimistic)
    → open SSE: GET /api/v1/conversations/{id}/stream?message_id={placeholder}
    → events:
        "token": append content chunk to streaming message buffer
        "done": finalize message with metadata (is_verified, citations_count)
        "error": show error, clear streaming buffer
    → close SSE
```

### Sending a voice message

```
User presses-and-holds VoiceRecordButton
  → useVoiceRecorder.start() — expo-av Audio.Recording
User releases
  → useVoiceRecorder.stop() → audio file URI
  → POST /api/v1/transcribe with audio file (multipart)
  → returns { transcript: "..." }
  → prefill MessageInput with transcript
  → user reviews → taps Send (same flow as text)
```

### Loading conversations

```
ConversationListScreen mounts
  → useConversations() — React Query
    → GET /api/v1/conversations?vertical=wellness
    → returns paginated list with avatar + last_message + updated_at
    → cached 30s, invalidated on new message or pull-to-refresh
```

### Loading chat history

```
ChatDetailScreen mounts with conversationId
  → useMessages(conversationId)
    → GET /api/v1/conversations/{id}/messages?limit=50
    → returns messages ordered by created_at ASC
    → rendered in FlatList with inverted scroll (newest at bottom)
```

---

## 4. State Management

**Server state: React Query (@tanstack/react-query)**
- `['conversations']` — list query, 30s stale time
- `['conversations', id, 'messages']` — per-conversation messages
- `['avatars']` — avatar catalog, infinite stale (rarely changes)

**UI state: useState / useReducer (no global store)**
- MessageInput text: local to component
- Streaming buffer: local to ChatDetailScreen (resets on conversation change)
- Voice recording state: local to useVoiceRecorder hook
- Modal visibility: parent screen state

**Auth state: single context + secure store (existing)**
- AuthContext provides `user`, `signIn`, `signOut`
- Token persisted via expo-secure-store (existing pattern)

**Why no Redux/Zustand:** React Query handles 80% of state (server data). UI state is local and doesn't need to be shared across screens.

---

## 5. Voice Input Flow

**Library:** expo-av (already in Expo SDK)

**Flow:**
1. User presses VoiceRecordButton — haptic feedback, start recording
2. Red pulsing indicator while recording
3. User releases — stop recording, get audio URI
4. POST audio to `/api/v1/transcribe` (Whisper STT on backend)
5. Response: `{ transcript: "...", latency_ms: N }`
6. Prefill MessageInput with transcript
7. User can edit transcript before sending
8. Send via normal text flow

**Permissions:**
- Request mic permission on first press via `Audio.requestPermissionsAsync()`
- If denied: show alert with deep link to settings
- Re-check on subsequent presses

**Recording config:**
- Format: .m4a (iOS) / .webm (Android), matches Whisper acceptable formats
- Max duration: 60 seconds (hard cap to prevent runaway)
- Visual countdown shown after 45s

---

## 6. Streaming Response Handling

**Technology:** Server-Sent Events (SSE) via `react-native-sse` or EventSource polyfill.

**Why SSE over WebSocket:** Unidirectional (server→client) matches use case. Simpler connection, auto-reconnect, built-in browser/polyfill support. Lower overhead than WebSocket for one-way data.

**Stream event schema (from backend):**
```typescript
type StreamEvent =
  | { type: 'token'; content: string }
  | { type: 'done'; message_id: number; is_verified: boolean | null; citations_count: number; verification_latency_ms: number | null }
  | { type: 'error'; message: string }
```

**Streaming UI behavior:**
- TypingIndicator shown until first `token` event
- StreamingMessage component renders tokens as they arrive (simple string append)
- No typewriter animation — just append (users see chunks naturally)
- On `done` event: replace streaming message with finalized version (includes CitationBadge if citations_count > 0)
- On `error` event: show error state in message bubble, offer retry

**Revision loop visibility:** Hidden from user. If backend runs revisions (verification failures), user just sees the final response. Total latency may be higher but message appears "once" from user's perspective.

**Connection lifecycle:**
- Open SSE on send
- Close on `done` or `error`
- Auto-close on unmount / navigation away
- Timeout: 60s (abort + show error)

---

## 7. Citations Display

**Format:** Small badge indicator below each agent message.

```
[Agent message bubble]
📎 5 sources · Verified ✓
```

- Tap badge → bottom sheet listing sources with titles + URLs (Phase 2 — for now just shows count)
- `is_verified === true` → "Verified ✓" badge (green)
- `is_verified === false` → "Fallback response" badge (amber) — meaning verification failed, showing professional referral
- `is_verified === null` → no badge (hotel vertical, not applicable)

**Phase 1 scope:** Badge is display-only. Tapping shows a toast with count. Full citation list modal is Phase 2.

---

## 8. Error Handling

| Scenario | Handling | User-facing behavior |
|---|---|---|
| Network offline (send) | Catch, retry button in message bubble | Red message bubble, "Tap to retry" |
| SSE connection fails | Timeout 60s, abort, show error | "Could not reach avatar. Tap to retry." |
| 401 Unauthorized | Clear token, return to SignIn | Alert "Session expired" + auto-logout |
| 429 Rate limit | Show quota message | "You've hit your message limit for today. Upgrade or wait." |
| 500 Backend error | Generic error bubble, log to Sentry | "Something went wrong. Please try again." |
| Transcription fails | Keep recording button active, alert | "Couldn't transcribe audio. Try again." |
| Mic permission denied | Alert + link to settings | "Mic access needed for voice messages" |

**Global error boundary:** wraps AppStack, catches render errors, shows fallback screen with reload button.

---

## 9. Theme & Styling

**Reuse existing palette** (from current App.tsx):
- Background: `#0b0f17` (deep space)
- Surface: `#141a26` (card)
- Input bg: `#1f2937` (elevated)
- Primary: `#7c5cff` (purple)
- Text primary: `#ffffff`
- Text secondary: `#d1d5db`
- Text muted: `#9ca3af`
- Border: `#374151`

**Extract to** `src/theme/index.ts` so all components consume from one source.

**Avatar colors (per-avatar accent):** Each avatar has a distinctive accent color used in their messages/badges:
- Nora (nutrition): green `#4ade80`
- Luna (sleep): indigo `#818cf8`
- Zen (mindfulness): teal `#2dd4bf`
- Dr. Integra (functional medicine): blue `#3b82f6`
- Axel (fitness): red `#f87171`
- Aura (beauty): pink `#f472b6`

Accent used for: avatar header, message bubble border, citation badge tint.

---

## 10. Backend API Requirements

**Endpoints used:**

| Method | Endpoint | Purpose | Status |
|---|---|---|---|
| GET | `/api/v1/avatars?vertical=wellness` | List avatars | Exists (verify) |
| GET | `/api/v1/conversations` | List user's conversations | Exists |
| POST | `/api/v1/conversations` | Create new conversation (body: agent_id, title) | Exists |
| GET | `/api/v1/conversations/{id}/messages` | Load message history | Exists |
| POST | `/api/v1/conversations/{id}/messages` | Send message + trigger generation | Exists |
| GET | `/api/v1/conversations/{id}/stream?message_id={id}` | SSE streaming response | **New — needs backend work** |
| POST | `/api/v1/transcribe` | Upload audio → transcript (Whisper) | **New — needs backend work** |

**Backend tasks (separate sub-project if needed):**
- Add SSE streaming endpoint that wraps GenerationService
- Add /transcribe endpoint using OpenAI Whisper
- Ensure avatars endpoint returns avatar metadata (slug, name, accent_color, avatar_image_url)

**This spec assumes these endpoints will be added.** If not available yet, mobile will use synchronous (non-streaming) POST /messages + show loading → final response (graceful degradation).

---

## 11. Testing Strategy

**Unit tests:** `mobile/src/**/__tests__/`
- Hooks: test state transitions, API mocking with jest
- Components: React Testing Library for interaction/rendering
- Utilities: pure function tests

**Integration tests:**
- Mock API responses via MSW (Mock Service Worker)
- Test full flow: sign in → list → chat → send → receive

**Manual testing checklist:**
- iOS simulator: sign in, send text, receive streaming response
- Android emulator: voice recording, transcription, send
- Offline: verify retry behavior
- Rate limit: mock 429 response, verify UI
- Session expired: mock 401, verify redirect to sign-in

**Accessibility:**
- All interactive elements have accessibilityLabel
- Voice record button: accessible alternative (keyboard shortcut when supported)
- Screen reader tested for message bubbles
- Contrast ratios WCAG AA compliant

---

## 12. Out of Scope (Phase 2+)

- Image upload (food photos, skin, lab reports) — Phase 2
- Wearables connection UI — Phase 2
- Voice output (TTS playback) — Phase 2
- Avatar video (HeyGen streaming) — Phase 2
- Full citation list modal — Phase 2
- Conversation search / filter — Phase 2
- Multi-avatar in single conversation — Phase 2
- Push notifications — Phase 3
- Onboarding flow — Phase 3

---

## Success Criteria

- User can sign in, see conversation list, start new chat with any wellness avatar
- User can send text messages and receive streaming agent responses
- User can record voice, see transcription, edit, and send
- Citations badge shown on verified agent messages
- Verification status visible (verified/fallback)
- Multiple conversations persist and can be resumed
- Network errors handled gracefully with retry
- Works on iOS 15+ and Android 10+ (matching Expo SDK 51+ targets)
- No crashes in 1-hour manual test session across all screens

---

## Open Questions / Deferred

- **SSE polyfill choice:** `react-native-sse` vs EventSource polyfill — decide during implementation after prototyping
- **Avatar images:** Source from backend vs bundled in app — backend recommended for launch flexibility
- **Message pagination:** If conversations grow long, implement infinite scroll — Phase 1 MVP limits to 50 most recent
- **Backend streaming endpoint:** If not ready, fall back to synchronous POST /messages showing full response after latency
