# mobile

Expo (React Native) shell for the WellnessAI vertical. Phase 0 scope is a
single authenticated round-trip against the Laravel backend — no chat, no
onboarding, no avatars, no voice.

## Requirements

- Node 22+
- Expo Go app on your Android/iOS device (or an Android emulator)

## Run locally against production backend

```bash
cd mobile
cp .env.example .env
npm install
npm start
```

Scan the QR code with Expo Go. Sign in with a user that exists in the
Laravel database — Phase 0's seeder creates `test@example.com` /
`password` on the local/test environment. For production, create a user
via Laravel tinker:

```bash
php artisan tinker
> \App\Models\User::factory()->create(['email' => 'you@example.com', 'password' => bcrypt('your-pass')]);
```

The app calls `POST /api/v1/auth/login`, stores the Sanctum token via
`expo-secure-store`, and renders the result of `GET /api/v1/me`.

## Configuration

- `EXPO_PUBLIC_API_URL` — Laravel base URL, e.g. `https://avatars.hotel-tech.ai`
- EAS project id lives in `app.json` under `expo.extra.eas.projectId`

## Build

```bash
npx eas-cli build --platform android --profile preview
```

## Design notes

See [`../docs/adr/2026-04-20-phase-0-mobile-expo.md`](../docs/adr/2026-04-20-phase-0-mobile-expo.md).
