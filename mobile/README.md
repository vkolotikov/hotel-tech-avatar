# WellnessAI Mobile (Expo)

React Native (Expo) app for the WellnessAI wellness vertical.

## Prerequisites

- Node 22+
- Expo CLI (`npm install -g expo-cli`) or Expo Go app on device
- iOS Simulator (macOS) or Android emulator
- Set `EXPO_PUBLIC_API_URL` in `.env` (copy from `.env.example`)

## Run

```bash
cd mobile
cp .env.example .env
npm install
npm start           # Expo dev server
npm run ios         # iOS simulator
npm run android     # Android emulator
npm test            # Jest + Testing Library
```

Sign in with a user in the Laravel database. Phase 0's seeder creates
`test@example.com` / `password` on local/test environments. For production,
create a user via tinker:

```bash
php artisan tinker
> \App\Models\User::factory()->create(['email' => 'you@example.com', 'password' => bcrypt('your-pass')]);
```

## Architecture

- **Navigation:** React Navigation native stack (`src/navigation/AppNavigator.tsx`)
- **State:** React Query for server state, local state for UI
- **API:** Sanctum bearer token via `src/api/index.ts`; emits `onSessionExpired` on 401
- **Auth:** expo-secure-store for token persistence
- **Streaming:** react-native-sse with synchronous POST fallback
- **Voice:** expo-av recording + backend Whisper transcription

## Features

- Sign in with email/password (Sanctum)
- Conversation list with pull-to-refresh
- Avatar picker (6 wellness avatars)
- Chat with streaming responses (graceful POST fallback)
- Text + voice input (press-and-hold to record)
- Citation badges showing verification status
- Auto-logout on expired session

## Backend endpoints consumed

- `POST /api/v1/auth/login` — sign in
- `GET  /api/v1/me` — current user
- `POST /api/v1/auth/logout` — sign out
- `GET  /api/v1/agents?vertical=wellness` — avatar catalog
- `GET  /api/v1/conversations` — conversation list
- `POST /api/v1/conversations` — create conversation
- `GET  /api/v1/conversations/{id}/messages` — message history
- `POST /api/v1/conversations/{id}/messages` — send message (`auto_reply`)
- `GET  /api/v1/conversations/{id}/stream?message_id={id}` — SSE stream *(optional)*
- `POST /api/v1/transcribe` — audio → transcript *(optional)*

Endpoints marked *(optional)* gracefully degrade when unavailable.

## Configuration

- `EXPO_PUBLIC_API_URL` — Laravel base URL, e.g. `https://avatars.hotel-tech.ai`
- EAS project id lives in `app.json` under `expo.extra.eas.projectId`

## Manual smoke test checklist

Run `npx expo start` and verify on a simulator:

- [ ] Sign in with valid credentials → navigates to conversation list
- [ ] Tap "+" → avatar picker opens → pick Nora → conversation created → chat detail opens
- [ ] Type "hello" → tap send → typing indicator → agent response arrives
- [ ] Navigate back → conversation appears in list
- [ ] Tap conversation → messages reload
- [ ] Hold record button → release → transcript prefills input
- [ ] Invalid credentials → error shown
- [ ] Server returns 401 → app returns to sign-in automatically

## Build

```bash
npx eas-cli build --platform android --profile preview
```

## Design notes

See [`../docs/adr/2026-04-20-phase-0-mobile-expo.md`](../docs/adr/2026-04-20-phase-0-mobile-expo.md)
and the implementation plan at
[`../docs/superpowers/plans/2026-04-21-mobile-chat-ui.md`](../docs/superpowers/plans/2026-04-21-mobile-chat-ui.md).
